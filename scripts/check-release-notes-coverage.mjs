#!/usr/bin/env node
/**
 * Fail closed when a recent CHANGELOG entry has no staff-update decision.
 *
 * A product-facing entry must point at a staff-update id (docs/STAFF_UPDATES.yml or
 * docs/staff-updates/*.yml). Internal-only work must point at an id in
 * RELEASE_NOTES_EXEMPTIONS.yml, or carry its own `<!-- silent-reason: ... -->` line
 * (fragment entries under docs/changes/). Entries come from docs/CHANGELOG.md + docs/changes/*.md. This keeps the explicit
 * STAFF_UPDATES source of truth while preventing silent omissions.
 *
 * Silent ship on UI/API code (PR context only): a docs/changes fragment ADDED in base...head that uses silent_ship fails
 * when the same diff touches user-facing code (non-test frontend/src, backend/app/Http/Controllers, backend/routes),
 * unless the PR carries the label `release-note-none` (Kubernetes-style `release-note-none`, checked by the reviewer
 * against the one-line reason in the PR body). The workflow passes PR_NUMBER and PR_LABELS (newline-separated, read
 * with `gh api repos/$REPO/pulls/$PR_NUMBER --jq '.labels[].name'`); without PR_NUMBER (land-queue batch, local
 * runs) this rule is skipped and the existing checks still run.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readChangelogText, readUpdatesYaml } from './lib/changeFragments.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EXEMPTIONS = path.join(ROOT, 'docs', 'RELEASE_NOTES_EXEMPTIONS.yml');
const BASELINE_DATE = '2026-08-08';
const HEADING_RE = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;
const MARKER_RE = /<!--\s*release-notes:\s*(staff_update|silent_ship)=([A-Za-z0-9._-]+)\s*-->/i;

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function parseEntries(markdown) {
  const lines = String(markdown).split(/\r?\n/);
  const entries = [];
  for (let i = 0; i < lines.length; i += 1) {
    const match = lines[i].match(HEADING_RE);
    if (!match) continue;
    let end = lines.length;
    for (let j = i + 1; j < lines.length; j += 1) {
      if (HEADING_RE.test(lines[j])) { end = j; break; }
    }
    entries.push({ date: match[1], title: match[2], body: lines.slice(i + 1, end).join('\n') });
  }
  return entries;
}

const REASON_RE = /<!--\s*silent-reason:\s*(\S.*?)\s*-->/i;

function markerFor(entry) {
  const match = entry.body.match(MARKER_RE);
  return match ? { kind: match[1].toLowerCase(), id: match[2] } : null;
}

function git(args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' });
}

function changedFiles(base, head) {
  return git(['diff', '--name-only', `${base}...${head}`]).split(/\r?\n/).filter(Boolean);
}

function addedHeadings(base, head) {
  return git(['diff', '--unified=0', `${base}...${head}`, '--', 'docs/CHANGELOG.md'])
    .split(/\r?\n/)
    .filter((line) => line.startsWith('+## '))
    .map((line) => line.slice(1).trim())
    .filter((line) => HEADING_RE.test(line));
}

const RELEASE_NOTE_NONE_LABEL = 'release-note-none';
const FRAGMENT_PATH_RE = /^docs\/changes\/\d{4}-\d{2}-\d{2}-.+\.md$/;

/** UI or API code staff can notice: non-test frontend/src, HTTP controllers, routes. */
function isUserFacingCode(file) {
  if (file.startsWith('frontend/src/')) {
    const base = file.slice(file.lastIndexOf('/') + 1);
    return !file.includes('/__tests__/') && !/\.(test|spec)\./.test(base);
  }
  return file.startsWith('backend/app/Http/Controllers/') || file.startsWith('backend/routes/');
}

/**
 * @param {{ fragments: { file: string, text: string }[], files: string[], labels: string[] }} input
 * @returns {string[]} one error per silent_ship marker in an added fragment, when the diff touches user-facing code
 *   and the PR lacks the release-note-none label
 */
function silentShipUserFacingErrors({ fragments, files, labels }) {
  if (labels.includes(RELEASE_NOTE_NONE_LABEL)) return [];
  const userFacing = files.filter(isUserFacingCode);
  if (userFacing.length === 0) return [];
  const shown = userFacing.slice(0, 5).join(', ') + (userFacing.length > 5 ? `, … (+${userFacing.length - 5})` : '');
  const errors = [];
  for (const { file, text } of fragments) {
    for (const entry of parseEntries(text)) {
      const marker = markerFor(entry);
      if (marker?.kind !== 'silent_ship') continue;
      errors.push(`${file}: silent_ship=${marker.id} but this PR changes UI or API code (${shown}). `
        + `Add a docs/staff-updates/<id>.yml card and switch the marker to staff_update=<id>, `
        + `or add the PR label ${RELEASE_NOTE_NONE_LABEL} with a one-line reason in the PR body, then re-run presubmit.`);
    }
  }
  return errors;
}

function readAddedFragments(base, head) {
  return git(['diff', '--name-only', '--diff-filter=A', `${base}...${head}`, '--', 'docs/changes/'])
    .split(/\r?\n/)
    .filter((file) => FRAGMENT_PATH_RE.test(file))
    .map((file) => ({ file, text: git(['show', `${head}:${file}`]) }));
}

function parseLabels(raw) {
  return String(raw || '').split(/[\r\n,]+/).map((label) => label.trim()).filter(Boolean);
}

function selfTest() {
  const entries = parseEntries([
    '## 2026-08-08 — fix: sample',
    '<!-- release-notes: staff_update=staff-sample -->',
    '- body',
    '',
    '## 2026-08-07 — docs: old',
    '- body',
  ].join('\n'));
  assert.equal(entries.length, 2);
  assert.match('<!-- silent-reason: 內部重構 -->', REASON_RE);
  assert.deepEqual(markerFor(entries[0]), { kind: 'staff_update', id: 'staff-sample' });
  assert.equal(markerFor(entries[1]), null);

  // Silent ship on UI/API code needs release-note-none.
  const silent = {
    file: 'docs/changes/2026-10-23-sample.md',
    text: [
      '## 2026-10-23 — fix: sample',
      '<!-- release-notes: silent_ship=silent-2026-10-23-sample -->',
      '<!-- silent-reason: 內部調整 -->',
      '- body',
    ].join('\n'),
  };
  const staffUpdate = {
    file: 'docs/changes/2026-10-23-visible.md',
    text: ['## 2026-10-23 — fix: visible', '<!-- release-notes: staff_update=staff-2026-10-23-visible -->', '- body'].join('\n'),
  };
  const run = (fragments, files, labels = []) => silentShipUserFacingErrors({ fragments, files, labels });

  const frontendErrors = run([silent], [silent.file, 'frontend/src/pages/StudentsList.vue']);
  assert.equal(frontendErrors.length, 1, 'silent fragment + frontend file fails');
  assert.match(frontendErrors[0], /silent_ship=silent-2026-10-23-sample/);
  assert.match(frontendErrors[0], /frontend\/src\/pages\/StudentsList\.vue/);
  assert.match(frontendErrors[0], /docs\/staff-updates\/<id>\.yml/);
  assert.match(frontendErrors[0], /staff_update=<id>/);
  assert.match(frontendErrors[0], /release-note-none/);
  assert.match(frontendErrors[0], /reason in the PR body/);

  assert.deepEqual(run([silent], [
    silent.file,
    'frontend/src/lib/__tests__/StudentsList.test.js',
    'frontend/src/lib/courseMoneyState.test.js',
    'frontend/src/components/AtButton.spec.js',
  ]), [], 'silent fragment + test-only frontend change passes');

  assert.equal(run([silent], [silent.file, 'backend/app/Http/Controllers/SubjectController.php']).length, 1,
    'silent fragment + Controllers change fails');
  assert.equal(run([silent], [silent.file, 'backend/routes/api.php']).length, 1, 'silent fragment + routes change fails');

  assert.deepEqual(run([silent], [silent.file, 'backend/app/Http/Controllers/SubjectController.php'], ['queue', 'release-note-none']), [],
    'Controllers change with release-note-none passes');
  assert.deepEqual(run([silent], [silent.file, 'frontend/src/pages/StudentsList.vue'], ['release-note-none']), [],
    'frontend change with release-note-none passes');
  assert.equal(run([silent], [silent.file, 'frontend/src/pages/StudentsList.vue'], ['release-note-none-pending']).length, 1,
    'only the exact label counts');

  assert.deepEqual(run([staffUpdate], [staffUpdate.file, 'frontend/src/pages/StudentsList.vue']), [],
    'staff_update fragment + frontend change passes');
  assert.deepEqual(run([silent], [silent.file, 'backend/app/Services/PackageDeductionService.php', 'backend/tests/Feature/XTest.php',
    '.github/workflows/presubmit.yml', 'docs/GUIDE_STAFF_UPDATES.md']), [], 'silent fragment + non-UI/API change passes');
  assert.deepEqual(run([], ['frontend/src/pages/StudentsList.vue']), [], 'no added fragment: nothing to judge here');
  assert.equal(run([silent, staffUpdate], ['frontend/src/App.vue']).length, 1, 'one error per silent marker, not per fragment');

  assert.equal(isUserFacingCode('frontend/src/pages/App.vue'), true);
  assert.equal(isUserFacingCode('frontend/src/lib/testHelpers.js'), true, 'a name containing "test" is still user-facing');
  assert.equal(isUserFacingCode('frontend/e2e/subject-settings.spec.js'), false);
  assert.equal(isUserFacingCode('backend/app/Http/Middleware/RequireCampus.php'), false);
  assert.deepEqual(parseLabels('queue\nrelease-note-none\n'), ['queue', 'release-note-none']);
  assert.deepEqual(parseLabels(''), []);
  console.log('check-release-notes-coverage self-test: ok');
}

function main() {
  if (process.argv.includes('--self-test')) {
    selfTest();
    return;
  }

  const argValue = (name, fallback) => {
    const index = process.argv.indexOf(name);
    return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
  };
  const base = argValue('--base', 'origin/main');
  const head = argValue('--head', 'HEAD');
  const changelog = readChangelogText(ROOT);
  const staff = readUpdatesYaml(ROOT, 'STAFF_UPDATES.yml', 'docs/staff-updates');
  const exemptions = fs.existsSync(EXEMPTIONS) ? read(EXEMPTIONS) : '';
  const errors = [];

  for (const entry of parseEntries(changelog).filter((item) => item.date >= BASELINE_DATE)) {
    const marker = markerFor(entry);
    if (!marker) {
      errors.push(`${entry.date} ${entry.title}: missing release-notes marker`);
      continue;
    }
    if (marker.kind === 'staff_update' && !staff.includes(`id: ${marker.id}`)) {
      errors.push(`${entry.date} ${entry.title}: ${marker.id} is not in docs/STAFF_UPDATES.yml`);
    }
    if (marker.kind === 'silent_ship' && !exemptions.includes(`id: ${marker.id}`) && !REASON_RE.test(entry.body)) {
      errors.push(`${entry.date} ${entry.title}: ${marker.id} is not in docs/RELEASE_NOTES_EXEMPTIONS.yml and has no <!-- silent-reason: ... --> line`);
    }
  }

  const changed = changedFiles(base, head);
  const changelogChanged = changed.includes('docs/CHANGELOG.md');
  const coverageSourceChanged = changed.includes('docs/STAFF_UPDATES.yml')
    || changed.includes('docs/RELEASE_NOTES_EXEMPTIONS.yml')
    || changed.some((f) => f.startsWith('docs/staff-updates/'));
  const newHeadings = addedHeadings(base, head);
  if (changelogChanged && (newHeadings.length > 0 || changed.includes('docs/CHANGELOG.md')) && !coverageSourceChanged) {
    errors.push('docs/CHANGELOG.md changed without a staff-update or exemption source (prefer a new docs/changes/*.md fragment instead of editing the frozen CHANGELOG)');
  }

  const prNumber = String(process.env.PR_NUMBER || '').trim();
  const silentErrors = prNumber
    ? silentShipUserFacingErrors({ fragments: readAddedFragments(base, head), files: changed, labels: parseLabels(process.env.PR_LABELS) })
    : [];
  if (!prNumber) console.log('Silent-ship UI/API rule skipped: no PR context (PR_NUMBER unset).');

  if (errors.length) {
    console.error('❌ Release-notes coverage gate failed:');
    for (const error of errors) console.error(`  - ${error}`);
    console.error('Add a release-notes marker to the CHANGELOG entry and update the referenced source file.');
  }
  if (silentErrors.length) {
    console.error(`❌ Silent ship on UI/API code needs a staff card or the ${RELEASE_NOTE_NONE_LABEL} label (PR #${prNumber}):`);
    for (const error of silentErrors) console.error(`  - ${error}`);
    console.error('Labels are read live; presubmit does not re-run on label events, so re-run this job after adding the label.');
  }
  if (errors.length || silentErrors.length) process.exit(1);

  console.log(`✅ Release-notes coverage OK: baseline ${BASELINE_DATE}; checked ${parseEntries(changelog).filter((item) => item.date >= BASELINE_DATE).length} recent entries.`);
}

main();
