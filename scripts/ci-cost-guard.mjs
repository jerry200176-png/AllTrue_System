#!/usr/bin/env node

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const selfTest = process.argv.includes('--self-test');

// Entry jobs own the first hosted runner in each PR workflow. Downstream jobs
// are already blocked by `needs` when an entry job is skipped.
const contracts = {
  '.github/workflows/ci.yml': ['control_plane'],
  '.github/workflows/codeql.yml': ['changes'],
  '.github/workflows/docs-integrity.yml': ['changes'],
  '.github/workflows/htaccess-guard.yml': ['guard'],
  '.github/workflows/presubmit.yml': ['gate'],
  '.github/workflows/secret-scan.yml': ['block-secret-files', 'gitleaks'],
  '.github/workflows/ui-smoke.yml': ['ui-smoke'],
  '.github/workflows/control-plane-enforce.yml': ['enforce'],
  '.github/workflows/high-risk-test-gate.yml': ['gate'],
  '.github/workflows/missing-tests-warn.yml': ['warn'],
};

const errors = [];

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function jobBlock(workflow, jobId) {
  const lines = workflow.split('\n');
  const start = lines.findIndex((line) => line === `  ${jobId}:`);
  if (start < 0) return '';
  const next = lines.findIndex((line, index) => index > start && /^  [A-Za-z0-9_-]+:$/.test(line));
  return lines.slice(start + 1, next < 0 ? lines.length : next).join('\n');
}

for (const [relativePath, entryJobs] of Object.entries(contracts)) {
  let workflow = read(relativePath);
  if (selfTest && relativePath === '.github/workflows/ci.yml') {
    workflow = workflow
      .replace('ready_for_review', 'self_test_removed_event')
      .replace('!github.event.pull_request.draft', 'self_test_removed_draft_gate');
  }

  if (!/^\s{4}types:.*\bready_for_review\b/m.test(workflow)) {
    errors.push(`${relativePath}: pull_request must include ready_for_review`);
  }

  for (const jobId of entryJobs) {
    const block = jobBlock(workflow, jobId);
    if (!block) {
      errors.push(`${relativePath}: missing entry job ${jobId}`);
      continue;
    }
    if (!/^\s{4}if:.*!github\.event\.pull_request\.draft/m.test(block)) {
      errors.push(`${relativePath}: entry job ${jobId} must skip draft PRs`);
    }
  }
}

if (selfTest) {
  const detectedEvent = errors.some((error) => error.includes('must include ready_for_review'));
  const detectedGate = errors.some((error) => error.includes('must skip draft PRs'));
  if (!detectedEvent || !detectedGate) {
    console.error('ERROR: ci-cost-guard self-test did not detect both injected violations');
    process.exit(1);
  }
  console.log('ci-cost-guard self-test: OK (injected violations detected)');
  process.exit(0);
}

if (errors.length) {
  for (const error of errors) console.error(`ERROR: ${error}`);
  process.exit(1);
}

console.log(`ci-cost-guard: OK (${Object.keys(contracts).length} workflows)`);
