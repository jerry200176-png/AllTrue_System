import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { findUnhandled } from './check-unhandled-emits.mjs';

const child = `<template><button @click="emit('navigate','x')"/></template>
<script setup>const emit = defineEmits(['navigate'])</script>`;
const parent = (attrs) => `<template>\n<div>\n<Child ${attrs} />\n</div></template>
<script setup>import Child from './Child.vue'</script>`;
const run = (attrs) => findUnhandled(new Map([['/s/Parent.vue', parent(attrs)], ['/s/Child.vue', child]]), '/s');

test('missing @navigate is reported (the #371 shape)', () => {
  const r = run(':a="1"');
  assert.equal(r.length, 1);
  assert.deepEqual([r[0].parent, r[0].line, r[0].child, r[0].event], ['Parent.vue', 3, 'Child', 'navigate']);
});
test('@navigate, v-on:, modifiers, $attrs are handled', () => {
  for (const a of ['@navigate="go"', 'v-on:navigate="go"', '@navigate.once="go"', 'v-bind="$attrs"']) assert.equal(run(a).length, 0, a);
});

test('real history: pre-fix App.vue (f369738a5~1) flags TuitionCollectionPage/navigate', (t) => {
  let old;
  try { old = execFileSync('git', ['show', 'f369738a5~1:frontend/src/App.vue'], { encoding: 'utf8', maxBuffer: 1 << 26, stdio: 'pipe' }); }
  catch { return t.skip('shallow clone: history unavailable'); }
  const f = join(mkdtempSync(join(tmpdir(), 'emits-')), 'App.old.vue');
  writeFileSync(f, old);
  const r = spawnSync('node', [new URL('./check-unhandled-emits.mjs', import.meta.url).pathname, '--file-override', `App.vue=${f}`], { encoding: 'utf8' });
  assert.equal(r.status, 1);
  assert.match(r.stderr, /App\.vue:\d+ <TuitionCollectionPage> emits 'navigate' but no listener/);
});
