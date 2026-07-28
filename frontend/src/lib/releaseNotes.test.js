/**
 * Mirrors calendar tests: runnable with plain Node (npm run test:release-notes).
 * B+ Founder Decision 2026-07-26: parent updates are explicit projections, not CHANGELOG keywords.
 */
import assert from 'assert';
import { changelogReleaseNotes } from './releaseNotes.generated.js';
import {
  latestReleaseVersionForRole,
  listActiveParentUpdates,
  notesForRole,
  parentReleaseNoteTeaser,
  allParentUpdates,
} from './releaseNotes.js';

// ─── Staff CHANGELOG cards ───────────────────────────────────────────
assert.ok(notesForRole('director').length >= 1, 'director should see at least one CHANGELOG-derived entry');
assert.ok(notesForRole('teacher').length > 0, 'teacher should see release entries');

const directorCount = notesForRole('director').length;
assert.strictEqual(notesForRole('super_admin').length, directorCount, 'super_admin should match director-facing notes');
assert.strictEqual(notesForRole('admin').length, directorCount, 'admin should match director-facing notes');

assert.ok(latestReleaseVersionForRole('super_admin').length > 0, 'version nudge needs a stable version key');

const latest = notesForRole('director')[0];
assert.ok(/^\d+\.\d+\.\d+$/.test(latest.version), 'release notes should use three-part continuous version labels');
assert.ok(Array.isArray(latest.sections) && latest.sections.length > 0, 'release cards should have grouped sections');

const userFacingText = JSON.stringify(latest);
assert.ok(!/Controller|Service|\.vue|\.php|GET\s+\/|POST\s+\/|::/.test(userFacingText), 'release notes should avoid technical implementation terms');

// Staff cards must never carry parent audience (keyword derivation removed).
for (const note of changelogReleaseNotes) {
  assert.ok(
    !note.audience?.includes('parent'),
    `staff CHANGELOG card ${note.version} must not include audience:parent`,
  );
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

// Teaser and detail fields share the same projection object (no staff summary splice).
assert.ok(p0.summary.includes('未來課程') || p0.summary.includes('課程尾端'), 'leave policy parent copy should be present');
assert.ok(p0.details.includes('不需要重新操作') || p0.details.includes('班主任'), 'details must be parent-facing guidance');

const parentBlob = JSON.stringify(parentNotes);
assert.ok(!/payment-report|Phase 1 catalog|主任信任決策|代課挑選|Controller|Service/i.test(parentBlob), 'parent projections must not contain staff/internal jargon');
assert.ok(!/2026\.07\.12|主任在「已核准/.test(parentBlob), '2026.07.12 director evaluation filter must not appear for parents');

// Staff day cards with internal topics must not appear via notesForRole('parent').
const parentViaRole = notesForRole('parent');
for (const n of parentViaRole) {
  assert.ok(n.summary, 'parent role note must have projection summary');
  assert.ok(!n.sections, 'parent projection must not expose staff sections');
  assert.ok(!/payment-report/i.test(JSON.stringify(n)), 'payment-report staff card must not appear for parents');
}

// One staff day card never auto-creates a parent projection.
assert.ok(
  !changelogReleaseNotes.some((n) => n.audience?.includes('parent')),
  'no staff card may auto-tag parent',
);

// Catalog may include expired rows; listActiveParentUpdates filters them.
assert.ok(Array.isArray(allParentUpdates), 'generated parentUpdates catalog is an array');
const expired = listActiveParentUpdates({ now: new Date('2026-08-26T12:00:00'), limit: 10 });
assert.ok(
  !expired.some((u) => u.id === 'parent-update-2026-07-26-leave'),
  'leave policy must expire after expires_at',
);

console.log('releaseNotes.test.js: ok');
