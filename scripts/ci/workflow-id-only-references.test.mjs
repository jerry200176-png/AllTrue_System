import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { checkWorkflowFileName, checkWorkflowText, idOnlyProblem, STRUCTURAL_WORDS } from './workflow-id-only-references.mjs';

// Synthetic name-shaped tokens. Never put a real person's name in this file.
const NAME = 'zzfamily-zzgiven';
const lines = (text) => checkWorkflowText(text).map((p) => p.line);

test('an ID-based approval reference passes', () => {
  for (const ref of [
    'founder-go-issue-5201-20261112',
    'founder-go-inapp-514-20261107',
    'founder-go-invoice1997-stale-values-20261008',
    'founder-go-package-ledger-net-repair-4455',
  ]) {
    assert.equal(idOnlyProblem(ref), null, ref);
    assert.deepEqual(lines(`          APPROVAL_REF=${ref}\n`), [], ref);
  }
});

test('a name-like token in an approval reference fails, in every place a literal can sit', () => {
  const ref = `founder-go-${NAME}-math-20260902`;
  assert.deepEqual(lines(`          APPROVAL_REF=${ref}\n`), [1]);
  assert.deepEqual(lines(`          APPROVAL_REF="${ref}"\n`), [1]);
  assert.deepEqual(lines(`          body='{"approval_reference":"${ref}","ttl_minutes":15}'\n`), [1]);
  assert.deepEqual(lines(`          body="{\\"approval_reference\\":\\"${ref}\\"}"\n`), [1]);
  assert.deepEqual(lines(`              execute.get('approval_reference') == '${ref}' and\n`), [1]);
  assert.deepEqual(lines(`x\n          APPROVAL_REF=${ref}; echo ok\n`), [2]);
});

test('a reference that is not founder-go is still judged when it is assigned', () => {
  assert.deepEqual(lines(`          APPROVAL_REF=repair-${NAME}-20261005\n`), [1]);
  assert.deepEqual(lines('          APPROVAL_REF="standing-a-auto-pop-td076-occurrence-repairs-${DIGEST:0:12}"\n'), []);
});

test('expansions count as ids; a bare expansion is not a literal', () => {
  assert.deepEqual(lines('          APPROVAL_REF="founder-go-td076-$REPAIR-c$CAMPUS-${DIGEST:0:12}"\n'), []);
  assert.deepEqual(lines('          APPROVAL_REF="founder-go-contract-charge-correction-$CASE_ID-$DIRECTION"\n'), []);
  assert.deepEqual(lines('          APPROVAL_REF="founder-go-issue-${{ inputs.issue }}-20261112"\n'), []);
  assert.deepEqual(lines('          body="{\\"approval_reference\\":\\"$APPROVAL_REF\\"}"\n'), []);
  assert.deepEqual(lines('            if s="$(APPROVAL_REF="$APPROVAL_REF" python3 - x)"; then\n'), []);
  assert.deepEqual(lines(`          APPROVAL_REF="founder-go-${NAME}-c$CAMPUS"\n`), [1]);
});

test('shape: uppercase, underscores and spaces are rejected; comments are ignored', () => {
  assert.notEqual(idOnlyProblem('founder-go-Issue-1'), null);
  assert.notEqual(idOnlyProblem('founder-go-issue_1'), null);
  assert.notEqual(idOnlyProblem(''), null);
  assert.deepEqual(lines(`  # approval ref founder-go-${NAME}-20260902 was retired\n`), []);
});

test('a mixed token is judged by its letter runs', () => {
  assert.equal(idOnlyProblem('founder-go-c15-r2-td076'), null);
  assert.notEqual(idOnlyProblem('founder-go-zzgiven164-20261005'), null);
});

test('the failure message never repeats the offending word', () => {
  const [problem] = checkWorkflowText(`          APPROVAL_REF=founder-go-${NAME}-20260902\n`);
  assert.ok(!problem.reason.includes('zzfamily') && !problem.reason.includes('zzgiven'), problem.reason);
  assert.match(problem.reason, /2 word\(s\)/);
});

test('pop workflow file names use the same vocabulary; other workflows are not judged', () => {
  assert.equal(checkWorkflowFileName('pop-invoice1997-stale-values.yml'), null);
  assert.equal(checkWorkflowFileName('pop-contract-charge-correction-3868.yml'), null);
  assert.equal(checkWorkflowFileName('pop-issue-5201-repair.yaml'), null);
  assert.notEqual(checkWorkflowFileName(`pop-${NAME}-billing-catchup.yml`), null);
  assert.notEqual(checkWorkflowFileName('pop-Invoice-1.yml'), null);
  assert.equal(checkWorkflowFileName('deploy.yml'), null);
  assert.equal(checkWorkflowFileName(`diagnose-${NAME}.yml`), null);
});

test('the vocabulary is a closed list: adding a word changes what passes', () => {
  assert.ok(Object.isFrozen(STRUCTURAL_WORDS));
  for (const word of STRUCTURAL_WORDS) assert.equal(idOnlyProblem(`founder-go-${word}-1`), null, word);
  assert.notEqual(idOnlyProblem('founder-go-notaword-1'), null);
});

test('every tracked workflow on this branch passes', () => {
  const dir = new URL('../../.github/workflows/', import.meta.url);
  const files = readdirSync(dir).filter((f) => /\.ya?ml$/.test(f));
  assert.ok(files.length > 0);
  let references = 0;
  for (const file of files) {
    assert.equal(checkWorkflowFileName(file), null, file);
    const text = readFileSync(new URL(file, dir), 'utf8');
    assert.deepEqual(checkWorkflowText(text), [], file);
    references += (text.match(/\bAPPROVAL_REF=["']?[a-z]/g) ?? []).length;
  }
  // The scan must really be reading literals, not passing because it found none.
  assert.ok(references >= 10, `only ${references} approval reference literals seen`);
});
