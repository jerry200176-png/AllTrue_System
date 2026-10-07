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
assert.match(step, /MODE: \$\{\{ github\.event_name == 'schedule' && 'auto' \|\| inputs\.mode \}\}/, 'schedule runs auto, never apply');
assert.equal(request({ MODE: 'auto', DAYS: '7' }), 0, 'scheduled auto run validates (inputs default to empty/7)');
assert.equal(request({ MODE: 'auto-dry-run' }), 0, 'auto dry-run is allowed');
assert.notEqual(request({ MODE: 'auto', REVIEWED_IDS: '335' }), 0, 'auto takes no reviewed IDs');
assert.notEqual(request({ MODE: 'auto', CONFIRMATION: 'CLOSE_STALE_RESOLVED' }), 0, 'auto takes no confirmation');
assert.notEqual(request({ MODE: 'auto', GITHUB_REF: 'refs/heads/feature' }), 0, 'auto is main only');
assert.notEqual(request({ MODE: 'autopilot' }), 0, 'unknown mode fails closed');
assert.match(shell, /\[\[ "\$command_help" == \*--auto\* \]\] \|\| \{ echo "deployed command lacks --auto"/, 'auto fails closed on an old deploy');
assert.match(shell, /php artisan bugs:close-stale-resolved --auto \$ACTOR_ARG\n/, 'auto apply uses only --auto');
assert.doesNotMatch(shell.slice(shell.indexOf('=== AUTO APPLY ==='), shell.indexOf('exit 0', shell.indexOf('=== AUTO APPLY ==='))), /reviewed-ids|--days/, 'auto apply carries no reviewed IDs or days');

console.log('bug-reporter-timeout workflow contract: PASS');
