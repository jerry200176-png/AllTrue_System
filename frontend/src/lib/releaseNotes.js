import { changelogReleaseNotes } from './releaseNotes.generated.js';
import { parentUpdates } from './parentUpdates.generated.js';

/** Derived from docs/CHANGELOG.md (see scripts/changelog-to-release-notes.mjs). Staff only. */
export const releaseNotes = changelogReleaseNotes;

/** Explicit parent copy from docs/PARENT_UPDATES.yml — never staff CHANGELOG. */
export const allParentUpdates = parentUpdates;

/**
 * Elevated campus roles should see whatever we ship to directors/teachers — they do not maintain
 * a separate curated list in `audience` today (super_admin would otherwise see zero notes).
 */
function roleMatchesNoteAudience(note, role) {
  if (role === 'parent') {
    return false;
  }
  if (!note.audience?.length) {
    return true;
  }
  if (note.audience.includes(role)) {
    return true;
  }
  const elevatedCampusStaff = ['super_admin', 'admin'];
  if (elevatedCampusStaff.includes(role)) {
    return note.audience.some((a) => a === 'director' || a === 'teacher');
  }
  return false;
}

function toIsoDateLocal(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/**
 * Active parent projections for ParentPortal.
 * @param {{ now?: Date, limit?: number }} [opts]
 */
export function listActiveParentUpdates(opts = {}) {
  const now = opts.now instanceof Date ? opts.now : new Date();
  const today = toIsoDateLocal(now);
  const limit = Number.isFinite(opts.limit) ? opts.limit : 2;
  const active = (allParentUpdates || []).filter((u) => {
    if (!u?.summary || !u?.title || !u?.details) return false;
    if (u.publishedAt && String(u.publishedAt) > today) return false;
    if (u.expiresAt && String(u.expiresAt) < today) return false;
    return true;
  });
  return active.slice(0, Math.max(0, limit));
}

export function notesForRole(role) {
  if (role === 'parent') {
    return listActiveParentUpdates({ limit: 2 });
  }
  return releaseNotes.filter((note) => roleMatchesNoteAudience(note, role));
}

export function latestReleaseVersionForRole(role) {
  const notes = notesForRole(role);
  return notes.length > 0 ? notes[0].version : '';
}

/**
 * One short line for parent mobile UI.
 * Uses projection summary only — never staff CHANGELOG summary/items.
 */
export function parentReleaseNoteTeaser(note, maxLen = 80) {
  if (!note) return '';
  const text = String(note.summary || '').trim();
  if (!text) return '';
  if (text.length <= maxLen) return text;
  return `${text.slice(0, maxLen - 1)}…`;
}
