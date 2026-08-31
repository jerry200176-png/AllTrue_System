/**
 * Staff = STAFF_UPDATES.yml; parent = PARENT_UPDATES.yml (R45).
 * CHANGELOG drafts must never auto-publish via notesForRole.
 */
import assert from 'assert';
import { changelogDraftNotes } from './changelogDraft.generated.js';
import { staffUpdates } from './staffUpdates.generated.js';
import {
  latestReleaseVersionForRole,
  listActiveParentUpdates,
  notesForRole,
  parentReleaseNoteTeaser,
  allParentUpdates,
  allStaffUpdates,
} from './releaseNotes.js';
import { findUserFacingCopyIssues } from '../../../scripts/lib/userFacingCopyGate.mjs';

assert.strictEqual(allStaffUpdates, staffUpdates);
assert.ok(notesForRole('director').length >= 1);
assert.ok(notesForRole('teacher').length > 0);
assert.ok(notesForRole('super_admin').length >= notesForRole('director').length);
assert.equal(notesForRole('admin').length, notesForRole('super_admin').length);
assert.ok(latestReleaseVersionForRole('super_admin'));

const latest = notesForRole('director')[0];
assert.ok(/^\d+\.\d+\.\d+$/.test(latest.version));
assert.ok(latest.id && latest.publishedAt && latest.sections?.length);
assert.ok(['digest', 'major', 'action_required'].includes(latest.importance));
  assert.strictEqual(latest.publishedAt, '2026-08-31');
  assert.strictEqual(latest.id, 'staff-2026-08-31-weekly-16-segments');
  for (const id of [
    'staff-2026-08-27-teacher-assessment-engagement',
    'staff-2026-08-26-bug-report-image-paste',
    'staff-2026-08-19-receipt-line-clarity',
    'staff-2026-08-19-accounting-receipt-pin-gap',
    'staff-2026-08-17-lr-batch-approve-perf',
    'staff-2026-08-17-fillrate-completed',
    'staff-2026-08-17-projected-ordinal',
    'staff-2026-08-17-course-memo-length-1732',
    'staff-2026-08-17-ui-jargon-w2',
    'staff-2026-08-17-ui-human-copy-sweep',
    'staff-2026-08-17-billing-scan-density',
    'staff-2026-08-17-billing-human-copy',
    'staff-2026-08-17-tuition-collect-tdz',
    'staff-2026-08-16-hai-sen-director-copy',
    'staff-2026-08-16-director-confirms',
    'staff-2026-08-16-billing-tabs-visible',
    'staff-2026-08-16-billing-ux-find',
    'staff-2026-08-16-fulltime-payroll-lock',
    'staff-2026-08-16-reported-paid-phase2',
    'staff-2026-08-16-reported-paid-notif',
    'staff-2026-08-16-fulltime-settlement-table',
    'staff-2026-08-16-reported-paid-pending',
    'staff-2026-08-14-monthly-copy-teacher-list',
    'staff-2026-08-16-fillrate-substitute-absent-copy',
    'staff-2026-08-16-lr-resurrect-status-adjust',
    'staff-2026-08-15-tuition-charge-display-1734',
    'staff-2026-08-15-teacher-home-projected-campus',
    'staff-2026-08-15-eligibility-approved-revert',
    'staff-2026-08-14-eligibility-pending-edit',
    'staff-2026-08-13-session-entitlement-transfer-command',
    'staff-2026-08-12-in-app-bug-fixes',
  'staff-2026-08-09-payroll-director-rules-v2',
  'staff-2026-08-08-makeup-candidate-date-fix',
  'staff-2026-08-08-calendar-stability',
  'staff-2026-08-08-leave-review-integrity',
  'staff-2026-08-08-payroll-eligibility',
]) {
  assert.ok(notesForRole('director').some((note) => note.id === id), id);
}

const directorNotes = notesForRole('director');
for (let i = 1; i < directorNotes.length; i++) {
  assert.ok(directorNotes[i - 1].publishedAt >= directorNotes[i].publishedAt);
}

const blob = JSON.stringify(latest);
assert.ok(!/Controller|Service|IsContractException|Phase\s*\d|pool coverage|DS tokens?/i.test(blob));

for (const note of staffUpdates) {
  assert.ok(!(note.audiences || []).includes('parent'), note.id);
}

assert.ok(Array.isArray(changelogDraftNotes));
assert.ok(!directorNotes.some((n) => n.draft || String(n.title || '').includes('草稿')));
if (changelogDraftNotes.length >= 2) {
  assert.ok(changelogDraftNotes[0].date >= changelogDraftNotes[1].date);
}

for (const phrase of [
  '55 復活判斷收斂為單一共用政策',
  '006 Phase 3A pool coverage planner',
  '調課標記 IsContractException',
  '頁改吃 DS tokens',
  'Continuity 群組 MVP',
]) {
  assert.ok(findUserFacingCopyIssues(phrase).length > 0, phrase);
}

assert.strictEqual(listActiveParentUpdates({ now: new Date('2099-01-01T12:00:00'), limit: 2 }).length, 0);

const parentNotes = listActiveParentUpdates({ now: new Date('2026-07-27T12:00:00'), limit: 2 });
assert.ok(parentNotes.length >= 1);
const p0 = parentNotes[0];
assert.ok(p0.id && p0.title && p0.summary && p0.details && p0.publishedAt && p0.expiresAt);
assert.ok(['improvement', 'policy', 'resolved_issue'].includes(p0.kind));
assert.ok(parentReleaseNoteTeaser(p0));
assert.ok(!parentReleaseNoteTeaser({ summary: '', items: ['staff only'], title: 'x' }));
assert.ok(!/staff-2026-07-week-30|調課後課表不再跳回/.test(JSON.stringify(parentNotes)));

for (const n of notesForRole('parent')) {
  assert.ok(n.summary && !n.sections && !n.importance);
}

assert.ok(Array.isArray(allParentUpdates));
assert.ok(
  !listActiveParentUpdates({ now: new Date('2026-08-26T12:00:00'), limit: 10 })
    .some((u) => u.id === 'parent-update-2026-07-26-leave'),
);

console.log('releaseNotes.test.js: ok');
