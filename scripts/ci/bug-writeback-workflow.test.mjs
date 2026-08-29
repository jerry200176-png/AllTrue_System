import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const workflows = [
  '.github/workflows/bug-phase-a-triage.yml',
  '.github/workflows/bug-followup-comment.yml',
];

for (const workflow of workflows) {
  const source = fs.readFileSync(workflow, 'utf8');
  assert.match(source, /PUBLIC_REPLY_B64="\$\(printf '%s' "\$PUBLIC_REPLY" \| base64 \| tr -d '\\n'\)"/);
  assert.match(source, /PUBLIC_REPLY_B64='\$PUBLIC_REPLY_B64' bash -s/);
  assert.match(source, /PUBLIC_REPLY="\$\(printf '%s' "\$PUBLIC_REPLY_B64" \| base64 -d\)"/);
  assert.ok(
    source.includes('[[ "$GITHUB_ISSUE_URL" =~ ^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$ ]]'),
    `${workflow} must strictly validate issue URLs`,
  );
  assert.ok(!source.includes('== https://github.com/*/issues/*'), `${workflow} must not use wildcard URL validation`);
  assert.ok(!source.includes('PUBLIC_REPLY=\\$(printf %q'), `${workflow} must not interpolate raw reply text`);
}

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-bug-reply-'));
const marker = path.join(tempDir, 'executed');
const payload = `literal $(touch ${marker}) \`echo SHOULD_NOT_RUN\` ; quoted 'reply'`;
const encoded = Buffer.from(payload, 'utf8').toString('base64');
const script = [
  'set -euo pipefail',
  `PUBLIC_REPLY_B64='${encoded}'`,
  'PUBLIC_REPLY="$(printf \'%s\' "$PUBLIC_REPLY_B64" | base64 -d)"',
  'printf \'%s\' "$PUBLIC_REPLY"',
].join('\n');

try {
  const decoded = execFileSync('bash', ['-c', script], { encoding: 'utf8' });
  assert.equal(decoded, payload, 'encoded reply must round-trip literally');
  assert.equal(fs.existsSync(marker), false, 'reply metacharacters must not execute');
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true });
}

console.log('bug-writeback-workflow.test.mjs: ok');
