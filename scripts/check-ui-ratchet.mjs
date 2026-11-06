#!/usr/bin/env node
/**
 * UI ratchet (#4989): a .vue file may import design-system/At* only if it is listed in
 * scripts/ui-legacy-pages.json. Deterministic, so it runs in `npm run build`.
 * `--vs-base <ref>` (presubmit only) also requires the list not to grow versus that ref; it fails closed
 * if the ref cannot be resolved. A ref that lacks the list file (the introducing PR) skips the grow check.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LIST = 'scripts/ui-legacy-pages.json';
const AT_IMPORT = /design-system\/At[A-Z]/;

export function walkVue(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walkVue(p, out);
    else if (e.name.endsWith('.vue')) out.push(p);
  }
  return out;
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

function baseList(ref) {
  try {
    execFileSync('git', ['rev-parse', '--verify', `${ref}^{commit}`], { cwd: ROOT, stdio: 'ignore' });
  } catch {
    console.error(`ui ratchet: cannot resolve base ref ${ref} (fail closed)`);
    process.exit(1);
  }
  try {
    return JSON.parse(execFileSync('git', ['show', `${ref}:${LIST}`], { cwd: ROOT, stdio: ['ignore', 'pipe', 'ignore'] }).toString());
  } catch {
    return null; // list not on base yet (introducing PR)
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const i = process.argv.indexOf('--vs-base');
  const ref = i > -1 ? process.argv[i + 1] : null;
  if (i > -1 && !ref) {
    console.error('ui ratchet: --vs-base needs a ref');
    process.exit(1);
  }
  const src = path.join(ROOT, 'frontend/src');
  const files = Object.fromEntries(walkVue(src).map((p) => [path.relative(path.join(ROOT, 'frontend'), p).split(path.sep).join('/'), fs.readFileSync(p, 'utf8')]));
  const errors = checkRatchet({ files, list: JSON.parse(fs.readFileSync(path.join(ROOT, LIST), 'utf8')), baseList: ref ? baseList(ref) : null });
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exit(1);
  }
  console.log('ui ratchet: OK');
}
