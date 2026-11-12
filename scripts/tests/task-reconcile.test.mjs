import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compare, main, observe, parseArgs, save } from '../ci/task-reconcile.mjs';

const a = 'a'.repeat(40), b = 'b'.repeat(40);
const observed = () => ({ observed_at: new Date().toISOString(), git: { head: a, changed: [] }, main: a,
  production: { backend: a, frontend: a }, prs: [{ number: 1, head: a, state: 'OPEN', failed: [], pending: [] }] });
const fixture = () => {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-task-test-'));
  const directory = path.join(base, '.local/agent-checkpoints'); fs.mkdirSync(directory, { recursive: true });
  return { base, file: path.join(directory, 'inapp.json'), done: () => fs.rmSync(base, { recursive: true, force: true }) };
};

test('CI advisory uses the latest attempt while retaining raw checks (#5201)', async (t) => {
  const check = (conclusion, startedAt, status = 'COMPLETED') => ({
    __typename: 'CheckRun', name: 'Presubmit Checks', status, conclusion, startedAt,
  });
  const context = (state, createdAt) => ({ __typename: 'StatusContext', context: 'external', state, createdAt });
  const old = '2026-11-12T06:00:00Z', fresh = '2026-11-12T07:00:00Z';
  const cases = [
    ['cancelled attempt recovered', [check('SUCCESS', fresh), check('CANCELLED', old)], [], []],
    ['later failure remains visible', [check('FAILURE', fresh), check('SUCCESS', old)], [], ['Presubmit Checks']],
    ['queued rerun supersedes success', [check('SUCCESS', old), check(null, null, 'QUEUED')], ['Presubmit Checks'], []],
    ['in-progress rerun supersedes failure', [check('FAILURE', old), check(null, fresh, 'IN_PROGRESS')], ['Presubmit Checks'], []],
    ['commit status recovered', [context('SUCCESS', fresh), context('ERROR', old)], [], []],
    ['commit status pending', [context('PENDING', fresh), context('SUCCESS', old)], ['external'], []],
    ['unrelated failure retained', [check('SUCCESS', fresh), { ...check('FAILURE', old), name: 'Other test' }], [], ['Other test']],
  ];
  for (const [name, checks, pending, failed] of cases) await t.test(name, async () => {
    let query;
    const runner = async (binary, args) => {
      if (binary === 'git') return args[0] === 'rev-parse' ? a : '';
      if (binary === 'curl') return JSON.stringify({ backend_sha: a, frontend_sha: a });
      assert.equal(binary, 'gh'); query = args.at(-1);
      return JSON.stringify({ data: { repository: { defaultBranchRef: { target: { oid: a } },
        p1: { headRefOid: a, state: 'OPEN', statusCheckRollup: { contexts: { pageInfo: { hasNextPage: false }, nodes: checks } } } } } });
    };
    const pr = (await observe([1], { runner })).prs[0];
    assert.deepEqual(pr.pending, pending);
    assert.deepEqual(pr.failed, failed);
    assert.equal(pr.checks.length, checks.length, 'raw attempts remain available for diagnosis');
    assert.match(query, /on CheckRun\{[^}]*startedAt/);
    assert.match(query, /on StatusContext\{[^}]*createdAt/);
  });
});

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
test('reports CI failure and recovery with the same PR head and state', () => {
  const stages = [
    { pending: ['Required test'], failed: [], status: 'IN_PROGRESS', conclusion: null },
    { pending: [], failed: ['Required test'], status: 'COMPLETED', conclusion: 'FAILURE' },
    { pending: [], failed: [], status: 'COMPLETED', conclusion: 'SUCCESS' },
    { pending: [], failed: [], status: 'COMPLETED', conclusion: 'SKIPPED' },
  ].map(({ status, conclusion, ...buckets }) => {
    const snapshot = observed();
    Object.assign(snapshot.prs[0], buckets, { checks: [{ name: 'Required test', status, conclusion }] });
    return snapshot;
  });
  for (let i = 1; i < stages.length; i++) {
    assert.deepEqual(compare(stages[i - 1], stages[i]), ['PR #1 checks changed']);
  }
});
test('check ordering alone does not report new progress', () => {
  const old = observed(); old.prs[0].pending = ['Build', 'Tests'];
  old.prs[0].checks = old.prs[0].pending.map(name => ({ name, status: 'IN_PROGRESS', conclusion: null }));
  const live = structuredClone(old); live.prs[0].pending.reverse(); live.prs[0].checks.reverse();
  assert.deepEqual(compare(old, live), []);
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
