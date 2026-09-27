import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('./changelog-to-release-notes.mjs', import.meta.url), 'utf8');
assert.match(source, /md\.split\(\/\\r\?\\n\/\)/);
// Must not use bare \bPR (eats "pr" from "progress"); require trailing word boundary.
assert.match(source, /\\bPR\\b\\s\*\\\)\?\/gi/);

const heading = '## 2026-08-13 — fix(teacher-home): 穩定教師首頁課表與評量投影\r\n';
const headingRe = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;
assert.equal(heading.split(/\r?\n/)[0].match(headingRe)?.[2], 'fix(teacher-home): 穩定教師首頁課表與評量投影');

// Local stripTechNoise mirror of the fixed PR token rule.
function stripPrToken(s) {
  return String(s).replace(/\bPR\b\s*\)?/gi, '');
}
assert.equal(stripPrToken('workspace progress + continuum'), 'workspace progress + continuum');
assert.equal(stripPrToken('via PR ) leftover'), 'via  leftover');

console.log('changelog-to-release-notes CRLF regression test passed');
