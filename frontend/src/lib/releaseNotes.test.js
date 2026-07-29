/**
 * Mirrors calendar tests: runnable with plain Node (npm run test:release-notes).
 * Staff updates = explicit STAFF_UPDATES.yml; parent = PARENT_UPDATES.yml (R45).
 * CHANGELOG drafts must never auto-publish to notesForRole.
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

// ─── Staff STAFF_UPDATES cards ───────────────────────────────────────
assert.ok(notesForRole('director').length >= 1, 'director should see at least one staff update');
assert.ok(notesForRole('teacher').length > 0, 'teacher should see release entries');
assert.strictEqual(allStaffUpdates, staffUpdates, 'allStaffUpdates aliases generated catalog');

const directorCount = notesForRole('director').length;
assert.strictEqual(notesForRole('super_admin').length, directorCount, 'super_admin should match director-facing notes');
assert.strictEqual(notesForRole('admin').length, directorCount, 'admin should match director-facing notes');

assert.ok(latestReleaseVersionForRole('super_admin').length > 0, 'version nudge needs a stable version key');

const latest = notesForRole('director')[0];
assert.ok(/^\d+\.\d+\.\d+$/.test(latest.version), 'release notes should use calendar version labels');
assert.ok(Array.isArray(latest.sections) && latest.sections.length > 0, 'release cards should have grouped sections');
assert.ok(latest.id && latest.publishedAt && latest.importance, 'staff card needs id/publishedAt/importance');
assert.ok(['digest', 'major', 'action_required'].includes(latest.importance), 'importance allowlist');

// published_at DESC: newest first (fixture: 2026-07-29 ahead of 2026-07-28 / 2026-07-24)
assert.strictEqual(latest.publishedAt, '2026-07-29', 'latest staff card must be newest published_at');
for (let i = 1; i < notesForRole('director').length; i++) {
  const prev = notesForRole('director')[i - 1].publishedAt;
  const cur = notesForRole('director')[i].publishedAt;
  assert.ok(prev >= cur, `staff notes must be published_at DESC (${prev} >= ${cur})`);
}

const userFacingText = JSON.stringify(latest);
assert.ok(!/Controller|Service|\.vue|\.php|GET\s+\/|POST\s+\/|::/.test(userFacingText), 'release notes should avoid technical implementation terms');
assert.ok(!/IsContractException|Phase\s*\d|pool coverage|DS tokens?/i.test(userFacingText), 'staff cards must not ship engineering jargon');

// Staff cards must never carry parent audience.
for (const note of staffUpdates) {
  assert.ok(
    !(note.audiences || note.audience || []).includes('parent'),
    `staff update ${note.id} must not include audience:parent`,
  );
}

// CHANGELOG draft must not feed notesForRole
assert.ok(Array.isArray(changelogDraftNotes), 'changelog draft artifact exists for AI curation');
assert.ok(
  !notesForRole('director').some((n) => n.draft === true || String(n.title || '').includes('草稿')),
  'notesForRole must not expose CHANGELOG drafts',
);
if (changelogDraftNotes.length >= 2) {
  for (let i = 1; i < changelogDraftNotes.length; i++) {
    assert.ok(
      changelogDraftNotes[i - 1].date >= changelogDraftNotes[i].date,
      'changelog draft must be date DESC regardless of CHANGELOG source order',
    );
  }
}

// Language gate rejects known bad phrases
const badPhrases = [
  '55 復活判斷收斂為單一共用政策',
  '006 Phase 3A pool coverage planner',
  '調課標記 IsContractException',
  '頁改吃 DS tokens',
  'Continuity 群組 MVP',
];
for (const phrase of badPhrases) {
  assert.ok(findUserFacingCopyIssues(phrase).length > 0, `gate must reject: ${phrase}`);
}

// ─── Parent empty list is a valid state ──────────────────────────────
const emptyDay = listActiveParentUpdates({
  now: new Date('2099-01-01T12:00:00'),
  limit: 2,
});
assert.strictEqual(emptyDay.length, 0, 'expired-only catalog may yield empty parent list');
assert.ok(Array.isArray(notesForRole('parent')), 'parent notes must be an array (empty allowed)');

// ─── Explicit projection only; no staff fallback ─────────────────────
const parentNotes = listActiveParentUpdates({
  now: new Date('2026-07-27T12:00:00'),
  limit: 2,
});
assert.ok(parentNotes.length >= 1, 'fixture PARENT_UPDATES.yml should expose leave policy on 2026-07-27');

const p0 = parentNotes[0];
assert.ok(p0.id && p0.title && p0.summary && p0.details, 'parent projection needs id/title/summary/details');
assert.ok(p0.publishedAt && p0.expiresAt, 'parent projection needs publishedAt/expiresAt');
assert.ok(['improvement', 'policy', 'resolved_issue'].includes(p0.kind), 'parent kind allowlist');

const teaser = parentReleaseNoteTeaser(p0);
assert.ok(teaser.length > 0 && teaser.length <= 200, 'parent teaser should be a short non-empty line');
assert.strictEqual(teaser, parentReleaseNoteTeaser({ summary: p0.summary }), 'teaser must come from projection summary');
assert.ok(!parentReleaseNoteTeaser({ summary: '', items: ['staff only'], title: 'x' }), 'empty summary must not fallback to staff items');

assert.ok(p0.summary.includes('未來課程') || p0.summary.includes('課程尾端'), 'leave policy parent copy should be present');
assert.ok(p0.details.includes('不需要重新操作') || p0.details.includes('班主任'), 'details must be parent-facing guidance');

const parentBlob = JSON.stringify(parentNotes);
assert.ok(!/payment-report|Phase 1 catalog|主任信任決策|代課挑選|Controller|Service/i.test(parentBlob), 'parent projections must not contain staff/internal jargon');
assert.ok(!/staff-2026-07-week-30|調課後課表不再跳回/.test(parentBlob), 'staff STAFF_UPDATES cards must not appear in parent projections');

const parentViaRole = notesForRole('parent');
for (const n of parentViaRole) {
  assert.ok(n.summary, 'parent role note must have projection summary');
  assert.ok(!n.sections, 'parent projection must not expose staff sections');
  assert.ok(!n.importance, 'parent projection must not use staff importance field');
}

assert.ok(Array.isArray(allParentUpdates), 'generated parentUpdates catalog is an array');
const expired = listActiveParentUpdates({ now: new Date('2026-08-26T12:00:00'), limit: 10 });
assert.ok(
  !expired.some((u) => u.id === 'parent-update-2026-07-26-leave'),
  'leave policy must expire after expires_at',
);

console.log('releaseNotes.test.js: ok');
