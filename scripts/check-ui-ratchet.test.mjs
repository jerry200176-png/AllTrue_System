import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { checkRatchet } from './check-ui-ratchet.mjs';

const at = "import AtButton from '@/components/design-system/AtButton.vue'";
test('ratchet blocks new At* imports and list growth', () => {
  assert.deepEqual(checkRatchet({ files: { 'a.vue': at }, list: ['a.vue'] }), []);
  assert.equal(checkRatchet({ files: { 'b.vue': at }, list: ['a.vue'] }).length, 1);
  assert.equal(checkRatchet({ files: {}, list: ['a.vue', 'b.vue'], baseList: ['a.vue'] }).length, 1);
  assert.deepEqual(checkRatchet({ files: { 'c.vue': 'ok' }, list: ['a.vue'], baseList: ['a.vue', 'x.vue'] }), []);
});

test('--vs-base fails closed on an unresolvable ref', () => {
  const r = spawnSync('node', [fileURLToPath(new URL('./check-ui-ratchet.mjs', import.meta.url)), '--vs-base', 'no-such-ref-xyz'], { encoding: 'utf8' });
  assert.equal(r.status, 1);
});
