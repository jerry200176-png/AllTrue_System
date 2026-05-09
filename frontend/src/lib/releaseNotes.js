import { changelogReleaseNotes } from './releaseNotes.generated.js';

/** Derived from docs/CHANGELOG.md (see scripts/changelog-to-release-notes.mjs). */
export const releaseNotes = changelogReleaseNotes;

/**
 * Elevated campus roles should see whatever we ship to directors/teachers — they do not maintain
 * a separate curated list in `audience` today (super_admin would otherwise see zero notes).
 */
function roleMatchesNoteAudience(note, role) {
  if (!note.audience?.length) {
    return true;
  }
  if (note.audience.includes(role)) {
    return true;
  }
  if (role === 'parent') {
    // Parent portal shows the same curated product-level updates in plain language.
    return note.audience.some((a) => a === 'director' || a === 'teacher');
  }
  const elevatedCampusStaff = ['super_admin', 'admin'];
  if (elevatedCampusStaff.includes(role)) {
    return note.audience.some((a) => a === 'director' || a === 'teacher');
  }
  return false;
}

export function notesForRole(role) {
  return releaseNotes.filter((note) => roleMatchesNoteAudience(note, role));
}

export function latestReleaseVersionForRole(role) {
  const notes = notesForRole(role);
  return notes.length > 0 ? notes[0].version : '';
}
