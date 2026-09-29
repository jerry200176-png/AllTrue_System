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
assert.deepEqual(Object.keys(jobs).sort(), ['disable', 'enable', 'guard', 'pilot_grant', 'preflight']);

// Inputs
assert.match(wf, /workflow_dispatch:/);
for (const a of ['preflight', 'grant_pilot', 'revoke_pilot', 'enable', 'disable']) {
  assert.ok(wf.includes(`          - ${a}\n`), `action option ${a}`);
}
for (const i of ['confirm', 'expected_head_sha', 'user_id', 'campus_id']) {
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

// Every job except guard needs guard; mutating jobs are environment-gated; preflight is read-only.
for (const [name, body] of Object.entries(jobs)) {
  if (name !== 'guard') assert.match(body, /needs: guard/, `${name} needs guard`);
}
for (const name of ['pilot_grant', 'enable', 'disable']) {
  assert.match(jobs[name], /environment: production-activation/, `${name} environment gate`);
}
assert.ok(!/environment:/.test(jobs.preflight), 'preflight is read-only, no approval needed');
assert.ok(!/sed -i|cp \.env|artisan optimize/.test(jobs.preflight), 'preflight must not edit .env or optimize');
assert.match(jobs.preflight, /READ_ONLY=true/);

// Pinned SSH trust helper in every SSH job
for (const name of ['preflight', 'pilot_grant', 'enable', 'disable']) {
  assert.match(jobs[name], /uses: \.\/\.github\/actions\/production-ssh-trust/, `${name} SSH trust`);
  assert.match(jobs[name], /Remove local key material/, `${name} key cleanup`);
}

// Never config:clear / cache:clear / optimize:clear (CLAUDE.md incident B)
assert.ok(!/config:clear|cache:clear|optimize:clear/.test(wf), 'forbidden cache clears');
for (const name of ['enable', 'disable']) {
  assert.match(jobs[name], /php artisan optimize\n/, `${name} optimize`);
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
assert.ok(en.includes('REFUSED-UNEXPECTED-DIFF') && en.includes('REFUSED-NO-PILOT-GRANTS'));
assert.ok(en.includes("grep -c '^STAFF_MULTI_ROLE_V1='") && en.includes('REFUSED-DIRTY-ENV'));
assert.ok(en.includes('cp "$BACKUP_FILE" .env'), 'restore from backup');
for (const r of ['FAILED-CONFIG-NOT-EFFECTIVE-ROLLED-BACK', 'FAILED-HEALTH-ROLLED-BACK', 'FAILED-SMOKE-ROLLED-BACK', 'FAILED-DUPLICATE-KEY-ROLLED-BACK']) {
  assert.ok(en.includes(r), `enable ${r}`);
}
assert.equal((en.match(/^\s+rollback$/gm) || []).length, 3, 'rollback called on config/health/smoke failures');
assert.ok(jobs.disable.includes("STAFF_MULTI_ROLE_V1=false") && jobs.disable.includes('cp "$BACKUP_FILE" .env'));

// Scripts: read-only diff never writes or flips config; grant script is idempotent + audited
assert.ok(!/->(create|update|delete|save|insert)\(|DB::table\([^)]*\)->(insert|update|delete)|config\(\[|Config::set/.test(diffPhp), 'diff script is read-only');
assert.match(diffPhp, /\$authorizer->resolve\(/);
assert.match(diffPhp, /diff-unexpected=/);
assert.ok(!/->email|->phone|->Name|LoginName/.test(diffPhp), 'diff output must not carry PII');
assert.match(grantPhp, /SecurityAuditEvent::append/);
assert.match(grantPhp, /already-active/);
assert.match(grantPhp, /revoked_at/);
assert.ok(!/->delete\(/.test(grantPhp), 'revoke is soft (revoked_at), never delete');

// Base64 embedding strips the leading <?php line for tinker
assert.ok(diffPhp.startsWith('<?php\n') && grantPhp.startsWith('<?php\n'));
assert.ok(wf.includes('tail -n +2 scripts/ops/staff-multirole-role-diff.php | base64 -w0'));
assert.ok(wf.includes('tail -n +2 scripts/ops/staff-multirole-pilot-grant.php | base64 -w0'));

console.log('staff-multirole-activation workflow contract: OK');
