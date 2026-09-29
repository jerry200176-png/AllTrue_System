import assert from 'node:assert/strict';
import fs from 'node:fs';

const wfPath = '.github/workflows/staff-multirole-activation.yml';
const wf = fs.readFileSync(wfPath, 'utf8');
const diffPhp = fs.readFileSync('scripts/ops/staff-multirole-role-diff.php', 'utf8');
const grantPhp = fs.readFileSync('scripts/ops/staff-multirole-pilot-grant.php', 'utf8');

// Split into jobs (top-level keys under `jobs:` indented two spaces).
const jobsBlock = wf.slice(wf.indexOf('\njobs:\n'));
const jobs = {};
for (const m of jobsBlock.matchAll(/^  ([a-z_]+):\n([\s\S]*?)(?=^  [a-z_]+:\n|(?![\s\S]))/gm)) jobs[m[1]] = m[2];
assert.deepEqual(Object.keys(jobs).sort(), ['disable', 'enable', 'guard', 'pilot_dry_run', 'pilot_grant', 'preflight']);

// Inputs
assert.match(wf, /workflow_dispatch:/);
for (const a of ['preflight', 'grant_pilot_dry_run', 'grant_pilot', 'revoke_pilot', 'enable', 'disable']) {
  assert.ok(wf.includes(`          - ${a}\n`), `action option ${a}`);
}
for (const i of ['confirm', 'expected_head_sha', 'user_id', 'campus_id', 'expected_pilot_user_id', 'acknowledge_narrowing']) {
  assert.match(wf, new RegExp(`^      ${i}:\\n`, 'm'), `input ${i}`);
}

// Exact confirmation strings + input validation in guard
const guard = jobs.guard;
assert.ok(guard.includes('"ENABLE_STAFF_MULTI_ROLE_V1:${EXPECTED_HEAD_SHA}"'));
assert.ok(guard.includes('"DISABLE_STAFF_MULTI_ROLE_V1"'));
assert.ok(guard.includes('"GRANT_PILOT:user=${USER_ID}:campus=${CAMPUS_ID}"'));
assert.ok(guard.includes('"REVOKE_PILOT:user=${USER_ID}:campus=${CAMPUS_ID}"'));
assert.ok(guard.includes("'^[0-9a-f]{40}$'"), 'sha must be 40 hex');
assert.ok(guard.includes("'^[1-9][0-9]{0,9}$'"), 'ids must be numeric');
assert.match(guard, /Unknown action/);
assert.ok(guard.includes('narrow_from=([0-9]+(,[0-9]+)*))?(:flag=on)?$'), 'grant suffix grammar');
assert.ok(guard.includes('":flag=on") FLAG=1'), 'revoke :flag=on suffix');
assert.ok(guard.includes('EXPECTED_PILOT_USER_ID" | grep -Eq'), 'enable requires expected_pilot_user_id');
assert.match(guard, /grant_pilot_dry_run\)\n\s+need_ids/, 'dry-run validates ids, needs no confirm');
assert.match(guard, /narrow_from=\$NARROW/);

// Every job except guard needs guard; mutating jobs are environment-gated; preflight is read-only.
for (const [name, body] of Object.entries(jobs)) {
  if (name !== 'guard') assert.match(body, /needs: guard/, `${name} needs guard`);
}
assert.ok(!/environment:/.test(jobs.pilot_dry_run), 'dry-run is read-only, no approval');
assert.match(jobs.pilot_dry_run, /PILOT_DRY_RUN=1/);
assert.ok(!/PILOT_DRY_RUN=0/.test(jobs.pilot_dry_run));
assert.match(jobs.pilot_grant, /PILOT_DRY_RUN=0/);
assert.ok(jobs.pilot_grant.includes('PILOT_NARROW_FROM: ${{ needs.guard.outputs.narrow_from }}'));
assert.ok(jobs.pilot_grant.includes('PILOT_FLAG_ON_ACK: ${{ needs.guard.outputs.flag_ack }}'));
for (const name of ['pilot_grant', 'enable', 'disable']) {
  assert.match(jobs[name], /environment: production-activation/, `${name} environment gate`);
}
assert.ok(!/environment:/.test(jobs.preflight), 'preflight is read-only, no approval needed');
assert.ok(!/sed -i|cp \.env|artisan optimize/.test(jobs.preflight), 'preflight must not edit .env or optimize');
assert.match(jobs.preflight, /READ_ONLY=true/);

// Pinned SSH trust helper in every SSH job
for (const name of ['preflight', 'pilot_dry_run', 'pilot_grant', 'enable', 'disable']) {
  assert.match(jobs[name], /uses: \.\/\.github\/actions\/production-ssh-trust/, `${name} SSH trust`);
  assert.match(jobs[name], /Remove local key material/, `${name} key cleanup`);
}

// Never config:clear / cache:clear / optimize:clear (CLAUDE.md incident B)
assert.ok(!/config:clear|cache:clear|optimize:clear/.test(wf), 'forbidden cache clears');
for (const name of ['enable', 'disable']) {
  assert.match(jobs[name], /php artisan optimize(\n| \|\||;)/, `${name} optimize`);
  assert.match(jobs[name], /opcache:reset/, `${name} opcache reset`);
  assert.ok(jobs[name].includes("config('staff_capabilities.multi_role_v1_enabled')"), `${name} effective config verify`);
  assert.match(jobs[name], /api\/v1\/health/, `${name} health check`);
  assert.match(jobs[name], /sha256sum/, `${name} backup checksum`);
}

// Enable: HEAD check, diff gate before any edit, backup+restore, idempotent edit, auto-restore on failure
const en = jobs.enable;
const idx = (s) => { const i = en.indexOf(s); assert.ok(i >= 0, `enable missing: ${s}`); return i; };
assert.ok(idx('REFUSED-WRONG-HEAD') < idx('diff-unexpected=0'));
assert.ok(idx('diff-unexpected=0') < idx('cp .env "$BACKUP_FILE"'));
assert.ok(idx('cp .env "$BACKUP_FILE"') < idx("STAFF_MULTI_ROLE_V1=true"));
assert.ok(en.includes('REFUSED-UNEXPECTED-DIFF') && en.includes('REFUSED-PILOT-SET-MISMATCH'));
assert.ok(en.includes("grep -c '^STAFF_MULTI_ROLE_V1='") && en.includes('REFUSED-DIRTY-ENV'));
assert.ok(en.includes('cp "$BACKUP_FILE" .env'), 'restore from backup');
for (const r of ['FAILED-CONFIG-NOT-EFFECTIVE-ROLLED-BACK', 'FAILED-HEALTH-ROLLED-BACK', 'FAILED-SMOKE-ROLLED-BACK', 'FAILED-DUPLICATE-KEY-ROLLED-BACK']) {
  assert.ok(en.includes(r), `enable ${r}`);
}
assert.ok((en.match(/^\s+rollback [A-Z-]+$/gm) || []).length >= 4, 'rollback called on dup-key/optimize/config/health/smoke failures');
assert.ok(en.includes('enable-result=ROLLBACK-FAILED') && en.includes('post-rollback-effective='), 'rollback re-verifies effective config');
assert.ok(en.includes('trap ') && en.includes('FAILED-UNEXPECTED-EXIT-ROLLED-BACK'), 'exit trap restores');
assert.ok(en.includes('REFUSED-BACKUP-FAILED') && en.includes('cp .env "$BACKUP_FILE" ||'), 'backup failure refuses');
assert.ok(en.includes('timeout 300 bash /home/admin/scripts/post-merge-smoke.sh'), 'smoke has timeout');
assert.ok(en.includes('REFUSED-PILOT-SET-MISMATCH') && en.includes('$EXPECTED_PILOT_USER_ID'), 'exact pilot set');
assert.ok(en.includes('REFUSED-NARROWING-NOT-ACKNOWLEDGED') && en.includes('"$ACK_NARROWING" != "true"'), 'narrowing gate');
for (const v of ['SMOKE_TEACHER_LOGIN', 'SMOKE_TEACHER_PASSWORD', 'SMOKE_BRANCH_ID', 'EXPECTED_HEAD_SHA']) {
  assert.ok(en.includes(`${v}="$${v}"`), `${v} passed via env VAR="$VAR"`);
}
for (const name of ['preflight', 'pilot_dry_run', 'pilot_grant', 'enable']) {
  const body = jobs[name];
  const heredoc = body.slice(body.indexOf("<< 'ENDSSH'"), body.indexOf('\n          ENDSSH'));
  assert.ok(heredoc.length > 10 && !heredoc.includes('${{'), `${name}: no Actions expressions inside the remote script`);
}
assert.ok(jobs.disable.includes('REFUSED-BACKUP-FAILED') && jobs.disable.includes('cp .env "$BACKUP_FILE" ||'));
assert.ok(jobs.disable.includes("STAFF_MULTI_ROLE_V1=false") && jobs.disable.includes('cp "$BACKUP_FILE" .env'));

// Scripts: read-only diff never writes or flips config; grant script is idempotent + audited
assert.ok(!/->(create|update|delete|save|insert)\(|DB::table\([^)]*\)->(insert|update|delete)|config\(\[|Config::set/.test(diffPhp), 'diff script is read-only');
assert.match(diffPhp, /\$authorizer->resolve\(/);
assert.ok(!/whereNull\('status'\)|whereNotIn\('status'/.test(diffPhp), 'diff must not skip inactive users');
assert.match(diffPhp, /'inactive'/);
assert.match(diffPhp, /diff-dual-user-ids=/);
assert.match(diffPhp, /diff-narrowed-dual=/);
assert.match(diffPhp, /diff-unexpected=/);
assert.ok(!/->email|->phone|->Name|LoginName/.test(diffPhp), 'diff output must not carry PII');
assert.match(grantPhp, /SecurityAuditEvent::append/);
assert.match(grantPhp, /already-active/);
assert.match(grantPhp, /DB::transaction\(/);
assert.match(grantPhp, /audit-event-not-written/);
assert.match(grantPhp, /pilot-result=FAILED/);
assert.match(grantPhp, /PILOT_DRY_RUN/);
assert.match(grantPhp, /DRY-RUN-OK/);
assert.match(grantPhp, /config\('staff_capabilities\.multi_role_v1_enabled'/);
assert.match(grantPhp, /PILOT_FLAG_ON_ACK/);
assert.match(grantPhp, /campus-narrowing-not-acknowledged/);
assert.match(grantPhp, /PILOT_NARROW_FROM/);
// dry-run branch returns before any write
assert.ok(grantPhp.indexOf('DRY-RUN-OK') < grantPhp.indexOf('DB::transaction('), 'dry-run exits before writes');
assert.match(grantPhp, /revoked_at/);
assert.ok(!/->delete\(/.test(grantPhp), 'revoke is soft (revoked_at), never delete');

// Base64 embedding strips the leading <?php line for tinker
assert.ok(diffPhp.startsWith('<?php\n') && grantPhp.startsWith('<?php\n'));
assert.ok(wf.includes('tail -n +2 scripts/ops/staff-multirole-role-diff.php | base64 -w0'));
assert.ok(wf.includes('tail -n +2 scripts/ops/staff-multirole-pilot-grant.php | base64 -w0'));

console.log('staff-multirole-activation workflow contract: OK');

// Runbook must carry the operator-critical rules loudly.
const runbook = fs.readFileSync('docs/RUNBOOK_STAFF_MULTI_ROLE_ACTIVATION.md', 'utf8');
for (const needle of [':narrow_from=', ':flag=on', 'acknowledge_narrowing', 'expected_pilot_user_id', 'grant_pilot_dry_run', '登出', 'ROLLBACK-FAILED', 'REFUSED-BACKUP-FAILED', '範圍縮小']) {
  assert.ok(runbook.includes(needle), `runbook mentions ${needle}`);
}
console.log('runbook contract: OK');
