#!/usr/bin/env node
// Guard against "silent dead UI" (root-cause family G2, in-app #371): a child
// emits `navigate` but the parent mounts it without `@navigate`, so a button
// does nothing. Regex/AST-lite: no deps.
//
// Usage: node scripts/ci/check-unhandled-emits.mjs [--file-override <Parent.vue>=<path>]
//   --file-override substitutes a parent's content (used to replay history).
//
// ponytail: static only. Not caught: dynamic <component :is>, components
// registered via `components: {}` without import, object-form defineEmits keys
// not listed here, emits passed through wrapper components.
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

// Navigation/result events: an unhandled emit of these = a dead button or a
// save that never propagates. Other events (e.g. analytics-ish) are optional.
export const WATCHED = ['navigate', 'close', 'saved', 'save', 'done', 'updated', 'submitted'];

const camel = (s) => s.replace(/-(\w)/g, (_, c) => c.toUpperCase());
const kebab = (s) => s.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());

export function collectEmits(src) {
  const out = new Set();
  const arrays = [...src.matchAll(/(?:defineEmits\(|\bemits\s*:\s*)\[([^\]]*)\]/g)];
  for (const m of arrays) for (const s of m[1].matchAll(/['"]([^'"]+)['"]/g)) out.add(camel(s[1]));
  for (const m of src.matchAll(/(?:\$?emit\(\s*|\(e:\s*)['"]([^'"]+)['"]/g)) out.add(camel(m[1]));
  return out;
}

export function collectImports(src, parentPath, srcRoot) {
  const map = new Map(); // component name -> absolute path
  const add = (name, spec) => {
    const p = spec.startsWith('@/') ? join(srcRoot, spec.slice(2)) : resolve(dirname(parentPath), spec);
    map.set(name, p);
  };
  for (const m of src.matchAll(/import\s+(\w+)\s+from\s+['"]([^'"]+\.vue)['"]/g)) add(m[1], m[2]);
  for (const m of src.matchAll(/(\w+)\s*=\s*[\w.]+\(\s*\(\)\s*=>\s*import\(\s*['"]([^'"]+\.vue)['"]/g)) add(m[1], m[2]);
  return map;
}

// Yield {name, text, line} for each `<Name ...>` open tag (quote-aware end).
function* tags(src, name) {
  const re = new RegExp(`<(?:${name}|${kebab(name).replace(/^-/, '')})(?=[\\s/>])`, 'g');
  for (const m of src.matchAll(re)) {
    let i = m.index, q = null;
    for (; i < src.length; i++) {
      const c = src[i];
      if (q) { if (c === q) q = null; } else if (c === '"' || c === "'") q = c; else if (c === '>') break;
    }
    yield { text: src.slice(m.index, i + 1), line: src.slice(0, m.index).split('\n').length };
  }
}

function listensTo(tag, event) {
  if (/v-bind\s*=\s*["']\$attrs["']|v-on\s*=\s*["']\$listeners["']/.test(tag)) return true;
  for (const m of tag.matchAll(/(?:@|v-on:)([\w:-]+)/g)) if (camel(m[1]) === event) return true;
  return false;
}

/** files: Map<absPath, content> of all .vue. Returns [{parent, line, child, event, key}] */
export function findUnhandled(files, srcRoot) {
  const emitsOf = new Map([...files].map(([p, s]) => [p, collectEmits(s)]));
  const res = [];
  for (const [parent, src] of files) {
    const tpl = src.match(/<template[\s\S]*<\/template>/)?.[0] ?? '';
    const script = src.replace(tpl, '');
    for (const [name, path] of collectImports(script, parent, srcRoot)) {
      const events = [...(emitsOf.get(path) ?? [])].filter((e) => WATCHED.includes(e));
      if (!events.length) continue;
      for (const t of tags(src, name)) {
        for (const event of events) {
          if (!listensTo(t.text, event)) {
            res.push({ parent: basename(parent), line: t.line, child: name, event, key: `${basename(parent)}>${name}>${event}` });
          }
        }
      }
    }
  }
  return res;
}

function walk(dir, out = []) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) { if (e.name !== 'node_modules') walk(p, out); } else if (p.endsWith('.vue')) out.push(p);
  }
  return out;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
  const srcRoot = join(root, 'frontend/src');
  const files = new Map(walk(srcRoot).map((p) => [p, readFileSync(p, 'utf8')]));
  const args = process.argv.slice(2);
  const oi = args.indexOf('--file-override');
  if (oi >= 0) {
    const [name, path] = args[oi + 1].split('=');
    for (const p of files.keys()) if (basename(p) === name) files.set(p, readFileSync(path, 'utf8'));
  }
  const baselinePath = join(root, 'scripts/ci/unhandled-emits-baseline.json');
  const baseline = new Set(existsSync(baselinePath) ? JSON.parse(readFileSync(baselinePath, 'utf8')) : []);
  const all = findUnhandled(files, srcRoot);
  if (args.includes('--print-baseline')) { console.log(JSON.stringify([...new Set(all.map((r) => r.key))].sort(), null, 2)); process.exit(0); }
  const bad = all.filter((r) => !baseline.has(r.key));
  for (const r of bad) console.error(`${r.parent}:${r.line} <${r.child}> emits '${r.event}' but no listener (add @${kebab(r.event)}, or baseline "${r.key}" if intentional)`);
  console.log(`unhandled-emits: ${bad.length} violation(s), ${baseline.size} baselined`);
  process.exit(bad.length ? 1 : 0);
}
