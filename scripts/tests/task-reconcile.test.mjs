import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compare, main, parseArgs, save } from '../ci/task-reconcile.mjs';

const a = 'a'.repeat(40), b = 'b'.repeat(40);
const observed = () => ({ observed_at: new Date().toISOString(), git: { head: a, changed: [] }, main: a,
  production: { backend: a, frontend: a }, prs: [{ number: 1, head: a, state: 'OPEN', failed: [], pending: [] }] });
const fixture = () => {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-task-test-'));
  const directory = path.join(base, '.local/agent-checkpoints'); fs.mkdirSync(directory, { recursive: true });
  return { base, file: path.join(directory, 'inapp.json'), done: () => fs.rmSync(base, { recursive: true, force: true }) };
};

test('rejects shell text, traversal and invalid PR IDs before observing', () => {
  for (const args of [['--checkpoint', '../public', '--prs', '1'], ['--checkpoint', 'x', '--prs', '1;touch x'],
    ['--checkpoint', 'x', '--prs', '99999999999999999'], ['--checkpoint', 'x', '--prs', '0']]) {
    assert.throws(() => parseArgs(args));
  }
});
test('observes stale heads, merged state, local edits and changed production', () => {
  const old = observed(), live = observed(); live.git.head = b; live.git.changed = [' M file']; live.main = b;
  live.production.backend = b; live.production.frontend = b; live.prs[0] = { number: 1, head: b, state: 'MERGED' };
  assert.equal(compare(old, live).length, 6);
  assert.match(compare(old, live).join(' '), /PR #1 head\/state changed/);
});
test('save survives a separate read and preserves goals/effect receipts without replay', async () => {
  const f = fixture();
  try {
    const marker = path.join(f.base, 'must-not-exist');
    const context = { goals: ['preserve approved product scope'], effects: ['already posted comment'], next_command: `touch ${marker}` };
    save(f.file, context, observed());
    let output = '';
    await main(['--checkpoint', 'inapp', '--prs', '1'], { base: f.base, observer: async () => observed(), output: text => { output = text; } });
    assert.equal(fs.existsSync(marker), false);
    assert.deepEqual(JSON.parse(fs.readFileSync(f.file)).context, context);
    assert.equal(JSON.parse(output).saved, false);
    assert.equal(fs.statSync(f.file).mode & 0o077, 0);
  } finally { f.done(); }
});
test('partial external failure leaves the prior checkpoint byte-for-byte intact', async () => {
  const f = fixture();
  try {
    save(f.file, { goals: ['continue'] }, observed()); const old = fs.readFileSync(f.file, 'utf8');
    await assert.rejects(main(['--checkpoint', 'inapp', '--prs', '1', '--save'], {
      base: f.base, observer: async () => { throw new Error('synthetic service failure'); }, output() {},
    }));
    assert.equal(fs.readFileSync(f.file, 'utf8'), old);
  } finally { f.done(); }
});
test('refuses to overwrite a concurrent coordinator update', async () => {
  const f = fixture();
  try {
    save(f.file, { goals: ['original'] }, observed());
    await assert.rejects(main(['--checkpoint', 'inapp', '--prs', '1', '--save'], {
      base: f.base, observer: async () => { save(f.file, { goals: ['newer accepted scope'] }, observed()); return observed(); }, output() {},
    }), /changed during observation/);
    assert.deepEqual(JSON.parse(fs.readFileSync(f.file)).context.goals, ['newer accepted scope']);
  } finally { f.done(); }
});
test('held checkpoint lock does not get deleted by another writer', () => {
  const f = fixture();
  try {
    fs.writeFileSync(`${f.file}.lock`, 'held');
    assert.throws(() => save(f.file, {}, observed()));
    assert.equal(fs.readFileSync(`${f.file}.lock`, 'utf8'), 'held');
  } finally { f.done(); }
});
test('checkpoint symlink cannot redirect the reader or writer', async () => {
  const f = fixture();
  try {
    const outside = path.join(f.base, 'outside.json'); fs.writeFileSync(outside, '{}'); fs.symlinkSync(outside, f.file);
    await assert.rejects(main(['--checkpoint', 'inapp', '--prs', '1', '--save'], { base: f.base, observer: async () => observed(), output() {} }), /symlinks/);
    assert.equal(fs.readFileSync(outside, 'utf8'), '{}');
  } finally { f.done(); }
});
test('directory replacement during observation cannot redirect checkpoint writes', async () => {
  const f = fixture();
  try {
    save(f.file, { goals: ['retain accepted scope'] }, observed());
    const outside = path.join(f.base, 'outside'); fs.mkdirSync(outside);
    const target = path.join(outside, 'inapp.json');
    const original = fs.readFileSync(f.file, 'utf8'); fs.writeFileSync(target, original, { mode: 0o600 });
    await assert.rejects(main(['--checkpoint', 'inapp', '--prs', '1', '--save'], {
      base: f.base, observer: async () => {
        fs.renameSync(path.dirname(f.file), path.join(f.base, 'original-directory'));
        fs.symlinkSync(outside, path.dirname(f.file));
        return observed();
      }, output() {},
    }), /symlinks|directory changed/);
    assert.equal(fs.readFileSync(target, 'utf8'), original);
    assert.equal(fs.existsSync(`${target}.lock`), false);
  } finally { f.done(); }
});
