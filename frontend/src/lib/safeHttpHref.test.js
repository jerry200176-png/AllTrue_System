import assert from 'node:assert/strict';
import { safeHttpHref } from './safeHttpHref.js';

for (const bad of ['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,<script>', 'vbscript:x', '', null]) {
  assert.equal(safeHttpHref(bad), '', `must not render: ${bad}`);
}
for (const ok of ['https://x.example/a.pdf', 'http://x.example/a', '/uploads/a.pdf']) {
  assert.equal(safeHttpHref(ok), ok);
}
console.log('safeHttpHref.test.js: ok');
