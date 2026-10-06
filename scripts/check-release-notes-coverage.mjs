#!/usr/bin/env node
/**
 * Fail closed when a recent CHANGELOG entry has no staff-update decision.
 *
 * A product-facing entry must point at a staff-update id (docs/STAFF_UPDATES.yml or
 * docs/staff-updates/*.yml). Internal-only work must point at an id in
 * RELEASE_NOTES_EXEMPTIONS.yml, or carry its own `<!-- silent-reason: ... -->` line
 * (fragment entries under docs/changes/). Entries come from docs/CHANGELOG.md + docs/changes/*.md. This keeps the explicit
 * STAFF_UPDATES source of truth while preventing silent omissions.
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

  if (errors.length) {
    console.error('❌ Release-notes coverage gate failed:');
    for (const error of errors) console.error(`  - ${error}`);
    console.error('Add a release-notes marker to the CHANGELOG entry and update the referenced source file.');
    process.exit(1);
  }

  console.log(`✅ Release-notes coverage OK: baseline ${BASELINE_DATE}; checked ${parseEntries(changelog).filter((item) => item.date >= BASELINE_DATE).length} recent entries.`);
}

main();
