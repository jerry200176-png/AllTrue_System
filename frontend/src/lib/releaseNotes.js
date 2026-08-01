import { staffUpdates } from './staffUpdates.generated.js';
import { parentUpdates } from './parentUpdates.generated.js';

/**
 * Staff in-app「版本更新」— explicit approved copy from docs/STAFF_UPDATES.yml only.
 * Engineering CHANGELOG drafts live in changelogDraft.generated.js and are never auto-published.
 */
export const releaseNotes = staffUpdates;
export const allStaffUpdates = staffUpdates;

/** Explicit parent copy from docs/PARENT_UPDATES.yml — never staff CHANGELOG / STAFF_UPDATES. */
export const allParentUpdates = parentUpdates;

/**
 * Elevated campus roles should see whatever we ship to directors/teachers — they do not maintain
 * a separate curated list in `audience` today (super_admin would otherwise see zero notes).
 */
function roleMatchesNoteAudience(note, role) {
  if (role === 'parent') {
    return false;
  }
  const audiences = note.audiences || note.audience || [];
  if (!audiences?.length) {
    return true;
  }
  if (audiences.includes(role)) {
    return true;
  }
  const elevatedCampusStaff = ['super_admin', 'admin'];
  if (elevatedCampusStaff.includes(role)) {
    return audiences.some((a) => a === 'director' || a === 'teacher');
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

/**
 * Staff notes for a role, sorted by published_at (generator already sorts; defensive re-sort).
 */
export function notesForRole(role) {
  if (role === 'parent') {
    return listActiveParentUpdates({ limit: 2 });
  }
  const IMPORTANCE_RANK = { action_required: 3, major: 2, digest: 1 };
  return staffUpdates
    .filter((note) => roleMatchesNoteAudience(note, role))
    .slice()
    .sort((a, b) => {
      const d = String(b.publishedAt || b.date || '').localeCompare(String(a.publishedAt || a.date || ''));
      if (d) return d;
      const ia = IMPORTANCE_RANK[a.importance] || 0;
      const ib = IMPORTANCE_RANK[b.importance] || 0;
      if (ib !== ia) return ib - ia;
      return String(b.id || '').localeCompare(String(a.id || ''));
    });
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
