#!/usr/bin/env node
// Guard for production-run Playwright specs. They never run on PRs (they need a production login),
// so a selector that stops matching only breaks after deploy (acceptance run 43555468636: the
// 課程查找 restyle removed the `.hint` the spec read).
//
// Two checks, each catching a different thing:
//  1. RATCHET (primary): counts CSS-class selectors in the production-run specs and fails when the
//     count grows against scripts/ci/production-spec-class-selectors.baseline.json. It stops NEW
//     class selectors. `--update-baseline` rewrites the baseline (use it when the count shrinks).
//  2. EXISTENCE (secondary): fails when a class or data-testid a spec selects appears nowhere under
//     frontend/src. It only catches a selector gone from the WHOLE frontend; `.hint` still exists
//     elsewhere, so this check alone would have passed the incident.
// Neither proves a selector still matches on the right page. New production-spec selectors must be
// data-testid or roles (docs/agents/IMPLEMENTER_BRIEF.md).
//
// Scope: frontend/e2e/calendar-course-consistency.spec.js, frontend/e2e/*production*.spec.js and
// frontend/e2e/fixtures/**.
// ponytail: static only; a selector built entirely at runtime is not seen, and `${…}` pieces are cut
// (a testid template is checked by its static prefix).
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

export const BASELINE_PATH = join(ROOT, 'scripts', 'ci', 'production-spec-class-selectors.baseline.json');

// CLASS selectors (never test ids) that are not expected in frontend/src. Keep each entry commented.
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
const SELECTOR_CALL = /(?:\.locator|\.\$\$?(?:eval)?|\.waitForSelector|\.querySelector(?:All)?|\.closest|\.matches|readySelector\s*:|selector\s*:)\s*\(?\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;
const TESTID_CALL = /getByTestId\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;
const TESTID_ATTR = /\[data-testid([\^$*~|]?=)\s*["']?([^"'\]]+)["']?\s*\]/g;

// `[class...=...]` in any operator form; `[class]` alone (presence) is not a class selector.
const CLASS_ATTR = /\[\s*class\s*[~|^$*]?\s*=[^\]]*\]/gi;

const cut = (s) => s.split('${')[0];

export function extractSelectors(source) {
  const classes = new Set();
  let classCount = 0;
  const testids = []; // { id, exact }
  for (const m of source.matchAll(SELECTOR_CALL)) {
    const sel = m[2];
    // XPath strings: each `@class` test (contains(@class...), @class="x") counts as one class selector.
    if (/^\s*(?:xpath=|\/\/|\.\/|\.\.\/|\(\/\/)/.test(sel)) { classCount += (sel.match(/@class\b/g) || []).length; continue; }
    // Attribute selectors on class ([class*=x], [class~=x], [class^=x], [class$=x], [class|=x], [class="x"], any quoting/case flag) are class selectors too.
    classCount += (sel.match(CLASS_ATTR) || []).length;
    for (const t of sel.matchAll(TESTID_ATTR)) testids.push({ id: cut(t[2]), exact: t[1] === '=' && !t[2].includes('${') });
    const rest = sel.replace(TESTID_ATTR, '').replace(/\[[^\]]*\]/g, '').replace(/\$\{[^}]*\}/g, ' ');
    for (const c of rest.matchAll(/\.([A-Za-z_][\w-]*)/g)) { classes.add(c[1]); classCount += 1; }
  }
  for (const m of source.matchAll(TESTID_CALL)) testids.push({ id: cut(m[2]), exact: !m[2].includes('${') });
  return { classes, classCount, testids: testids.filter((t) => t.id) };
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
      const ok = (t.exact ? token(t.id).test(srcText) : srcText.includes(t.id)) || prefixes.some((p) => t.id.startsWith(p));
      if (!ok) problems.push(`${f}: data-testid "${t.id}"${t.exact ? '' : ' (prefix)'} not found under frontend/src`);
    }
  }
  return problems;
}

export function readSrc(root = ROOT) {
  return walk(join(root, 'frontend', 'src')).filter((f) => !/\.test\.[cm]?js$/.test(f)).map((f) => readFileSync(f, 'utf8')).join('\n');
}

export function classCounts(files, root = ROOT) {
  const specs = {};
  let total = 0;
  for (const f of files) {
    const n = extractSelectors(readFileSync(f, 'utf8')).classCount;
    if (n) { specs[f.replace(`${root}/`, '')] = n; total += n; }
  }
  return { total, specs };
}

export function ratchetProblems(current, baseline) {
  const problems = [];
  if (current.total > baseline.total) problems.push(`class selectors in production-run specs grew: ${baseline.total} -> ${current.total}`);
  for (const [f, n] of Object.entries(current.specs)) {
    const was = baseline.specs?.[f] ?? 0;
    if (n > was) problems.push(`${f}: ${was} -> ${n} class selectors (use getByTestId or a role)`);
  }
  return problems;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const files = specFiles();
  const current = classCounts(files);
  if (process.argv.includes('--update-baseline')) {
    writeFileSync(BASELINE_PATH, `${JSON.stringify(current, null, 2)}\n`);
    console.log(`baseline written: ${current.total} class selectors`);
    process.exit(0);
  }
  const baseline = JSON.parse(readFileSync(BASELINE_PATH, 'utf8'));
  const problems = [...ratchetProblems(current, baseline), ...findStale(files, readSrc())];
  if (problems.length) {
    console.error('Production-run spec selector problems:');
    for (const p of problems) console.error(`  ${p.replace(`${ROOT}/`, '')}`);
    console.error('Select by data-testid or role. Selectors gone from frontend/src must be fixed, not allowlisted.');
    process.exit(1);
  }
  if (current.total < baseline.total) {
    console.log(`class selectors shrank (${baseline.total} -> ${current.total}): lock it in with  node scripts/ci/check-production-spec-selectors.mjs --update-baseline`);
  }
  console.log(`check-production-spec-selectors: ${files.length} spec files OK, ${current.total} class selectors (baseline ${baseline.total})`);
}
