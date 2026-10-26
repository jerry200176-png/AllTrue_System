import assert from 'node:assert/strict';
import fs from 'node:fs';

const wfPath = '.github/workflows/agreed-fee-allowlist-activation.yml';
const invPath = 'docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json';
const wf = fs.readFileSync(wfPath, 'utf8');
const inv = JSON.parse(fs.readFileSync(invPath, 'utf8'));

assert.match(wf, /^# governance-capability:\s*legacy-case-specific-mutation/m);

const jobsBlock = wf.slice(wf.indexOf('\njobs:\n'));
const jobs = {};
for (const m of jobsBlock.matchAll(/^  ([a-z_]+):\n([\s\S]*?)(?=^  [a-z_]+:\n|(?![\s\S]))/gm)) {
  jobs[m[1]] = m[2];
}
assert.deepEqual(Object.keys(jobs).sort(), [
  'clear_allowlist',
  'guard',
  'inventory',
  'set_allowlist',
  'verify',
]);

assert.match(wf, /workflow_dispatch:/);
for (const a of ['inventory', 'set_allowlist', 'clear_allowlist', 'verify']) {
  assert.ok(wf.includes(`          - ${a}\n`), `action option ${a}`);
}

const guard = jobs.guard;
assert.ok(guard.includes('"SET AGREED FEE ALLOWLIST"'));
assert.ok(guard.includes('"CLEAR AGREED FEE ALLOWLIST"'));
assert.ok(guard.includes("'^[0-9a-f]{40}$'"));
assert.match(guard, /Unknown action/);
assert.match(guard, /environment: production-activation/);

for (const [name, body] of Object.entries(jobs)) {
  if (name !== 'guard') assert.match(body, /needs: guard/, `${name} needs guard`);
}
for (const name of ['inventory', 'set_allowlist', 'clear_allowlist', 'verify']) {
  assert.match(jobs[name], /environment: production-ssh/, `${name} production-ssh`);
  assert.match(jobs[name], /uses: \.\/\.github\/actions\/production-ssh-trust/, `${name} SSH trust`);
  assert.match(jobs[name], /Remove local key material/, `${name} key cleanup`);
}

// Never flip the feature flag via .env/code write; never forbidden cache clears; never echo allowlist values.
assert.ok(!/AGREED_FEE_CORRECTION_ENABLED\s*=/.test(wf), 'must not write AGREED_FEE_CORRECTION_ENABLED');
assert.ok(!/billing\.agreed_fee_correction_enabled['"]?\s*=>\s*true/.test(wf), 'must not hard-enable flag in workflow');
assert.ok(!/config\(\s*\[\s*['"]billing\.agreed_fee_correction_enabled['"]\s*=>\s*true/.test(wf), 'must not config-set flag true');
assert.ok(!/config:clear|cache:clear|optimize:clear/.test(wf), 'forbidden cache clears');
// Must never echo/cat/printenv the secret *values* (variable expansion). Naming the keys in error text is OK.
assert.ok(!/\b(?:echo|cat|printenv)\b[^\n]*\$\{?AGREED_FEE_CORRECTION_(?:CAMPUS|COURSE)_IDS\b/.test(wf), 'must not echo allowlist secret values');

for (const name of ['set_allowlist', 'clear_allowlist']) {
  assert.match(jobs[name], /php artisan optimize/, `${name} optimize`);
  assert.match(jobs[name], /opcache:reset/, `${name} opcache`);
  assert.match(jobs[name], /sha256sum/, `${name} backup checksum`);
  assert.match(jobs[name], /api\/v1\/health/, `${name} health`);
  assert.match(jobs[name], /REFUSED-WRONG-HEAD/, `${name} HEAD gate`);
  assert.ok(jobs[name].includes("config('billing.agreed_fee_correction_enabled')"), `${name} verifies flag`);
}

assert.match(jobs.set_allowlist, /send_env/);
assert.match(jobs.set_allowlist, /secrets\.AGREED_FEE_CORRECTION_CAMPUS_IDS/);
assert.match(jobs.set_allowlist, /secrets\.AGREED_FEE_CORRECTION_COURSE_IDS/);
assert.match(jobs.set_allowlist, /set-result=SUCCESS/);
assert.match(jobs.clear_allowlist, /clear-result=SUCCESS/);
assert.match(jobs.inventory, /READ_ONLY=true/);
assert.match(jobs.verify, /READ_ONLY=true/);
assert.ok(!/sed -i|cp \.env|artisan optimize/.test(jobs.inventory), 'inventory must not edit .env or optimize');
assert.ok(!/sed -i|cp \.env|artisan optimize/.test(jobs.verify), 'verify must not edit .env or optimize');

const entry = inv.workflows['agreed-fee-allowlist-activation.yml'];
assert.ok(entry, 'inventory entry required');
assert.equal(entry.classification, 'legacy-case-specific-mutation');
assert.equal(entry.production_mutation, 'confirmed');
assert.match(entry.production_command_or_path, /AGREED_FEE_CORRECTION_/);
assert.match(entry.authorization, /Founder/);

console.log('agreed-fee-allowlist-activation-workflow: ok');
