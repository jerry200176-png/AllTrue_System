import assert from 'node:assert/strict';
import test from 'node:test';
import {
  classifyStepFailure,
  classifySameShaRerun,
  TAXONOMY,
  GOV_CODES,
} from './gov-codes.mjs';
import { validateBranchName, slashPrefixRegexSource } from './branch-policy.mjs';
import { claimedSessionFiles, shouldBindAgentSession } from './session-claim.mjs';

test('taxonomy labels unique and include required classes', () => {
  assert.ok(TAXONOMY.includes('UNKNOWN'));
  assert.ok(TAXONOMY.includes('PR_SIZE_POLICY'));
  assert.ok(TAXONOMY.includes('FLAKY_TEST'));
  assert.equal(new Set(TAXONOMY).size, TAXONOMY.length);
});

test('classifies branch, size, provenance, conflict, generated', () => {
  assert.equal(classifyStepFailure({ step: '[CHECK 1] Branch naming', logSnippet: 'Invalid branch name' }).primary, 'BRANCH_NAMING_POLICY');
  assert.equal(classifyStepFailure({ step: '[CHECK 2] PR size', logSnippet: 'PR too large: 1536' }).primary, 'PR_SIZE_POLICY');
  assert.equal(classifyStepFailure({ workflow: 'Agent Session Provenance', logSnippet: 'base_sha is not an ancestor' }).primary, 'PROVENANCE_STALE');
  assert.equal(classifyStepFailure({ logSnippet: '<<<<<<< HEAD' }).primary, 'MERGE_CONFLICT_MARKERS');
  assert.equal(classifyStepFailure({ step: 'Generated in-app updates must match committed artifacts' }).primary, 'GENERATED_ARTIFACT_DRIFT');
});

test('same-SHA flake classifier', () => {
  assert.equal(classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'success', headShaSame: true }).kind, 'flake');
  assert.equal(classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'failure', headShaSame: true }).kind, 'deterministic');
  assert.equal(classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'success', headShaSame: false }).kind, 'code_changed');
});

test('branch policy accepts sec/design/cursor/claude/revert; rejects agent/ops', () => {
  assert.equal(validateBranchName('sec/1401-impact-audit').ok, true);
  assert.equal(validateBranchName('security/foo').canonicalPrefix, 'sec');
  assert.equal(validateBranchName('design/ui').ok, true);
  assert.equal(validateBranchName('cursor/ci-gov-1ff7').ok, true);
  assert.equal(validateBranchName('claude/course-management-display-issue-ji5tq1').ok, true);
  assert.equal(validateBranchName('exo/INT-20260905-200220-8PG4').ok, true);
  assert.equal(validateBranchName('revert/x').ok, true);
  assert.equal(validateBranchName('agent/x').ok, false);
  assert.equal(validateBranchName('ops/x').ok, false);
  assert.match(slashPrefixRegexSource(), /sec/);
  assert.match(slashPrefixRegexSource(), /claude/);
  assert.match(slashPrefixRegexSource(), /exo/);
});

test('inherited session files are not a claim; only diff paths bind', () => {
  assert.deepEqual(claimedSessionFiles([]), { agent: false, human: false });
  assert.equal(shouldBindAgentSession(claimedSessionFiles([])), false);
  assert.equal(shouldBindAgentSession(claimedSessionFiles(['README.md'])), false);
  assert.equal(shouldBindAgentSession(claimedSessionFiles(['.agent-session/manifest.json'])), true);
  assert.equal(shouldBindAgentSession(claimedSessionFiles(['.agent-session/human-authored.json'])), false);
});

test('stable gov codes', () => {
  assert.equal(GOV_CODES.BRANCH, 'GOV-BRANCH-001');
  assert.equal(GOV_CODES.GENERATED, 'GOV-GENERATED-001');
  assert.equal(GOV_CODES.SIZE, 'GOV-SIZE-001');
});
