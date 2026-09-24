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

const phaseASource = fs.readFileSync('.github/workflows/bug-phase-a-triage.yml', 'utf8');
assert.ok(
  phaseASource.includes('DISPOSITION: ${{ inputs.disposition }}')
    && phaseASource.includes('"disposition" => $disposition')
    && phaseASource.includes('"github_issue_url" => $issueUrl'),
  'Phase-A must pass the selected disposition and GitHub issue to the existing service',
);
assert.match(phaseASource, /\[\[ "\$DISPOSITION" =~ \^\[a-z_\]\+\$ \]\]/,
  'Phase-A must constrain the disposition before passing it through SSH');
assert.ok(!phaseASource.includes('"disposition" => "bug"'), 'Phase-A must not force every report to bug');
assert.ok(!phaseASource.includes('"engineering_required" => true'),
  'Phase-A must use the service-owned engineering requirement default');
assert.ok(phaseASource.includes('$svc::normalizeDispositionOptions(['),
  'Phase-A must validate disposition against the deployed service even on an idempotent rerun');

const phaseCSource = fs.readFileSync('.github/workflows/bug-phase-c-allowlist.yml', 'utf8');
assert.match(phaseCSource, /workflow_dispatch:\s+inputs:\s+bug_id:\s+description:[^\n]+\s+required: true/,
  'Phase-C manual dispatch must require one target ID');
assert.match(phaseCSource, /re\.fullmatch\(r"bug_id:\[ \\t\]\*\(\[1-9\]\[0-9\]\*\)/,
  'Phase-C request-file push must parse an exact positive target ID line');
assert.match(phaseCSource, /if len\(target_lines\) != 1:/,
  'Phase-C request-file push must reject absent or ambiguous target IDs');
assert.match(phaseCSource, /TARGET_BUG_ID: \$\{\{ steps\.target\.outputs\.bug_id \}\}/,
  'Phase-C must pass the validated target into the write step');
assert.match(phaseCSource, /TARGET_BUG_ID='\$TARGET_BUG_ID' bash/,
  'Phase-C must pass only the numeric target to the remote write step');
assert.match(phaseCSource, /!isset\(\$items\[\$targetBugId\]\)/,
  'Phase-C must reject targets absent from the allowlist before writes');
assert.match(phaseCSource, /git merge-base --is-ancestor/,
  'Phase-C must prove the allowlisted revision is an ancestor of production');
assert.match(phaseCSource, /\$items = \[\$targetBugId => \$target\];[\s\S]*?foreach \(\$items as \$bugId => \$cfg\)/,
  'Phase-C must loop only over the selected target after preflight');
const targetStep = phaseCSource.match(/      - name: Select one target\n[\s\S]*?        run: \|\n([\s\S]*?)\n      - name: Configure pinned production SSH trust/);
assert.ok(targetStep, 'Phase-C target selection step must exist');
const targetScript = targetStep[1].split('\n').map((line) => line.replace(/^          /, '')).join('\n');
const targetFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-phase-c-target-'));
try {
  const requestPath = path.join(targetFixture, 'operations/closeout/bug-phase-c-allowlist.request.md');
  const outputPath = path.join(targetFixture, 'github-output');
  fs.mkdirSync(path.dirname(requestPath), { recursive: true });
  const selectTarget = (eventName, dispatchId, requestText) => {
    fs.writeFileSync(requestPath, requestText);
    fs.writeFileSync(outputPath, '');
    execFileSync('bash', ['-c', targetScript], {
      cwd: targetFixture,
      env: { ...process.env, EVENT_NAME: eventName, DISPATCH_BUG_ID: dispatchId, GITHUB_OUTPUT: outputPath },
    });
    return fs.readFileSync(outputPath, 'utf8').trim();
  };
  assert.throws(() => selectTarget('push', '', '# old unscoped request\n'),
    'an old request without an explicit target must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329\nbug_id: 323\n'),
    'a request with two targets must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329\nbug_id: 323 # duplicate request\n'),
    'a malformed second target must not be ignored');
  assert.throws(() => selectTarget('push', '', 'bug_id:\n329\n'),
    'a target split across lines must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329 # comment\n'),
    'trailing content on the target line must fail closed');
  assert.equal(selectTarget('push', '', 'bug_id: 329\n'), 'bug_id=329');
  assert.throws(() => selectTarget('workflow_dispatch', '0', ''),
    'a nonpositive manual target must fail closed');
  assert.equal(selectTarget('workflow_dispatch', '329', ''), 'bug_id=329');
} finally {
  fs.rmSync(targetFixture, { recursive: true, force: true });
}
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
