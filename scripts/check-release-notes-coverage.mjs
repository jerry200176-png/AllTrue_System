#!/usr/bin/env node
/**
 * Fail closed when a recent CHANGELOG entry has no staff-update decision.
 *
 * A product-facing entry must point at a STAFF_UPDATES id. Internal-only work
 * must point at an id in RELEASE_NOTES_EXEMPTIONS.yml. This keeps the explicit
 * STAFF_UPDATES source of truth while preventing silent omissions.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CHANGELOG = path.join(ROOT, 'docs', 'CHANGELOG.md');
const STAFF_UPDATES = path.join(ROOT, 'docs', 'STAFF_UPDATES.yml');
const EXEMPTIONS = path.join(ROOT, 'docs', 'RELEASE_NOTES_EXEMPTIONS.yml');
const BASELINE_DATE = '2026-08-08';
const HEADING_RE = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;
const MARKER_RE = /<!--\s*release-notes:\s*(staff_update|silent_ship)=([A-Za-z0-9._-]+)\s*-->/i;
const STAFF_FACING_PREFIXES = [
  // The SPA shell, shared clients, composables, styles, and libraries can all
  // change what a director or teacher sees, even when no page/component file
  // changes directly.
  'frontend/src/',
  // Backend application code can change the staff-visible response even when
  // the route/controller itself is unchanged.
  'backend/app/',
  'backend/routes/',
];

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

function isStaffFacingPath(file) {
  const normalized = String(file).replaceAll('\\', '/');
  if (!STAFF_FACING_PREFIXES.some((prefix) => normalized.startsWith(prefix))) return false;
  // These namespaces are control-plane/bootstrap plumbing rather than
  // director/teacher-facing application behavior.
  if (normalized.startsWith('backend/app/Console/')
    || normalized.startsWith('backend/app/Providers/')
    || normalized.startsWith('backend/app/Operations/')) return false;
  // These are generated release-note artifacts; the source-of-truth docs are
  // the communication change and should not recursively require themselves.
  if (normalized.startsWith('frontend/src/lib/') && normalized.endsWith('.generated.js')) return false;
  return !normalized.includes('/__tests__/')
    && !normalized.includes('/e2e/')
    && !/(?:\.test|\.spec)\.[^.]+$/.test(normalized);
}

function meaningfulPrField(body, label) {
  const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = String(body || '').match(new RegExp(`^\\s*\\*{0,2}${escaped}\\*{0,2}\\s*:\\s*(.+?)\\s*$`, 'im'));
  if (!match) return false;
  const value = match[1].replace(/<!--.*?-->/g, '').trim();
  return value.length > 2 && !/^<?(?:fill|tbd|n\/a|none|todo)>?$/i.test(value);
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
  assert.deepEqual(markerFor(entries[0]), { kind: 'staff_update', id: 'staff-sample' });
  assert.equal(markerFor(entries[1]), null);
  assert.equal(isStaffFacingPath('frontend/src/pages/SmartCalendar.vue'), true);
  assert.equal(isStaffFacingPath('frontend/src/App.vue'), true);
  assert.equal(isStaffFacingPath('frontend/src/api.js'), true);
  assert.equal(isStaffFacingPath('frontend/src/lib/teacherDailyWorkflow.js'), true);
  assert.equal(isStaffFacingPath('frontend/src/styles.css'), true);
  assert.equal(isStaffFacingPath('frontend/src/lib/staffUpdates.generated.js'), false);
  assert.equal(isStaffFacingPath('backend/app/Services/Scheduling/ScheduleCommitmentClassifier.php'), true);
  assert.equal(isStaffFacingPath('backend/app/Models/ClassSession.php'), true);
  assert.equal(isStaffFacingPath('backend/app/Console/Commands/NightlyReconcile.php'), false);
  assert.equal(isStaffFacingPath('backend/app/Operations/Contracts/OperationEngine.php'), false);
  assert.equal(isStaffFacingPath('frontend/src/pages/__tests__/SmartCalendar.test.js'), false);
  assert.equal(meaningfulPrField('**Where:** 排課頁面', 'Where'), true);
  assert.equal(meaningfulPrField('**How to use:** <!-- fill -->', 'How to use'), false);
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
  const bodyFile = argValue('--pr-body-file', '');
  const changelog = read(CHANGELOG);
  const staff = read(STAFF_UPDATES);
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
    if (marker.kind === 'silent_ship' && !exemptions.includes(`id: ${marker.id}`)) {
      errors.push(`${entry.date} ${entry.title}: ${marker.id} is not in docs/RELEASE_NOTES_EXEMPTIONS.yml`);
    }
  }

  const changed = changedFiles(base, head);
  const changelogChanged = changed.includes('docs/CHANGELOG.md');
  const coverageSourceChanged = changed.includes('docs/STAFF_UPDATES.yml')
    || changed.includes('docs/RELEASE_NOTES_EXEMPTIONS.yml');
  const newHeadings = addedHeadings(base, head);
  if (changelogChanged && (newHeadings.length > 0 || changed.includes('docs/CHANGELOG.md')) && !coverageSourceChanged) {
    errors.push('docs/CHANGELOG.md changed without docs/STAFF_UPDATES.yml or docs/RELEASE_NOTES_EXEMPTIONS.yml');
  }

  const staffFacingChanged = changed.some(isStaffFacingPath);
  if (staffFacingChanged) {
    if (newHeadings.length === 0) {
      errors.push('staff-facing runtime change requires a new dated docs/CHANGELOG.md entry in the same PR');
    }
    if (!changed.includes('docs/STAFF_UPDATES.yml')) {
      errors.push('staff-facing runtime change requires docs/STAFF_UPDATES.yml in the same PR');
    }
    const addedEntries = newHeadings.map((heading) => {
      const match = heading.match(HEADING_RE);
      return match ? parseEntries(changelog).find((entry) => entry.date === match[1] && entry.title === match[2]) : null;
    }).filter(Boolean);
    const staffMarkers = addedEntries.map(markerFor).filter(Boolean);
    if (!staffMarkers.some((marker) => marker.kind === 'staff_update')) {
      errors.push('staff-facing runtime change requires a release-notes: staff_update marker');
    }
    if (bodyFile) {
      const body = read(bodyFile);
      for (const field of ['Staff release note id', 'What changed', 'Where', 'How to use']) {
        if (!meaningfulPrField(body, field)) errors.push(`PR body is missing a meaningful "${field}:" field for the staff release note`);
      }
      const bodyId = body.match(/^\s*\*{0,2}Staff release note id\*{0,2}\s*:\s*([A-Za-z0-9._-]+)\s*$/im)?.[1];
      if (bodyId && !staffMarkers.some((marker) => marker.kind === 'staff_update' && marker.id === bodyId)) {
        errors.push(`PR body Staff release note id ${bodyId} does not match a new staff_update CHANGELOG marker`);
      }
    }
  }

  if (errors.length) {
    console.error('❌ Release-notes coverage gate failed:');
    for (const error of errors) console.error(`  - ${error}`);
    console.error('Add a release-notes marker to the CHANGELOG entry and update the referenced source file.');
    process.exit(1);
  }

  console.log(`✅ Release-notes coverage OK: baseline ${BASELINE_DATE}; checked ${parseEntries(changelog).filter((item) => item.date >= BASELINE_DATE).length} recent entries.`);
}

main();
