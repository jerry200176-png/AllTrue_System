import assert from 'node:assert/strict';
import test from 'node:test';
import { MARK, MAX_BODY, MAX_ROWS, mergeBody, parseInfection, parseStryker, renderSection } from '../ci/mutation-comment.mjs';

const LOG = `Escaped mutants:\n================\n\n1) /home/runner/work/r/r/backend/app/Services/Billing/X.php:25    [M] GreaterThan\n\n--- Original\n+++ New\n2) /home/runner/work/r/r/backend/app/Services/Y.php:7    [M] Plus\n`;

test('parseInfection reads file:line and mutator, path rooted at backend/', () => {
  assert.deepEqual(parseInfection(LOG), [
    { file: 'backend/app/Services/Billing/X.php', line: 25, mutator: 'GreaterThan' },
    { file: 'backend/app/Services/Y.php', line: 7, mutator: 'Plus' }]);
  assert.deepEqual(parseInfection('no mutants'), []);
});

test('parseStryker keeps Survived and NoCoverage only', () => {
  const j = { files: { 'a.mjs': { mutants: [
    { mutatorName: 'ConditionalExpression', status: 'Survived', location: { start: { line: 3 } } },
    { mutatorName: 'StringLiteral', status: 'Killed', location: { start: { line: 4 } } },
    { mutatorName: 'BlockStatement', status: 'NoCoverage', location: { start: { line: 9 } } }] } } };
  assert.deepEqual(parseStryker(j).map((m) => [m.line, m.status]), [[3, 'Survived'], [9, 'NoCoverage']]);
  assert.deepEqual(parseStryker(null), []);
});

test('one sticky body: sections replace themselves and never each other', () => {
  const php = renderSection('php', [{ file: 'backend/app/X.php', line: 1, mutator: 'Plus' }]);
  const js = renderSection('js', []);
  let body = mergeBody(undefined, 'php', php);
  assert.ok(body.startsWith(MARK));
  body = mergeBody(body, 'js', js);
  assert.ok(body.includes('backend/app/X.php:1') && body.includes('No surviving mutants'));
  body = mergeBody(body, 'php', renderSection('php', []));
  assert.ok(!body.includes('backend/app/X.php:1'));
  assert.equal(body.split(MARK).length, 2); // marker once
  assert.ok(body.includes('JS (StrykerJS): 0 surviving'));
});

test('hostile report fields never reach the comment: backtick, newline, html comment, mention, image', () => {
  const evil = [
    { file: 'a`b.php', line: 1, mutator: 'Plus' },
    { file: 'a.php\n- injected row', line: 1, mutator: 'Plus' },
    { file: 'a.php', line: 1, mutator: '--><!-- mutation-advisory:v1 -->' },
    { file: '![x](http://evil)', line: 1, mutator: 'Plus' },
    { file: 'a.php', line: 1, mutator: '@everyone' },
    { file: 'a.php', line: '5\nx', mutator: 'Plus' },
    { file: 'ok/File.php', line: 7, mutator: 'Plus' },
  ];
  const out = renderSection('php', evil);
  assert.ok(out.includes('`ok/File.php:7` Plus'));
  assert.ok(out.includes('6 entries with unexpected characters skipped'));
  for (const bad of ['a`b', 'injected', 'evil', '@everyone']) assert.ok(!out.includes(bad), bad);
  assert.equal(out.split('<!--').length - 1, 2); // only our own section markers
});

test('size cap: 3000 survivors stay under the GitHub limit; rows capped with a +N more line', () => {
  const many = Array.from({ length: 3000 }, (_, i) => ({ file: `backend/app/Services/${'X'.repeat(150)}${i}.php`, line: i + 1, mutator: 'GreaterThanOrEqualTo' }));
  const out = renderSection('php', many);
  assert.ok(out.includes(`+${3000 - MAX_ROWS} more`));
  assert.equal(out.split('\n- `').length - 1, MAX_ROWS);
  const body = mergeBody(mergeBody(undefined, 'php', out), 'js', renderSection('js', many));
  assert.ok(body.length <= MAX_BODY);
});
