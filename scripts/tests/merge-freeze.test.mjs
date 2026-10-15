import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { FREEZE_PATH, parseFreeze, readFreeze } from '../../.github/scripts/merge-freeze.mjs';
import { freezeHold } from '../../.github/scripts/land-queue.mjs';

const root = path.resolve(import.meta.dirname, '../..');

test('parseFreeze: explicit true/false, quoted reason, since', () => {
  assert.deepEqual(parseFreeze('frozen: false\nreason: "x"\nsince: 2026-10-15\n'), { frozen: false, reason: 'x', since: '2026-10-15' });
  assert.equal(parseFreeze('# c\nfrozen: true # comment\n').frozen, true);
});

test('parseFreeze: malformed, empty, duplicate or missing key fails closed (frozen)', () => {
  for (const t of ['', '   ', 'frozen: no', 'frozen: False', '  frozen: false', 'reason: x', 'frozen: false\nfrozen: false', null, undefined]) {
    assert.equal(parseFreeze(t).frozen, true, JSON.stringify(t));
  }
});

test('readFreeze: reads main through the API; any error is frozen', () => {
  let args;
  assert.equal(readFreeze('o/r', 'main', (a) => { args = a; return 'frozen: false\n'; }).frozen, false);
  assert.ok(args.at(-1).endsWith(`/repos/o/r/contents/${FREEZE_PATH}?ref=main`));
  assert.equal(readFreeze('o/r', 'main', () => { throw new Error('HTTP 404'); }).frozen, true);
});

test('committed freeze file is valid and currently frozen', () => {
  const f = parseFreeze(fs.readFileSync(path.join(root, FREEZE_PATH), 'utf8'));
  assert.equal(f.frozen, true);
  assert.match(f.reason, /Founder merge freeze 2026-10-15/);
  assert.equal(f.since, '2026-10-15');
});

test('land-queue freezeHold: frozen pauses the queue with one comment key per PR per freeze', () => {
  const calls = [];
  const queue = [{ number: 4141 }, { number: 9 }];
  assert.equal(freezeHold({ frozen: true, reason: 'r', since: '2026-10-15' }, queue, (...a) => calls.push(a)), true);
  assert.deepEqual(calls.map(([n, key]) => [n, key]), [[4141, 'freeze-2026-10-15'], [9, 'freeze-2026-10-15']]);
  assert.equal(freezeHold({ frozen: false, reason: '', since: '' }, queue, () => assert.fail('no comment')), false);
});

test('land-queue checks the freeze before any batch, update or merge', () => {
  const src = fs.readFileSync(path.join(root, '.github/scripts/land-queue.mjs'), 'utf8');
  const body = src.slice(src.indexOf('function processQueue'));
  const hold = body.indexOf("freezeHold(readFreeze(REPO, 'main')");
  assert.ok(hold > 0, 'processQueue must call freezeHold(readFreeze(...))');
  for (const s of ['git/matching-refs', 'startBatch(', 'update-branch', 'guardedSquash(']) assert.ok(body.indexOf(s) > hold, s);
});
