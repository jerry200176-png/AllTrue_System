// 試聽暫留 (in-app #575): where a hold sits in the Smart Calendar grid.
// The grid buckets by start hour; one hour slot is 56px + 1px border, a block sits 3px inside it (SmartCalendar getBlockHeight).
const HOUR_PX = 57;

const minutesOf = (hhmm) => {
  const [h, m] = String(hhmm || '').split(':').map(Number);
  return (Number.isFinite(h) ? h : 0) * 60 + (Number.isFinite(m) ? m : 0);
};

/** Held holds of this campus that start in this hour on this date; teacherIds (Set of string ids) narrows when given. */
export function holdsInSlot(holds, { date, hour, campusId, teacherIds = null }) {
  return (holds || []).filter((hold) => hold.hold_date === date
    && Math.floor(minutesOf(hold.start_time) / 60) === hour
    && Number(hold.campus_id) === Number(campusId)
    && (!teacherIds || teacherIds.has(String(hold.teacher_id))));
}

/** Absolute position inside the start-hour slot: minute offset on top, duration as height. */
export function holdBlockStyle(hold) {
  const start = minutesOf(hold.start_time);
  const hours = (minutesOf(hold.end_time) - start) / 60;
  return {
    top: `${Math.round(((start % 60) / 60) * HOUR_PX) + 3}px`,
    height: `${Math.max(Math.round(hours * HOUR_PX) - 7, 20)}px`,
  };
}

/** Minutes between two HH:MM times (for prefilling a trial from a hold). */
export function holdDurationMinutes(hold) {
  return minutesOf(hold.end_time) - minutesOf(hold.start_time);
}
