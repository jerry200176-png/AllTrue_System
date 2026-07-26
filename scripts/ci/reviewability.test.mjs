import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  classifyNumstat, evaluateReviewability, resolveDiffBase, matchFounderException, isHighRiskPath,
} from './diff-classifier.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

test('stacked base prefers PR_BASE_SHA', () => {
  assert.equal(resolveDiffBase({ env: { PR_BASE_SHA: 'abc' } }), 'abc');
  assert.equal(resolveDiffBase({ env: { GITHUB_BASE_REF: 'feat/base' } }), 'origin/feat/base');
});

test('vertical UI slice under hard score; monolithic high-risk blocks', () => {
  const slice = [
    '220\t20\tfrontend/src/pages/DirectorDashboard.vue',
    '180\t0\tfrontend/e2e/ui-foundation-pages.spec.js',
    '100\t0\tdocs/design/ALLTRUE_UI_FOUNDATION.md',
    '50\t0\tfrontend/src/lib/releaseNotes.generated.js',
  ].join('\n');
  const e = evaluateReviewability(classifyNumstat(slice));
  assert.equal(e.ok, true);
  assert.ok(e.score < 400);

  const lines = [];
  for (let i = 0; i < 20; i += 1) lines.push(`40\t0\tbackend/database/migrations/m${i}.php`);
  lines.push('500\t100\tbackend/app/Http/Controllers/Auth/LoginController.php');
  const hard = evaluateReviewability(classifyNumstat(lines.join('\n')));
  assert.equal(hard.ok, false);
  assert.ok(isHighRiskPath('backend/database/migrations/x.php'));
});

test('Founder exception binds PR+SHA+check; rejects incomplete ledger', () => {
  const good = {
    pr_number: 1450, head_sha: 'abc', waived_check: 'reviewability',
    why_unsplittable: 'single vertical UI foundation', independent_review_evidence: 'Founder review note',
    rollback: 'revert PR', expires_at: '2099-01-01T00:00:00Z',
  };
  assert.equal(matchFounderException(good, { prNumber: 1450, headSha: 'abc', checkId: 'reviewability' }).ok, true);
  assert.equal(matchFounderException(good, { prNumber: 1450, headSha: 'ddd', checkId: 'reviewability' }).ok, false);
  assert.equal(matchFounderException({ ...good, why_unsplittable: '' }, { prNumber: 1450, headSha: 'abc', checkId: 'reviewability' }).ok, false);
});

/** Historical replay fixtures — expected direction, not hard-coded greenwash. */
test('historical replay fixtures', () => {
  const fixtures = JSON.parse(fs.readFileSync(path.join(root, 'scripts/ci/fixtures/historical-replay.json'), 'utf8'));
  for (const row of fixtures) {
    const e = evaluateReviewability(classifyNumstat(row.numstat));
    if (row.expect === 'block') assert.equal(e.ok, false, row.id);
    else if (row.expect === 'pass') assert.equal(e.ok, true, row.id);
    else if (row.expect === 'warn_or_pass') assert.ok(e.ok || e.warnings.length, row.id);
    if (row.require_high_risk_block) {
      assert.ok(e.blockers.some((b) => b.reason === 'high_risk_lines' || b.reason === 'production_code_lines'), row.id);
    }
  }
});

test('concurrency policy: deploy/repair must not cancel-in-progress true', () => {
  const dir = path.join(root, '.github/workflows');
  const dangerous = [];
  for (const name of fs.readdirSync(dir).filter((n) => n.endsWith('.yml'))) {
    const t = fs.readFileSync(path.join(dir, name), 'utf8');
    const isProd = /deploy|password|credential|repair|backup-restore|rotation/i.test(name);
    if (!isProd) continue;
    const m = t.match(/cancel-in-progress:\s*([^\n#]+)/);
    if (m && m[1].trim() === 'true') dangerous.push(name);
  }
  assert.deepEqual(dangerous, []);
});
