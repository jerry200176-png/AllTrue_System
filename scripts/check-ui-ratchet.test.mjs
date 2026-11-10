import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { baseJson, checkRatchet, checkOverlayLimits, countOverlays } from './check-ui-ratchet.mjs';

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

test('overlay counts only go down', () => {
  const src = '<div class="modal-backdrop"><Teleport to="body"/> if (window.confirm("x")) prompt("y") alert(1) window.alert(2) x.alert(3) function confirm() {}';
  const counts = countOverlays({ 'src/pages/A.vue': src, 'src/components/ui/x.vue': src, 'src/components/__tests__/t.js': src, 'src/lib/l.js': src });
  assert.deepEqual(counts, { modal: 1, confirm: 1, prompt: 1, alert: 2, teleport: 1 });
  const limits = { modal: 1, confirm: 1, prompt: 1, alert: 2, teleport: 1 };
  assert.deepEqual(checkOverlayLimits({ counts, limits }), []);
  assert.equal(checkOverlayLimits({ counts, limits: { ...limits, prompt: 0 } }).length, 1);
  assert.equal(checkOverlayLimits({ counts, limits, baseLimits: { ...limits, modal: 0 } }).length, 1);
  assert.deepEqual(checkOverlayLimits({ counts: { ...counts, modal: 0 }, limits: { ...limits, modal: 0 }, baseLimits: limits }), []);
});
test('overlay limits fail closed on missing or non-integer values', () => {
  const counts = { modal: 0, confirm: 0, prompt: 0, alert: 0, teleport: 0 };
  const limits = { ...counts };
  const { modal, ...missing } = limits;
  assert.equal(checkOverlayLimits({ counts, limits: missing }).length, 1);
  assert.equal(checkOverlayLimits({ counts, limits, baseLimits: missing }).length, 1);
  assert.equal(checkOverlayLimits({ counts, limits: { ...limits, alert: 1.5 } }).length, 1);
  assert.equal(checkOverlayLimits({ counts, limits: { ...limits, alert: '0' } }).length, 1);
});

test('baseJson: absent file is null, invalid JSON or bad ref throws', () => {
  const d = fs.mkdtempSync(path.join(os.tmpdir(), 'ratchet-'));
  const git = (...a) => execFileSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', ...a], { cwd: d, stdio: 'ignore' });
  git('init', '-q');
  fs.writeFileSync(path.join(d, 'bad.json'), '{oops');
  fs.writeFileSync(path.join(d, 'ok.json'), '{"a":1}');
  git('add', '.');
  git('commit', '-qm', 'x');
  assert.equal(baseJson('HEAD', 'missing.json', d), null);
  assert.deepEqual(baseJson('HEAD', 'ok.json', d), { a: 1 });
  assert.throws(() => baseJson('HEAD', 'bad.json', d), /cannot read or parse/);
  assert.throws(() => baseJson('no-such-ref', 'ok.json', d), /cannot resolve/);
});
