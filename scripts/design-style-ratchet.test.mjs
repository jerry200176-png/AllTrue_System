import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  CATEGORIES,
  collectCounts,
  compareToBaseline,
  countBorderRadius,
  countBoxShadow,
  countFontSize,
  countNativeDialogs,
  countStyleViolations,
  countTemplateEmoji,
} from './design-style-ratchet.mjs';

test('font-size: raw values count, design vars / keywords / custom properties do not', () => {
  const css = `
    .a { font-size: 13px; }
    .b { font-size: var(--ds-font-size-sm); }
    .c { font-size: 1.2rem !important; }
    .d { font-size: inherit; }
    .e { font-size: calc(var(--ds-font-size-sm) + 10px); }
    :root { --ds-font-size-xs: 11px; --my-font-size: 9px; }
  `;
  assert.equal(countFontSize(css), 3);
});

test('border-radius: longhands count; var(--ds-radius-*) and 0 pass', () => {
  const css = `
    .a { border-radius: 12px; }
    .b { border-top-left-radius: 3px; }
    .c { border-radius: var(--ds-radius-md); }
    .d { border-radius: 0; }
    .e { border-radius: 50%; }
    .f { border-radius: var(--ds-radius-md) var(--ds-radius-md) 0 0; }
    .g { border-radius: var(--ds-radius-md) 12px; }
  `;
  assert.equal(countBorderRadius(css), 4);
});

test('box-shadow: design vars and focus-ring pass, literals count', () => {
  const css = `
    .a { box-shadow: 0 2px 4px rgba(0,0,0,.2); }
    .b { box-shadow: var(--ds-shadow-1); }
    .c { box-shadow: 0 0 0 3px var(--ds-focus-ring); }
    .d { box-shadow: none; }
    .e { box-shadow: var(--ds-shadow-1), 0 30px 60px rgba(0, 0, 0, .5); }
  `;
  assert.equal(countBoxShadow(css), 2);
});

test('inline style attributes are counted like CSS', () => {
  const vue = '<template><div style="font-size: 12px; border-radius: 4px"></div></template>';
  const counts = countStyleViolations(vue, { isVue: true });
  assert.equal(counts.font_size, 1);
  assert.equal(counts.border_radius, 1);
});

test('emoji are counted only inside the Vue <template> block', () => {
  const vue = `
    <template>
      <h2>\u{1F4E6} 課程包</h2>
      <span>✅ 完成</span>
      <!-- \u{1F6A7} comment emoji is ignored -->
      <template v-if="x"><b>⚠️</b></template>
      <small>© AllTrue</small>
      <button>✎ 編輯</button>
      <i>\u{1F468}\u200D\u{1F469}\u200D\u{1F467}</i>
    </template>
    <script setup>const label = '\u{1F389} </template>';</script>
  `;
  assert.equal(countTemplateEmoji(vue), 5);
  assert.equal(countStyleViolations(vue, { isVue: false }).emoji, 0);
});

test('native dialogs: calls count, definitions and member calls do not', () => {
  const js = `
    alert('x');
    if (window.confirm('sure?')) {}
    if (!confirm(\`刪除 \${name}？\`)) return;
    function confirm(a) { return a; }
    const api = { confirm(id) { return id; } };
    dialog.confirm('ok');
    this.alert('ok');
    globalThis.confirm('a'); window?.alert('b'); self.alert('c');
    class A { confirm(a: string): void { } }
  `;
  assert.equal(countNativeDialogs(js), 6);
  const vue = '<template><button @click="confirm(item)">x</button></template><script>alert(1)</script>';
  assert.equal(countStyleViolations(vue, { isVue: true }).native_dialog, 1);
});

test('commented-out code is ignored', () => {
  const js = '// alert("debug")\n/* font-size: 99px */\nconst a = 1;';
  const counts = countStyleViolations(js);
  assert.equal(counts.native_dialog, 0);
  assert.equal(counts.font_size, 0);
});

test('compareToBaseline: increases fail, decreases are reported, new files count from 0', () => {
  const baseline = { files: { 'a.vue': { font_size: 3, emoji: 1 } } };
  const current = { files: { 'a.vue': { font_size: 2, emoji: 2 }, 'b.vue': { native_dialog: 1 } } };
  const { increases, decreases } = compareToBaseline(baseline, current);
  assert.deepEqual(increases.map((v) => `${v.file}:${v.category}:${v.base}->${v.current}`), [
    'a.vue:emoji:1->2',
    'b.vue:native_dialog:0->1',
  ]);
  assert.deepEqual(decreases.map((v) => `${v.file}:${v.category}`), ['a.vue:font_size']);
});

test('collectCounts walks frontend/src and skips tests and generated files', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'style-ratchet-'));
  const src = path.join(root, 'frontend', 'src');
  fs.mkdirSync(path.join(src, 'pages', '__tests__'), { recursive: true });
  fs.writeFileSync(path.join(src, 'pages', 'A.vue'), '<template><p>\u{1F4C5}</p></template><style>.x{font-size:10px}</style>');
  fs.writeFileSync(path.join(src, 'pages', '__tests__', 'A.test.js'), 'alert(1)');
  fs.writeFileSync(path.join(src, 'x.generated.js'), 'alert(1)');
  fs.writeFileSync(path.join(src, 'util.js'), 'confirm("y")');
  const { totals, files } = collectCounts(root);
  assert.deepEqual(Object.keys(files).sort(), ['frontend/src/pages/A.vue', 'frontend/src/util.js']);
  assert.deepEqual(totals, { font_size: 1, border_radius: 0, box_shadow: 0, emoji: 1, native_dialog: 1 });
  assert.deepEqual(Object.keys(totals), CATEGORIES);
  fs.rmSync(root, { recursive: true, force: true });
});
