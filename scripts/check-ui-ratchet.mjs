#!/usr/bin/env node
/**
 * UI ratchet (#4989): a .vue file may import design-system/At* only if it is listed in
 * scripts/ui-legacy-pages.json. Deterministic, so it runs in `npm run build`.
 * Also (#4989 overlays): counts of `class="modal`, `window.confirm`, `prompt(`, `alert(` and raw `<Teleport` in pages/ and components/
 * may not exceed scripts/ui-overlay-limits.json; the limits may only shrink.
 * `--vs-base <ref>` (presubmit only) also requires the list not to grow versus that ref; it fails closed
 * if the ref cannot be resolved. A ref that lacks the file (the introducing PR) skips the grow check; a file that exists but is unreadable or invalid JSON fails closed.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LIST = 'scripts/ui-legacy-pages.json';
const LIMITS = 'scripts/ui-overlay-limits.json';
export const OVERLAY_PATTERNS = {
  modal: /class="modal/g,
  confirm: /(?<![\w.$])(?<!function )(?:window\.)?confirm\(/g,
  prompt: /(?<![\w.$])(?:window\.)?prompt\(/g,
  alert: /(?<![\w.$])(?:window\.)?alert\(/g,
  teleport: /<Teleport\b/g,
};
const OVERLAY_DIR = /^src\/(?:pages|components)\//;
const OVERLAY_SKIP = /(?:^|\/)(?:__tests__|ui|app-overlay)\/|\.(?:test|spec)\./;
const AT_IMPORT = /design-system\/At[A-Z]/;

export function walkVue(dir, out = [], exts = ['.vue']) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walkVue(p, out, exts);
    else if (exts.some((x) => e.name.endsWith(x))) out.push(p);
  }
  return out;
}

export function countOverlays(sources) {
  const counts = Object.fromEntries(Object.keys(OVERLAY_PATTERNS).map((k) => [k, 0]));
  for (const [file, source] of Object.entries(sources)) {
    if (!OVERLAY_DIR.test(file) || OVERLAY_SKIP.test(file)) continue;
    for (const [k, re] of Object.entries(OVERLAY_PATTERNS)) counts[k] += (source.match(re) || []).length;
  }
  return counts;
}

export function checkOverlayLimits({ counts, limits, baseLimits }) {
  const errors = [];
  const valid = (v) => Number.isInteger(v) && v >= 0;
  for (const k of Object.keys(OVERLAY_PATTERNS)) {
    if (!valid(limits?.[k]) || (baseLimits && !valid(baseLimits[k]))) {
      errors.push(`${LIMITS}: ${k} limit must be a non-negative integer in current and base limits (got ${limits?.[k]} / ${baseLimits?.[k]})`);
      continue;
    }
    if (counts[k] > limits[k]) errors.push(`overlay ratchet: ${k} count ${counts[k]} > limit ${limits[k]}; use components/app-overlay (ConfirmDialog/useConfirm, FormDialog, DetailSheet)`);
    if (baseLimits && limits[k] > baseLimits[k]) errors.push(`${LIMITS}: ${k} limit rose ${baseLimits[k]} -> ${limits[k]}; limits can only shrink`);
  }
  return errors;
}

export function checkRatchet({ files, list, baseList }) {
  const errors = [];
  const allowed = new Set(list);
  for (const [file, source] of Object.entries(files)) {
    if (AT_IMPORT.test(source) && !allowed.has(file)) errors.push(`${file}: imports design-system/At* but is not in ${LIST}; use components/ui`);
  }
  if (baseList) {
    const base = new Set(baseList);
    for (const f of list) if (!base.has(f)) errors.push(`${LIST} gained "${f}"; the list can only shrink`);
  }
  return errors;
}

export function baseJson(ref, file, cwd = ROOT) {
  const git = (...args) => execFileSync('git', args, { cwd, stdio: ['ignore', 'pipe', 'ignore'] });
  try {
    git('rev-parse', '--verify', `${ref}^{commit}`);
  } catch {
    throw new Error(`cannot resolve base ref ${ref}`);
  }
  try {
    git('cat-file', '-e', `${ref}:${file}`);
  } catch {
    return null; // genuinely absent on base (introducing PR)
  }
  try {
    return JSON.parse(git('show', `${ref}:${file}`).toString());
  } catch (e) {
    throw new Error(`cannot read or parse ${file} at ${ref}: ${e.message}`);
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const base = (ref, file) => {
    try {
      return baseJson(ref, file);
    } catch (e) {
      console.error(`ui ratchet: ${e.message} (fail closed)`);
      process.exit(1);
    }
  };
  const i = process.argv.indexOf('--vs-base');
  const ref = i > -1 ? process.argv[i + 1] : null;
  if (i > -1 && !ref) {
    console.error('ui ratchet: --vs-base needs a ref');
    process.exit(1);
  }
  const src = path.join(ROOT, 'frontend/src');
  const files = Object.fromEntries(walkVue(src).map((p) => [path.relative(path.join(ROOT, 'frontend'), p).split(path.sep).join('/'), fs.readFileSync(p, 'utf8')]));
  const errors = checkRatchet({ files, list: JSON.parse(fs.readFileSync(path.join(ROOT, LIST), 'utf8')), baseList: ref ? base(ref, LIST) : null });
  const sources = { ...files };
  for (const p of walkVue(src, [], ['.js'])) sources[path.relative(path.join(ROOT, 'frontend'), p).split(path.sep).join('/')] = fs.readFileSync(p, 'utf8');
  errors.push(...checkOverlayLimits({ counts: countOverlays(sources), limits: JSON.parse(fs.readFileSync(path.join(ROOT, LIMITS), 'utf8')), baseLimits: ref ? base(ref, LIMITS) : null }));
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exit(1);
  }
  console.log('ui ratchet: OK');
}
