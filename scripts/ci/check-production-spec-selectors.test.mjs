import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { extractSelectors, findStale, specFiles, readSrc } from './check-production-spec-selectors.mjs';

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
