import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { extractSelectors, findStale, specFiles, readSrc, classCounts, ratchetProblems, BASELINE_PATH } from './check-production-spec-selectors.mjs';

const spec = (body) => {
  const f = join(mkdtempSync(join(tmpdir(), 'sel-')), 'x.spec.js');
  writeFileSync(f, body);
  return f;
};

test('extracts classes, data-testid attributes and getByTestId (template prefix cut)', () => {
  const { classes, testids } = extractSelectors(
    "page.locator('.foo-bar > td.baz'); a.locator('[data-testid=\"t-one\"]'); b.getByTestId('t-two'); c.getByTestId(`row-${id}`);",
  );
  assert.deepEqual([...classes].sort(), ['baz', 'foo-bar']);
  assert.deepEqual(testids.map((t) => t.id).sort(), ['row-', 't-one', 't-two']);
});

test('flags a class or test id missing from src, passes when present', () => {
  const f = spec("p.locator('.gone-class'); p.getByTestId('gone-id'); p.locator('.here'); p.getByTestId('here-id');");
  const problems = findStale([f], 'class="here" data-testid="here-id"', new Set());
  assert.equal(problems.length, 2);
  assert.ok(problems.some((p) => p.includes('.gone-class')));
  assert.ok(problems.some((p) => p.includes('"gone-id"')));
});

test('does not accept a longer class that merely contains the name', () => {
  const f = spec("p.locator('.hint');");
  assert.equal(findStale([f], 'class="hint-text"', new Set()).length, 1);
});

test('template test ids in src cover a spec that selects one of them', () => {
  const f = spec("p.getByTestId('tc-reminder-settled');");
  assert.equal(findStale([f], ':data-testid="`tc-reminder-${v.key}`"', new Set()).length, 0);
});

test('the real production-run specs only select things that exist in frontend/src', () => {
  assert.deepEqual(findStale(specFiles(), readSrc()), []);
});

test('allowlist covers class selectors only, not test ids of the same name', () => {
  const f = spec("p.locator('.legacy'); p.getByTestId('legacy');");
  const problems = findStale([f], '', new Set(['legacy']));
  assert.equal(problems.length, 1);
  assert.ok(problems[0].includes('data-testid "legacy"'));
});

test('ratchet counts every class part and fails only when the count grows', () => {
  const f = spec("p.locator('.a .b'); p.locator('td.c'); p.getByTestId('x');");
  const cur = classCounts([f], '/nowhere');
  assert.equal(cur.total, 3);
  assert.deepEqual(ratchetProblems(cur, { total: 3, specs: { [f]: 3 } }), []);
  assert.deepEqual(ratchetProblems(cur, { total: 9, specs: { [f]: 9 } }), []);
  assert.equal(ratchetProblems(cur, { total: 2, specs: { [f]: 2 } }).length, 2);
  assert.equal(ratchetProblems(cur, { total: 3, specs: {} }).length, 1);
});

test('the committed baseline is not lower than the real count and is read from the file', () => {
  const baseline = JSON.parse(readFileSync(BASELINE_PATH, 'utf8'));
  assert.deepEqual(ratchetProblems(classCounts(specFiles()), baseline), []);
});
