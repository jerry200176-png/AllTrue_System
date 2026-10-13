#!/usr/bin/env node
// Design style ratchet (UI/UX U1, Refs #3115): per-file counts may only go DOWN.
//
// Sibling of the raw-hex guard (scripts/check-no-raw-hex.sh). It counts, per
// file under frontend/src (.vue/.js/.ts/.css, tests and generated files
// excluded), five categories of off-system styling:
//   font_size     `font-size:` values that do not use var(--ds-font-size-*)
//   border_radius `border-*radius:` values that do not use var(--ds-radius-*)
//   box_shadow    `box-shadow:` values that do not use var(--ds-shadow-*)
//                 (focus rings built on var(--ds-focus-ring) are allowed)
//   emoji         emoji / pictograph glyphs inside a Vue <template> block
//   native_dialog alert( / confirm( / window.alert( / window.confirm( calls
//
// Usage (from repo root):
//   node scripts/design-style-ratchet.mjs                    check vs baseline
//   node scripts/design-style-ratchet.mjs --update-baseline  rewrite baseline
//   node scripts/design-style-ratchet.mjs --json             print current counts
// CI: chained at the end of scripts/check-no-raw-hex.sh (npm run lint:design).
//
// Exit 1 only when some file's count in some category is ABOVE its baseline.

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
    if (!usesDesignVar(value)) count += 1;
  }
  return count;
}

export function countFontSize(source) {
  return countDeclarations(source, 'font-size', (v) => /var\(--ds-font-size-/.test(v));
}

export function countBorderRadius(source) {
  return countDeclarations(
    source,
    'border(?:-(?:top|bottom|start|end)-(?:left|right|start|end))?-radius',
    (v) => /var\(--ds-radius-/.test(v),
  );
}

export function countBoxShadow(source) {
  return countDeclarations(
    source,
    'box-shadow',
    (v) => /var\(--ds-shadow-/.test(v) || /var\(--ds-focus-ring\)/.test(v),
  );
}

// Extended_Pictographic plus common dingbat check/cross/star glyphs used as
// icons; (c) (r) TM are typographic, not pictographs.
const PICTOGRAPH = /[\p{Extended_Pictographic}✓✕✗★☆]/gu;
const TYPOGRAPHIC = new Set(['©', '®', '™']);

/** Text of the outermost Vue <template> block (nested templates included). */
export function extractTemplate(source) {
  const start = source.search(/<template[\s>]/);
  const end = source.lastIndexOf('</template>');
  if (start < 0 || end < start) return '';
  return source.slice(start, end);
}

export function countTemplateEmoji(source) {
  const template = extractTemplate(source).replace(/<!--[\s\S]*?-->/g, ' ');
  let count = 0;
  for (const [glyph] of template.matchAll(PICTOGRAPH)) {
    if (!TYPOGRAPHIC.has(glyph)) count += 1;
  }
  return count;
}

export function countNativeDialogs(source) {
  const re = /(?<![\w$.])(?:window\.)?(?:alert|confirm)\s*\(/g;
  let count = 0;
  for (const match of source.matchAll(re)) {
    const before = source.slice(Math.max(0, match.index - 16), match.index);
    if (/function\s+$/.test(before)) continue; // local `function confirm(` definition
    // Method shorthand definition `confirm(args) {` is not a call.
    let depth = 0;
    let i = match.index + match[0].length - 1;
    for (; i < source.length; i += 1) {
      if (source[i] === '(') depth += 1;
      else if (source[i] === ')') {
        depth -= 1;
        if (depth === 0) break;
      }
    }
    if (/^\s*\{/.test(source.slice(i + 1, i + 40))) continue;
    count += 1;
  }
  return count;
}

export function countStyleViolations(source, { isVue = false } = {}) {
  const code = stripVueComments(source);
  return {
    font_size: countFontSize(code),
    border_radius: countBorderRadius(code),
    box_shadow: countBoxShadow(code),
    emoji: isVue ? countTemplateEmoji(source) : 0,
    native_dialog: countNativeDialogs(code),
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
      `  "categories": ${JSON.stringify(CATEGORIES)},`,
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

const invokedPath = process.argv[1] ? pathToFileURL(process.argv[1]).href : '';
if (invokedPath === import.meta.url) {
  process.exitCode = main();
}
