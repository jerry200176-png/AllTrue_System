#!/usr/bin/env node
// Guard: production-run Playwright specs never run on PRs (they need a production login), so a
// class or data-testid removed from the app only breaks them after deploy (acceptance run
// 43555468636: `.hint` removed by the 課程查找 restyle). This fails PR CI when a CSS class or
// test id a production-run spec selects no longer appears anywhere under frontend/src.
//
// Scope: frontend/e2e/calendar-course-consistency.spec.js, frontend/e2e/*production*.spec.js and
// frontend/e2e/fixtures/**. Rule: production-run specs select by data-testid (IMPLEMENTER_BRIEF).
// ponytail: static only; a selector built entirely at runtime is not seen, and `${…}` pieces are cut
// (a testid template is checked by its static prefix).
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

// Selectors that come from libraries or the browser, not from frontend/src. Keep each entry commented.
export const LIBRARY_ALLOWLIST = new Set([
  // A class a spec asserts is ABSENT (toHaveCount(0)): retired on purpose, so it is gone from src.
  'btn-status', // fixtures/coursePaymentEntry.js: the retired 繳費 status button must not come back
]);

const walk = (dir, out = []) => {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) {
      if (name === 'node_modules' || name === '__tests__') continue;
      walk(p, out);
    } else out.push(p);
  }
  return out;
};

export function specFiles(root = ROOT) {
  const e2e = join(root, 'frontend', 'e2e');
  return walk(e2e).filter((f) => /calendar-course-consistency\.spec\.js$|production[^/]*\.spec\.js$/.test(f) || f.includes('/e2e/fixtures/'));
}

// Calls whose first string argument is a CSS selector.
const SELECTOR_CALL = /(?:\.locator|\.\$\$?|\.waitForSelector|\.querySelector(?:All)?|\.closest|\.matches|readySelector\s*:|selector\s*:)\s*\(?\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;
const TESTID_CALL = /getByTestId\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;
const TESTID_ATTR = /\[data-testid([\^$*~|]?=)\s*["']?([^"'\]]+)["']?\s*\]/g;

const cut = (s) => s.split('${')[0];

export function extractSelectors(source) {
  const classes = new Set();
  const testids = []; // { id, exact }
  for (const m of source.matchAll(SELECTOR_CALL)) {
    const sel = m[2];
    for (const t of sel.matchAll(TESTID_ATTR)) testids.push({ id: cut(t[2]), exact: t[1] === '=' && !t[2].includes('${') });
    const rest = sel.replace(TESTID_ATTR, '').replace(/\[[^\]]*\]/g, '').replace(/\$\{[^}]*\}/g, ' ');
    for (const c of rest.matchAll(/\.([A-Za-z_][\w-]*)/g)) classes.add(c[1]);
  }
  for (const m of source.matchAll(TESTID_CALL)) testids.push({ id: cut(m[2]), exact: !m[2].includes('${') });
  return { classes, testids: testids.filter((t) => t.id) };
}

const token = (name) => new RegExp(`(?<![\\w-])${name.replace(/[-]/g, '\\-')}(?![\\w-])`);

// `data-testid="tc-reminder-${key}"` in src: static prefixes of template test ids.
const templatePrefixes = (srcText) => [...srcText.matchAll(/testid[^\n`]*?`([\w-]+)\$\{/gi)].map((m) => m[1]);

export function findStale(files, srcText, allow = LIBRARY_ALLOWLIST) {
  const problems = [];
  const prefixes = templatePrefixes(srcText);
  for (const f of files) {
    const { classes, testids } = extractSelectors(readFileSync(f, 'utf8'));
    for (const c of classes) if (!allow.has(c) && !token(c).test(srcText)) problems.push(`${f}: class .${c} not found under frontend/src`);
    for (const t of testids) {
      if (allow.has(t.id)) continue;
      const ok = (t.exact ? token(t.id).test(srcText) : srcText.includes(t.id)) || prefixes.some((p) => t.id.startsWith(p));
      if (!ok) problems.push(`${f}: data-testid "${t.id}"${t.exact ? '' : ' (prefix)'} not found under frontend/src`);
    }
  }
  return problems;
}

export function readSrc(root = ROOT) {
  return walk(join(root, 'frontend', 'src')).filter((f) => !/\.test\.[cm]?js$/.test(f)).map((f) => readFileSync(f, 'utf8')).join('\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const files = specFiles();
  const problems = findStale(files, readSrc());
  if (problems.length) {
    console.error('Production-run spec selectors that no longer exist in the app:');
    for (const p of problems) console.error(`  ${p.replace(`${ROOT}/`, '')}`);
    console.error('Select by data-testid and keep it in the page, or add a commented library entry to LIBRARY_ALLOWLIST.');
    process.exit(1);
  }
  console.log(`check-production-spec-selectors: ${files.length} spec files OK`);
}
