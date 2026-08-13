import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('./changelog-to-release-notes.mjs', import.meta.url), 'utf8');
assert.match(source, /md\.split\(\/\\r\?\\n\/\)/);

const heading = '## 2026-08-13 — fix(teacher-home): 穩定教師首頁課表與評量投影\r\n';
const headingRe = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;
assert.equal(heading.split(/\r?\n/)[0].match(headingRe)?.[2], 'fix(teacher-home): 穩定教師首頁課表與評量投影');

console.log('changelog-to-release-notes CRLF regression test passed');
