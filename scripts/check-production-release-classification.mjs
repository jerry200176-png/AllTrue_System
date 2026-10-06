#!/usr/bin/env node
/**
 * Require every deployable PR to declare its user-facing release impact.
 * The declaration is deliberately small; the docs/changes + docs/staff-updates
 * fragment pipeline (plus the frozen CHANGELOG/STAFF_UPDATES) remains the publication path.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const IMPACT_RE = /^\s*Release-Impact:\s*(user-visible|internal|no-user-facing-note)\s*$/gim;
const RUNTIME_PREFIXES = [
  'backend/app/',
  'backend/routes/',
  'backend/config/',
  'backend/database/migrations/',
  'frontend/src/',
  'frontend/public/',
  '.github/workflows/',
  'operations/',
  'scripts/production',
];
const NON_RUNTIME_PREFIXES = [
  'backend/tests/',
  'frontend/src/**/*.test.',
  'frontend/e2e/',
  'scripts/tests/',
  'operations/closeout/',
];

function git(args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' });
}

function changedFiles(base, head) {
  return git(['diff', '--name-only', `${base}...${head}`])
    .split(/\r?\n/)
    .map((file) => file.trim())
    .filter(Boolean);
}

function isRuntimePath(file) {
  if (NON_RUNTIME_PREFIXES.some((prefix) => file.startsWith(prefix))) return false;
  return RUNTIME_PREFIXES.some((prefix) => file.startsWith(prefix));
}

function parseImpact(body) {
  const matches = [...String(body || '').matchAll(IMPACT_RE)].map((match) => match[1].toLowerCase());
  return [...new Set(matches)];
}

function validate({ body, files }) {
  const runtimeFiles = files.filter(isRuntimePath);
  if (runtimeFiles.length === 0) return { ok: true, runtimeFiles, impact: null, errors: [] };

  const impacts = parseImpact(body);
  const errors = [];
  if (impacts.length !== 1) {
    errors.push('exactly one Release-Impact declaration is required: user-visible, internal, or no-user-facing-note');
  }
  const impact = impacts[0] || null;
  if (impact === 'user-visible' && !files.some((f) => f === 'docs/CHANGELOG.md' || /^docs\/changes\/\d{4}-\d{2}-\d{2}-.+\.md$/.test(f))) {
    errors.push('user-visible production changes must add a docs/changes/<date>-<slug>.md fragment before merge');
  }
  if ((impact === 'internal' || impact === 'no-user-facing-note') && files.some((f) => f === 'docs/STAFF_UPDATES.yml' || f.startsWith('docs/staff-updates/'))) {
    errors.push(impact + ' changes must not publish a staff Version Update');
  }
  return { ok: errors.length === 0, runtimeFiles, impact, errors };
}

function selfTest() {
  assert.equal(validate({ body: 'Release-Impact: user-visible', files: ['frontend/src/App.vue', 'docs/CHANGELOG.md'] }).ok, true);
  assert.equal(validate({ body: 'Release-Impact: internal', files: ['backend/app/Services/Foo.php'] }).ok, true);
  assert.equal(validate({ body: 'Release-Impact: no-user-facing-note', files: ['.github/workflows/ci.yml'] }).ok, true);
  assert.equal(validate({ body: 'Release-Impact: user-visible', files: ['frontend/src/App.vue'] }).ok, false);
  assert.equal(validate({ body: 'Release-Impact: user-visible', files: ['frontend/src/App.vue', 'docs/changes/2026-10-06-x.md'] }).ok, true);
  assert.equal(validate({ body: 'Release-Impact: internal', files: ['backend/app/Foo.php', 'docs/staff-updates/staff-x.yml'] }).ok, false);
  assert.equal(validate({ body: 'Release-Impact: internal\nRelease-Impact: no-user-facing-note', files: ['backend/app/Foo.php'] }).ok, false);
  assert.equal(validate({ body: '', files: ['docs/CHANGELOG.md'] }).ok, true);
  console.log('check-production-release-classification self-test: ok');
}

function main() {
  if (process.argv.includes('--self-test')) return selfTest();
  const arg = (name, fallback) => {
    const index = process.argv.indexOf(name);
    return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
  };
  const base = arg('--base', 'origin/main');
  const head = arg('--head', 'HEAD');
  const bodyFile = arg('--body-file', null);
  const body = bodyFile && fs.existsSync(bodyFile) ? fs.readFileSync(bodyFile, 'utf8') : '';
  const result = validate({ body, files: changedFiles(base, head) });
  console.log(JSON.stringify({ runtime_files: result.runtimeFiles, impact: result.impact, ok: result.ok }));
  if (!result.ok) {
    for (const error of result.errors) console.error('❌ Release impact gate: ' + error);
    process.exit(1);
  }
  console.log('✅ Production release impact classification is explicit.');
}

main();
