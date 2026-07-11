import assert from 'node:assert/strict';
import test from 'node:test';

import { countRawHex, stripVueComments } from './design-hex-counter.mjs';

test('ignores decimal issue references and hex-looking text in comments', () => {
  const source = `
    <!-- #1151 and #ff0000 are documentation, not styles -->
    <script setup>
    // Regression #198 used #abcdef before the fix.
    const value = 'kept'; /* issue #614; example #123456 */
    </script>
    <style>
    /* Historical color: #654321 */
    </style>
  `;

  assert.equal(countRawHex(source), 0);
});

test('still detects valid CSS hex shapes, including all-decimal colors', () => {
  const source = `
    <template><div style="color: #123456; border-color: #abcd" /></template>
    <style>.danger { color: #ff0000; background: #fff; }</style>
  `;

  assert.equal(countRawHex(source), 4);
});

test('preserves comment markers and colors inside quoted strings', () => {
  const source = `
    <script setup>
    const url = "https://example.test/#abcdef";
    const literal = '/* not a comment */ #123';
    const template = \`// not a comment #aabbcc\`;
    </script>
  `;

  assert.equal(countRawHex(source), 3);
});

test('preserves line structure while removing multiline comments', () => {
  const source = 'color: #fff;\n/* #123456\n#abcdef */\nbackground: #000;';
  const stripped = stripVueComments(source);

  assert.equal(stripped.split('\n').length, source.split('\n').length);
  assert.equal(countRawHex(stripped), 2);
});
