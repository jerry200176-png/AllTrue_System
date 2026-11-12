import assert from 'node:assert/strict';
import { knownFamilies, validateMap, run } from './rootfam-backfill.mjs';

const fams = knownFamilies();
assert.ok(fams.has('money') && fams.has('no-undo'), 'families are read from .github/labels.yml');
assert.equal(fams.size, 14);
assert.throws(() => validateMap({ 12: 'not-a-family' }), /unknown family for #12/);
assert.throws(() => validateMap({ abc: 'money' }), /bad issue number/);

const store = { 1: [], 2: [{ name: 'rootfam:money' }], 3: [{ name: 'rootfam:money' }, { name: 'rootfam:no-undo' }] };
const calls = [];
const api = async (method, path, body) => {
  calls.push([method, path, body]);
  const n = Number(path.split('/')[2]);
  return { labels: store[n] };
};
const map = { 1: 'write-guard', 2: 'no-undo', 3: 'money' };

let out = await run(map, { api });
assert.deepEqual(out, ['would label #1: rootfam:write-guard', 'skip #2: already rootfam:money', 'skip #3: already has multiple rootfam labels']);
assert.ok(calls.every(([m]) => m === 'GET'), 'dry-run (default) never writes');

calls.length = 0;
out = await run(map, { api, apply: true });
assert.deepEqual(calls.filter(([m]) => m === 'POST'), [['POST', '/issues/1/labels', { labels: ['rootfam:write-guard'] }]], 'apply writes only the unlabelled issue');
store[4] = [];
const prApi = async (m, path) => ({ labels: [], pull_request: {} });
await assert.rejects(run({ 4: 'money' }, { api: prApi, apply: true }), /#4 is a pull request/);
console.log('rootfam-backfill: PASS');
