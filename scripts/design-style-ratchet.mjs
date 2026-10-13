#!/usr/bin/env node
// Design style ratchet (UI U1, Refs #3115), sibling of scripts/check-no-raw-hex.sh.
// Per file under frontend/src counts off-system font-size / border-radius /
// box-shadow values, emoji in Vue <template>, and native alert/confirm calls.
// Rules and categories: docs/design/ALLTRUE_UI_FOUNDATION.md section 11.
// Usage: node scripts/design-style-ratchet.mjs [--update-baseline | --json]
// Exit 1 only when a file's count in a category is ABOVE the baseline.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { stripVueComments } from './design-hex-counter.mjs';

export const CATEGORIES = ['font_size', 'border_radius', 'box_shadow', 'emoji', 'native_dialog'];

// CSS-wide keywords and zero never need a design variable.
const NEUTRAL_VALUE = /^(?:0|0px|none|inherit|initial|unset|revert|revert-layer)$/i;

function countDeclarations(source, propertyPattern, usesDesignVar) {
  const re = new RegExp(`(?<![-\\w])(?:${propertyPattern})\\s*:\\s*([^;}{"\`\\n]+)`, 'gi');
  let count = 0;
  for (const match of source.matchAll(re)) {
    const value = match[1].replace(/!important/i, '').trim().replace(/['",]+$/, '').trim();
    if (!value || NEUTRAL_VALUE.test(value)) continue;
    if (value.startsWith('{{') || value.startsWith('$')) continue; // template/JS interpolation
    if (!usesDesignVar(value.toLowerCase())) count += 1;
  }
  return count;
}

// Split on top-level commas / spaces only, so rgba(a, b) and calc() stay whole.
function splitTop(value, sep) {
  const parts = [];
  let depth = 0;
  let cur = '';
  for (const ch of value) {
    if (ch === '(') depth += 1;
    if (ch === ')') depth -= 1;
    if (depth === 0 && sep.test(ch)) { if (cur.trim()) parts.push(cur.trim()); cur = ''; } else cur += ch;
  }
  if (cur.trim()) parts.push(cur.trim());
  return parts;
}

// The whole value must be the design variable: mixing it with literals
// (calc(var(--ds-font-size-sm) + 10px), `var(--ds-shadow-1), 0 30px red`) counts.
export function countFontSize(source) {
  return countDeclarations(source, 'font-size', (v) => /^var\(--ds-font-size-[\w-]+\)$/.test(v));
}

export function countBorderRadius(source) {
  return countDeclarations(
    source,
    'border(?:-(?:top|bottom|start|end)-(?:left|right|start|end))?-radius',
    (v) => splitTop(v, /\s/).every((p) => p === '0' || /^var\(--ds-radius-[\w-]+\)$/.test(p)),
  );
}

export function countBoxShadow(source) {
  return countDeclarations(
    source,
    'box-shadow',
    (v) => splitTop(v, /,/).every((layer) => /^var\(--ds-shadow-[\w-]+\)$/.test(layer)
      || /^(?:inset\s+)?(?:-?[\d.]+(?:px)?\s+){2,4}var\(--ds-focus-ring\)$/.test(layer)),
  );
}

// Extended_Pictographic, flags, plus dingbat check/cross/star/pencil/arrow glyphs
// used as icons; (c) (r) TM are typographic, not pictographs.
const PICTOGRAPH = /[\p{Extended_Pictographic}\p{Regional_Indicator}✓✕✗★☆✎✏×▲▼◀▶]/u;
const TYPOGRAPHIC = new Set(['©', '®', '™']);

/** [start, end) of the Vue <template> block: last </template> before the next <script>/<style>. */
function templateRange(source) {
  const start = source.search(/<template[\s>]/);
  if (start < 0) return null;
  const next = source.slice(start).search(/<(?:script|style)[\s>]/);
  const end = source.lastIndexOf('</template>', next < 0 ? source.length : start + next);
  return end > start ? [start, end] : null;
}

export function extractTemplate(source) {
  const range = templateRange(source);
  return range ? source.slice(...range) : '';
}

export function countTemplateEmoji(source) {
  const template = extractTemplate(source).replace(/<!--[\s\S]*?-->/g, ' ');
  let count = 0; // one per visible glyph: a ZWJ family or emoji+VS16 counts once
  for (const { segment } of new Intl.Segmenter().segment(template)) {
    if (PICTOGRAPH.test(segment) && !TYPOGRAPHIC.has(segment)) count += 1;
  }
  return count;
}

export function countNativeDialogs(source) {
  const re = /(?<![\w$.])(?:(?:window|globalThis|self)\??\.)?(?:alert|confirm)\s*\(/g;
  let count = 0;
  for (const match of source.matchAll(re)) {
    const before = source.slice(Math.max(0, match.index - 16), match.index);
    if (/function\s+$/.test(before)) continue; // local `function confirm(` definition
    // Method shorthand definition `confirm(args) {` / `confirm(a): void {` is not a call.
    let depth = 0;
    let i = match.index + match[0].length - 1;
    for (; i < source.length; i += 1) {
      if (source[i] === '(') depth += 1;
      else if (source[i] === ')') {
        depth -= 1;
        if (depth === 0) break;
      }
    }
    if (/^\s*(?::[^{;=]*)?\{/.test(source.slice(i + 1, i + 60))) continue;
    count += 1;
  }
  return count;
}

export function countStyleViolations(source, { isVue = false } = {}) {
  const code = stripVueComments(source);
  // Vue templates cannot reach window.alert/confirm; a bare `confirm(x)` there is a component method.
  const range = isVue ? templateRange(code) : null;
  const scriptCode = range ? code.slice(0, range[0]) + code.slice(range[1]) : code;
  return {
    font_size: countFontSize(code),
    border_radius: countBorderRadius(code),
    box_shadow: countBoxShadow(code),
    emoji: isVue ? countTemplateEmoji(source) : 0,
    native_dialog: countNativeDialogs(scriptCode),
  };
}

const SCANNED_EXT = /\.(?:vue|js|mjs|ts|css)$/;
const EXCLUDED = /(?:^|\/)__tests__\/|\.(?:test|spec)\.[cm]?[jt]s$|\.generated\.js$/;

function walk(dir, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (SCANNED_EXT.test(entry.name)) out.push(full);
  }
  return out;
}

export function collectCounts(repoRoot) {
  const srcDir = path.join(repoRoot, 'frontend', 'src');
  const files = {};
  const totals = Object.fromEntries(CATEGORIES.map((c) => [c, 0]));
  for (const file of walk(srcDir, []).sort()) {
    const rel = path.relative(repoRoot, file).split(path.sep).join('/');
    if (EXCLUDED.test(rel)) continue;
    const counts = countStyleViolations(fs.readFileSync(file, 'utf8'), { isVue: file.endsWith('.vue') });
    const nonZero = Object.fromEntries(CATEGORIES.filter((c) => counts[c] > 0).map((c) => [c, counts[c]]));
    if (Object.keys(nonZero).length === 0) continue;
    files[rel] = nonZero;
    for (const c of CATEGORIES) totals[c] += counts[c];
  }
  return { totals, files };
}

/** Compare current counts with a baseline; returns increases and decreases. */
export function compareToBaseline(baseline, current) {
  const increases = [];
  const decreases = [];
  const baseFiles = baseline.files || {};
  const paths = new Set([...Object.keys(baseFiles), ...Object.keys(current.files)]);
  for (const file of [...paths].sort()) {
    for (const c of CATEGORIES) {
      const b = baseFiles[file]?.[c] ?? 0;
      const n = current.files[file]?.[c] ?? 0;
      if (n > b) increases.push({ file, category: c, base: b, current: n });
      else if (n < b) decreases.push({ file, category: c, base: b, current: n });
    }
  }
  return { increases, decreases };
}

function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const baselinePath = path.join(repoRoot, 'docs', 'design-style-baseline.json');
  const args = new Set(process.argv.slice(2));
  const current = collectCounts(repoRoot);

  if (args.has('--json')) {
    process.stdout.write(`${JSON.stringify(current, null, 2)}\n`);
    return 0;
  }
  if (args.has('--update-baseline')) {
    // One line per file keeps the baseline diff small and reviewable.
    const fileLines = Object.entries(current.files).map(([f, c]) => `    ${JSON.stringify(f)}: ${JSON.stringify(c)}`);
    const doc = [
      '{',
      '  "_comment": "Generated by node scripts/design-style-ratchet.mjs --update-baseline. Per-file counts may only go down.",',
      `  "totals": ${JSON.stringify(current.totals)},`,
      '  "files": {',
      fileLines.join(',\n'),
      '  }',
      '}',
    ].join('\n');
    fs.writeFileSync(baselinePath, `${doc}\n`);
    console.log(`[design-style-ratchet] baseline written: ${JSON.stringify(current.totals)}`);
    return 0;
  }
  if (!fs.existsSync(baselinePath)) {
    console.log(`[design-style-ratchet] missing baseline ${baselinePath}; run with --update-baseline`);
    return 1;
  }
  const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
  const { increases, decreases } = compareToBaseline(baseline, current);
  for (const v of increases) {
    console.log(`  ✗ ${v.file} [${v.category}]: ${v.base} → ${v.current} (+${v.current - v.base})`);
  }
  if (increases.length > 0) {
    console.log('');
    console.log(`[design-style-ratchet] ${increases.length} new off-system style usage(s).`);
    console.log('Fix: font-size → var(--ds-font-size-*), border-radius → var(--ds-radius-*),');
    console.log('box-shadow → var(--ds-shadow-*), emoji → <AtIcon>, alert/confirm → AtDialog / AtInlineAlert / toast.');
    console.log('See docs/design/ALLTRUE_UI_FOUNDATION.md §11.');
    return 1;
  }
  if (decreases.length > 0) {
    console.log(`[design-style-ratchet] ${decreases.length} count(s) dropped below baseline. Lock in the gain with:`);
    console.log('  node scripts/design-style-ratchet.mjs --update-baseline');
  }
  console.log(`[design-style-ratchet] OK: no increase vs baseline. Current totals ${JSON.stringify(current.totals)}`);
  return 0;
}

// realpath: a symlinked checkout must not skip main() and silently exit 0.
const invokedPath = process.argv[1] ? pathToFileURL(fs.realpathSync(process.argv[1])).href : '';
if (invokedPath === import.meta.url) {
  process.exitCode = main();
}
