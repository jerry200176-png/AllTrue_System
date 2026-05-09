/**
 * Mirrors calendar tests: runnable with plain Node (npm run test:release-notes).
 */
import assert from 'assert';
import { latestReleaseVersionForRole, notesForRole } from './releaseNotes.js';

assert.ok(notesForRole('director').length > 0, 'director should see release entries');
assert.ok(notesForRole('teacher').length > 0, 'teacher should see release entries');

const directorCount = notesForRole('director').length;
assert.strictEqual(notesForRole('super_admin').length, directorCount, 'super_admin should match director-facing notes');
assert.strictEqual(notesForRole('admin').length, directorCount, 'admin should match director-facing notes');

assert.ok(latestReleaseVersionForRole('super_admin').length > 0, 'version nudge needs a stable version key');

console.log('releaseNotes.test.js OK');
