/**
 * Mirrors calendar tests: runnable with plain Node (npm run test:release-notes).
 */
import assert from 'assert';
import { latestReleaseVersionForRole, notesForRole, parentReleaseNoteTeaser } from './releaseNotes.js';

assert.ok(notesForRole('director').length >= 3, 'director should see several CHANGELOG-derived entries');
assert.ok(notesForRole('teacher').length > 0, 'teacher should see release entries');
assert.ok(notesForRole('parent').length > 0, 'parent should see parent-tagged release entries');
const p0 = notesForRole('parent')[0];
assert.ok(
  parentReleaseNoteTeaser(p0).length > 0 && parentReleaseNoteTeaser(p0).length <= 200,
  'parent teaser should be a short non-empty line',
);

const directorCount = notesForRole('director').length;
assert.strictEqual(notesForRole('super_admin').length, directorCount, 'super_admin should match director-facing notes');
assert.strictEqual(notesForRole('admin').length, directorCount, 'admin should match director-facing notes');

assert.ok(latestReleaseVersionForRole('super_admin').length > 0, 'version nudge needs a stable version key');

const latest = notesForRole('director')[0];
assert.ok(/^\d+\.\d+\.\d+$/.test(latest.version), 'release notes should use three-part continuous version labels');
assert.ok(Array.isArray(latest.sections) && latest.sections.length > 0, 'release cards should have Minecraft-style sections');

const userFacingText = JSON.stringify(latest);
assert.ok(!/Controller|Service|\.vue|\.php|GET\s+\/|POST\s+\/|::/.test(userFacingText), 'release notes should avoid technical implementation terms');
