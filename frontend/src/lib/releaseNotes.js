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
    return note.audience?.includes('parent');
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

const PARENT_TEASER_HINT =
  /家長|家長端|家長入口|Parent|請假|繳費|帳務|課表|學習評量|評量|留言|互動|回饋|LINE|通知|出缺勤|月結|帳單|刷卡/i;

/** One short line for parent mobile UI; prefers a parent-relevant bullet when present. */
export function parentReleaseNoteTeaser(note, maxLen = 80) {
  if (!note) return '';
  const items = note.items || [];
  const hit = items.find((t) => PARENT_TEASER_HINT.test(String(t)));
  const text = String(hit || note.summary || '').trim();
  if (text.length <= maxLen) return text;
  return `${text.slice(0, maxLen - 1)}…`;
}
