import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { buildReport, summarizeAssets } from './bundle-budget.mjs';

const cssFixture = [
  { name: 'index-ABC123.css', size: 104537 },
  { name: 'CourseManagement-XYZ.css', size: 69666 },
  { name: 'LearningRecordsPage-XYZ.css', size: 69393 },
  { name: 'RocRankBadge-XYZ.css', size: 57 },
];

test('sums entry-prefixed chunks into initial, separate from lazy chunks', () => {
  const result = summarizeAssets(cssFixture, { entryPrefix: 'index-' });
  assert.equal(result.initial, 104537);
});

test('identifies the largest lazy-route chunk by name and size', () => {
  const result = summarizeAssets(cssFixture, { entryPrefix: 'index-' });
  assert.deepEqual(result.largestLazy, { name: 'CourseManagement-XYZ.css', size: 69666 });
});

test('reports total size and chunk count across all chunks, including the entry chunk', () => {
  const result = summarizeAssets(cssFixture, { entryPrefix: 'index-' });
  assert.equal(result.total, 104537 + 69666 + 69393 + 57);
  assert.equal(result.count, 4);
});

test('sums multiple entry-prefixed files into initial (mirrors Vite multi-entry output)', () => {
  const files = [
    { name: 'index-a.css', size: 100 },
    { name: 'index-b.css', size: 50 },
    { name: 'Lazy-c.css', size: 10 },
  ];
  const result = summarizeAssets(files, { entryPrefix: 'index-' });
  assert.equal(result.initial, 150);
  assert.equal(result.total, 160);
});

test('largestLazy is null when every chunk is an entry chunk (no lazy routes)', () => {
  const files = [{ name: 'index-a.css', size: 10 }];
  const result = summarizeAssets(files, { entryPrefix: 'index-' });
  assert.equal(result.largestLazy, null);
});

test('throws instead of silently reporting a 0 KB metric when no chunk matches the entry prefix', () => {
  // This is the failure mode a Vite output-naming change would trigger: the
  // pre-#1273 bash step would have computed CSS_SIZE="" -> CSS_KB=0 and
  // reported the budget as satisfied. This must fail loudly instead.
  const files = [{ name: 'Lazy-only.css', size: 10 }];
  assert.throws(
    () => summarizeAssets(files, { entryPrefix: 'index-', label: 'css' }),
    /no entry chunk found matching prefix "index-" for css/
  );
});

test('buildReport scans a real directory, excludes .map files, and matches summarizeAssets', () => {
  const dir = mkdtempSync(join(tmpdir(), 'bundle-budget-test-'));
  try {
    writeFileSync(join(dir, 'index-abc.js'), 'x'.repeat(10));
    writeFileSync(join(dir, 'index-abc.js.map'), 'x'.repeat(999));
    writeFileSync(join(dir, 'index-abc.css'), 'x'.repeat(20));
    writeFileSync(join(dir, 'Lazy-xyz.css'), 'x'.repeat(5));

    const report = buildReport(dir);

    assert.equal(report.js.initial, 10);
    assert.equal(report.js.count, 1, '.js.map must be excluded from the chunk count');
    assert.equal(report.css.initial, 20);
    assert.equal(report.css.total, 25);
    assert.deepEqual(report.css.largestLazy, { name: 'Lazy-xyz.css', size: 5 });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('buildReport throws when the JS entry chunk is missing (Vite naming change)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'bundle-budget-test-'));
  try {
    writeFileSync(join(dir, 'main-abc.js'), 'x'.repeat(10)); // renamed away from index-*
    writeFileSync(join(dir, 'index-abc.css'), 'x'.repeat(20));

    assert.throws(() => buildReport(dir), /no entry chunk found matching prefix "index-" for js/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
