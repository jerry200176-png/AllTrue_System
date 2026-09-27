/**
 * Pure helpers for the calendar's cross-campus availability hint.
 *
 * The availability endpoint is already permission-checked and returns only
 * metadata.  These helpers intentionally keep this feature presentation-only:
 * they filter to another campus and report interval overlap without changing
 * scheduling or conflict decisions.
 */

function toMinutes(value) {
  const match = String(value ?? '').trim().match(/^(\d{1,2}):(\d{2})/);
  if (!match) return null;
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (!Number.isInteger(hours) || !Number.isInteger(minutes) || hours < 0 || hours > 23 || minutes > 59) {
    return null;
  }
  return hours * 60 + minutes;
}

export function normalizeCrossCampusBusySlots(slots, currentCampusId) {
  const campus = Number(currentCampusId);
  if (!Number.isInteger(campus) || campus <= 0 || !Array.isArray(slots)) return [];

  return slots
    .map((slot) => {
      const start = toMinutes(slot?.start_time);
      const end = toMinutes(slot?.end_time);
      const slotCampus = Number(slot?.campus_id);
      const remaining = Number(slot?.remaining_capacity ?? 0);
      if (!Number.isInteger(slotCampus) || slotCampus <= 0 || slotCampus === campus) return null;
      if (start == null || end == null || end <= start) return null;
      // A group class with an available seat is not an unavailable teacher
      // slot.  The endpoint defaults missing capacity to zero (full), so this
      // remains fail-safe for older responses.
      if (!Number.isFinite(remaining) || remaining > 0) return null;
      return { start, end, campusId: slotCampus };
    })
    .filter(Boolean)
    .sort((a, b) => a.start - b.start || a.end - b.end || a.campusId - b.campusId);
}

export function hasCrossCampusBusySlot(slots, hour) {
  const start = Number(hour) * 60;
  if (!Number.isInteger(start) || start < 0) return false;
  const end = start + 60;
  return (Array.isArray(slots) ? slots : []).some((slot) => slot.start < end && slot.end > start);
}

