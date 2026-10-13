import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const workflow = readFileSync(new URL('../../.github/workflows/bug-reporter-timeout.yml', import.meta.url), 'utf8');
const stepStart = workflow.indexOf('      - name: Run reporter timeout command\n');
const stepEnd = workflow.indexOf('      - name: Upload timeout evidence\n', stepStart);
assert.ok(stepStart >= 0 && stepEnd > stepStart, 'timeout command step exists');
const step = workflow.slice(stepStart, stepEnd);
const runStart = step.indexOf('        run: |\n');
assert.ok(runStart >= 0, 'timeout command has shell body');
const shell = step.slice(runStart + '        run: |\n'.length)
  .split('\n')
  .map((line) => line.startsWith('          ') ? line.slice(10) : line)
  .join('\n');
const validation = shell.split('mkdir -p out')[0];
assert.ok(validation.includes('set -euo pipefail'), 'validation runs before SSH');

function request(overrides = {}) {
  const env = {
    ...process.env,
    GITHUB_REF: 'refs/heads/main',
    WORKFLOW_SHA: 'a'.repeat(40),
    MODE: 'dry-run',
    DAYS: '7',
    ACTOR: '',
    CONFIRMATION: '',
    REVIEWED_IDS: '',
    EVENT_NAME: 'workflow_dispatch',
    BATCH_IDS: '',
    EVIDENCE_SHA: '',
    ...overrides,
  };
  return spawnSync('bash', ['-c', validation], { env, encoding: 'utf8' }).status;
}

assert.equal(request(), 0, 'plain dry-run is allowed');
assert.equal(request({ MODE: 'apply', CONFIRMATION: 'CLOSE_STALE_RESOLVED', REVIEWED_IDS: '335,329' }), 0, 'reviewed apply is allowed');
assert.notEqual(request({ MODE: 'apply', CONFIRMATION: 'CLOSE_STALE_RESOLVED' }), 0, 'apply needs reviewed IDs');
assert.notEqual(request({ MODE: 'apply', CONFIRMATION: 'CLOSE_STALE_RESOLVED', REVIEWED_IDS: '335,335' }), 0, 'duplicate ID fails');
assert.notEqual(request({ MODE: 'apply', CONFIRMATION: 'CLOSE_STALE_RESOLVED', REVIEWED_IDS: '0' }), 0, 'zero ID fails');
assert.notEqual(request({ MODE: 'apply', CONFIRMATION: 'CLOSE_STALE_RESOLVED', REVIEWED_IDS: '335;echo unsafe' }), 0, 'non-numeric input fails');
assert.notEqual(request({ MODE: 'apply', REVIEWED_IDS: '335' }), 0, 'confirmation is required');
assert.notEqual(request({ REVIEWED_IDS: '335' }), 0, 'dry-run rejects apply-only IDs');
assert.notEqual(request({ GITHUB_REF: 'refs/heads/feature' }), 0, 'non-main branch fails');
assert.notEqual(request({ WORKFLOW_SHA: 'not-a-sha' }), 0, 'invalid SHA fails');
assert.notEqual(request({ ACTOR: '1;echo unsafe' }), 0, 'actor remains numeric');

assert.match(shell, /git merge-base --is-ancestor "\$WORKFLOW_SHA" "\$PROD_SHA"/, 'apply checks production ancestry');
assert.match(shell, /command_help="\$\(php artisan bugs:close-stale-resolved --help\)"/, 'apply reads deployed command help');
assert.match(shell, /\[\[ "\$command_help" == \*--reviewed-ids\* \]\]/, 'apply checks deployed command support');
assert.match(shell, /php artisan bugs:close-stale-resolved --days="\$DAYS" \$ACTOR_ARG --reviewed-ids="\$REVIEWED_IDS"/, 'apply passes exact reviewed IDs');
assert.match(step, /REVIEWED_IDS: \$\{\{ inputs\.reviewed_ids \}\}/, 'workflow dispatch input is wired');

// Founder 2A: daily unattended auto mode.
assert.match(workflow, /schedule:\n    - cron: "40 2 \* \* \*"/, 'daily schedule');
assert.match(step, /MODE: \$\{\{ github\.event_name == 'schedule' && 'auto' \|\| github\.event_name == 'push' && steps\.request\.outputs\.mode \|\| inputs\.mode \}\}/, 'schedule runs auto, push reads the request file, never apply');
assert.equal(request({ MODE: 'auto', DAYS: '7' }), 0, 'scheduled auto run validates (inputs default to empty/7)');
assert.equal(request({ MODE: 'auto-dry-run' }), 0, 'auto dry-run is allowed');
assert.notEqual(request({ MODE: 'auto', REVIEWED_IDS: '335' }), 0, 'auto takes no reviewed IDs');
assert.notEqual(request({ MODE: 'auto', CONFIRMATION: 'CLOSE_STALE_RESOLVED' }), 0, 'auto takes no confirmation');
assert.notEqual(request({ MODE: 'auto', GITHUB_REF: 'refs/heads/feature' }), 0, 'auto is main only');
assert.notEqual(request({ MODE: 'autopilot' }), 0, 'unknown mode fails closed');
assert.match(shell, /\[\[ "\$command_help" == \*--auto\* \]\] \|\| \{ echo "deployed command lacks --auto"/, 'auto fails closed on an old deploy');
assert.match(shell, /php artisan bugs:close-stale-resolved --auto \$ACTOR_ARG\n/, 'auto apply uses only --auto');
assert.doesNotMatch(shell.slice(shell.indexOf('=== AUTO APPLY ==='), shell.indexOf('exit 0', shell.indexOf('=== AUTO APPLY ==='))), /reviewed-ids|--days/, 'auto apply carries no reviewed IDs or days');

// Founder 2026-10-13: cloud trigger = merged request file (push), resolved by scripts/bug_reporter_timeout_request.py.
assert.match(workflow, /  push:\n    branches: \[main\]\n    paths:\n      - "operations\/closeout\/bug-reporter-timeout\.request\.yml"\n/, 'push trigger is path-filtered to the request file');
assert.match(workflow, /python3 scripts\/bug_reporter_timeout_request\.py --repo \. --head "\$GITHUB_SHA"/, 'push resolves the request file');
for (const name of ['Configure pinned production SSH trust', 'Setup SSH', 'Run reporter timeout command', 'Upload timeout evidence']) {
  const at = workflow.indexOf(`      - name: ${name}\n`);
  assert.ok(at >= 0, name);
  assert.match(workflow.slice(at, at + 300), /if: github\.event_name != 'push' \|\| steps\.request\.outputs\.run == 'true'/, `${name} is skipped for an idle or already-used request`);
}
const batch = { EVENT_NAME: 'push', MODE: 'reviewed-apply', BATCH_IDS: Array.from({ length: 25 }, (_, i) => 300 + i).join(','), EVIDENCE_SHA: 'b'.repeat(40) };
assert.equal(request(batch), 0, 'a reviewed batch may exceed the auto cap');
assert.notEqual(request({ ...batch, EVENT_NAME: 'workflow_dispatch' }), 0, 'reviewed-apply is push only');
assert.notEqual(request({ ...batch, BATCH_IDS: '' }), 0, 'reviewed-apply needs IDs');
assert.notEqual(request({ ...batch, BATCH_IDS: '301,301' }), 0, 'duplicate batch ID fails');
assert.notEqual(request({ ...batch, BATCH_IDS: '301;echo unsafe' }), 0, 'non-numeric batch fails');
assert.notEqual(request({ ...batch, EVIDENCE_SHA: '' }), 0, 'reviewed-apply needs the evidence SHA');
assert.notEqual(request({ ...batch, CONFIRMATION: 'CLOSE_STALE_RESOLVED' }), 0, 'reviewed-apply takes no dispatch inputs');
assert.notEqual(request({ MODE: 'auto', BATCH_IDS: '301' }), 0, 'auto never takes request IDs');
assert.equal(request({ EVENT_NAME: 'push', MODE: 'auto-dry-run' }), 0, 'request auto-dry-run validates');
assert.match(shell, /git merge-base --is-ancestor "\$EVIDENCE_SHA" "\$PROD_SHA"/, 'reviewed-apply checks production ancestry');
assert.match(shell, /\[\[ "\$command_help" == \*--reviewed-batch\* \]\]/, 'reviewed-apply checks deployed command support');
assert.match(shell, /php artisan bugs:close-stale-resolved --auto --reviewed-batch="\$BATCH_IDS" \$ACTOR_ARG\n/, 'reviewed-apply passes exactly the reviewed IDs');

console.log('bug-reporter-timeout workflow contract: PASS');
