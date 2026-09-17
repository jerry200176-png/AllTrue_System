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

const phaseCSource = fs.readFileSync('.github/workflows/bug-phase-c-allowlist.yml', 'utf8');
assert.match(
  phaseCSource,
  /280 => \[[\s\S]*?"rev" => "16e38fb969cf73a227a86c4dfe9918078b00a459",[\s\S]*?"deploy" => "35183308316",/,
  'in-app #280 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /297 => \[[\s\S]*?"rev" => "16e38fb969cf73a227a86c4dfe9918078b00a459",[\s\S]*?"deploy" => "35183308316",/,
  'in-app #297 must resolve only against its exact verified production revision and deploy run',
);
for (const bugId of [281, 283]) {
  assert.match(
    phaseCSource,
    new RegExp(`${bugId} => \\[[\\s\\S]*?"rev" => "86602e4ddaf8c03b73c78ee99d745507d884e958",[\\s\\S]*?"deploy" => "34670532157",`),
    `in-app #${bugId} must resolve only against its exact verified production revision and deploy run`,
  );
}
assert.match(
  phaseCSource,
  /284 => \[[\s\S]*?"rev" => "921fe606766172e63252f03805504ae08c8ac8d8",[\s\S]*?"deploy" => "34675463987",/,
  'in-app #284 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /287 => \[[\s\S]*?"rev" => "025f4e6ee3657c702178ca3ef8a8753b8002fe3e",[\s\S]*?"deploy" => "34705892308",/,
  'in-app #287 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /289 => \[[\s\S]*?"rev" => "09be02bf8f6ca559d8173091c7171b5a8e2b0189",[\s\S]*?"deploy" => "34927408130",/,
  'in-app #289 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /294 => \[[\s\S]*?"rev" => "09be02bf8f6ca559d8173091c7171b5a8e2b0189",[\s\S]*?"deploy" => "34927408130",/,
  'in-app #294 must resolve only against its exact verified production revision and repair run',
);
assert.match(
  phaseCSource,
  /298 => \[[\s\S]*?"rev" => "b4a5f64b1549a0ca8c154e7a7253948597f99f09",[\s\S]*?"deploy" => "34954544684",/,
  'in-app #298 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /291 => \[[\s\S]*?"rev" => "b4a5f64b1549a0ca8c154e7a7253948597f99f09",[\s\S]*?"deploy" => "34954544684",/,
  'in-app #291 must resolve only against its exact verified production revision and deploy run',
);
assert.ok(
  !phaseCSource.includes('repair_resolved'),
  'Phase-C allowlist entries must not replay already-resolved reports during unrelated runs',
);
assert.match(
  phaseCSource,
  /if \(in_array\(\$status, \["resolved", "closed"\], true\)\) \{\s+\$results\[\] = \["id" => \$bugId, "action" => "skip_already", "status" => \$status\];\s+continue;/,
  'Phase-C must skip every already-resolved or closed report',
);

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
