import assert from 'node:assert/strict';
import fs from 'node:fs';

const wf = fs.readFileSync('.github/workflows/staff-identity-merge.yml', 'utf8');
const cmd = fs.readFileSync('backend/app/Console/Commands/StaffIdentityMergeCommand.php', 'utf8');
const svc = fs.readFileSync('backend/app/Services/StaffIdentityMergeService.php', 'utf8');
const inv = JSON.parse(fs.readFileSync('docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json', 'utf8'));

const jobs = {};
for (const m of wf.slice(wf.indexOf('\njobs:\n')).matchAll(/^  ([a-z_]+):\n([\s\S]*?)(?=^  [a-z_]+:\n|(?![\s\S]))/gm)) jobs[m[1]] = m[2];
assert.deepEqual(Object.keys(jobs).sort(), ['guard', 'run'], 'exact job set');
assert.equal((wf.match(/^          - [a-z_]+$/gm) || []).length, 2, 'exactly candidates + dry_run');
assert.ok(!/execute|apply|revert|--force|environment:/i.test(wf.replace(/^#.*$/gm, '')), 'no write path, no approval env');
assert.ok(!/--execute|--apply/.test(cmd), 'artisan command has no write option');
assert.ok(!/config:clear|cache:clear|optimize/.test(wf), 'no cache-clearing artisan command');
assert.match(wf, /^# governance-capability: read-only-probe/);
assert.match(wf, /concurrency:\n  group: staff-identity-merge\n  cancel-in-progress: false/);

const { guard, run } = jobs;
assert.ok(guard.includes("'^[1-9][0-9]{0,9}$'") && guard.includes("'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'") && /Unknown action/.test(guard));
for (const re of [/needs: guard/, /uses: \.\/\.github\/actions\/production-ssh-trust/, /Remove local key material/, /READ_ONLY=true/, /retention-days: 90/,
  /staff:identity-merge --candidates/, /staff:identity-merge --dry-run --survivor="\$SURVIVOR" --retired="\$RETIRED" --cutover="\$CUTOVER"/]) assert.match(run, re);
assert.ok(/ENDSSH' \| grep -viE 'password\|secret\|passwd\|PSW=\|bearer\|private key\|DB_PASS' \| tee \/tmp\/audit\.txt/.test(run), 'secrets filtered before log + artifact');
const heredoc = run.slice(run.indexOf("<< 'ENDSSH'"), run.indexOf('\n          ENDSSH'));
assert.ok(heredoc.length > 10 && !heredoc.includes('${{'), 'no Actions expression in remote script');
for (const body of Object.values(jobs)) {
  for (const m of body.matchAll(/^ {8}run: \|\n((?: {10}.*\n|\n)+)/gm)) assert.ok(!/\$\{\{\s*(github\.event\.)?inputs\./.test(m[1]), 'inputs only via env');
}

assert.ok(!/->(insert|insertOrIgnore|update|delete|truncate|upsert|save|create)\(|DB::(statement|insert|update|delete|unprepared)|Schema::(create|drop|table)\(/.test(svc), 'service must not write');
assert.equal(inv.workflows['staff-identity-merge.yml']?.classification, 'read-only-probe', 'inventory registration');
console.log('staff-identity-merge-workflow.test.mjs OK');
