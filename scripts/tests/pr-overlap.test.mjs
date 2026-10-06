import assert from 'node:assert/strict';
import test from 'node:test';
import { findOverlaps, hotFiles, render } from '../pr-overlap.mjs';

const prs = [
  { number: 1, title: 'self', headRefName: 'a', files: ['x.php', 'y.php'] },
  { number: 2, title: 'two', headRefName: 'b', author: { login: 'u' }, updatedAt: 'T', files: ['y.php', 'z.php'] },
  { number: 3, title: 'three', headRefName: 'c', files: ['x.php', 'y.php'] },
  { number: 4, title: 'none', headRefName: 'd', files: ['q.php'] },
];

test('findOverlaps: excludes self and disjoint PRs, most overlap first', () => {
  const o = findOverlaps(['x.php', 'y.php'], prs, 1);
  assert.deepEqual(o.map((p) => p.number), [3, 2]);
  assert.deepEqual(o[1].overlap, ['y.php']);
});

test('hotFiles: counts each PR once, threshold applies', () => {
  const merged = [{ files: ['a', 'a', 'b'] }, { files: ['a'] }, { files: ['a', 'b'] }];
  assert.deepEqual(hotFiles(merged, 3), [['a', 3]]);
});

test('render: empty when nothing, lists PR and files, flags hot own files only', () => {
  assert.equal(render([], [['x', 9]], ['y']), '');
  const t = render(findOverlaps(['y.php'], prs, 1), [['y.php', 4], ['other', 5]], ['y.php']);
  assert.match(t, /#2 two/);
  assert.match(t, /Hot files[\s\S]*y\.php.*\(4\)/);
  assert.doesNotMatch(t, /`other`/);
});
