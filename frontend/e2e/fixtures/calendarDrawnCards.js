// @ts-check
/**
 * What the calendar must draw for one day, judged from the class sessions the acceptance served it (#5175, #5178).
 * A card exposes its identity in its aria-label: "{date} {start_time} {student} {subject}，點擊查看詳細資訊"
 * (SmartCalendar.vue getCourseAriaLabel). Matching on date + time + student keeps the check independent of the
 * subject wording and of the card layout (day view, week view, split slot).
 */
const DEAD_STATUSES = ['cancelled', 'voided'];

const ymd = (value) => String(value ?? '').slice(0, 10);
const hm = (value) => String(value ?? '').slice(0, 5);
const field = (row, ...keys) => {
  for (const key of keys) if (row?.[key] !== undefined && row?.[key] !== null) return row[key];
  return null;
};

function identity(row) {
  return {
    date: ymd(field(row, 'session_date', 'SessionDate')),
    time: hm(field(row, 'start_time', 'StartTime')),
    student: String(field(row, 'student_name', 'studentName') ?? '').trim(),
    live: !DEAD_STATUSES.includes(String(field(row, 'status', 'Status') ?? '').toLowerCase()),
  };
}

/** Sessions of `date`: live ones the calendar must draw, and every one (any status) a card may legitimately show. */
export function sessionsOfDay(rows, date) {
  const all = (Array.isArray(rows) ? rows : []).map(identity).filter((item) => item.date === date);
  return { live: all.filter((item) => item.live && item.student !== '' && item.time !== ''), all };
}

const labelMatches = (label, item) => label.startsWith(`${item.date} ${item.time}`) && label.includes(item.student);

/**
 * Compare drawn card labels with the sessions of the day.
 *  - matched: live sessions with a drawn card;
 *  - wrongDay: labels carrying a date other than `date` (the day view draws only the selected day);
 *  - phantom: labels of this day that match no served session of any status (informational, see drawsServedSessions).
 */
export function compareDrawnCards(labels, rows, date) {
  const { live, all } = sessionsOfDay(rows, date);
  const named = all.filter((item) => item.student !== '');
  const cards = (Array.isArray(labels) ? labels : []).map(String);
  const matched = live.filter((item) => cards.some((label) => labelMatches(label, item)));
  const wrongDay = cards.filter((label) => /^\d{4}-\d{2}-\d{2} /.test(label) && !label.startsWith(`${date} `));
  const phantom = cards.filter((label) => label.startsWith(`${date} `)
    && !named.some((item) => labelMatches(label, item)));
  return { expected: live.length, matched: matched.length, wrongDay, phantom };
}

/** One readable line for a failed poll. */
export function describeDrawnCards(result) {
  return `served live sessions=${result.expected}, drawn for them=${result.matched}, `
    + `cards of another day=${result.wrongDay.length}, cards with no served session=${result.phantom.length}`;
}

/**
 * True when the calendar draws the day correctly: at least one served session is drawn and no card of another day.
 * Cards with no served session that day (`phantom`) are not a failure: the calendar legitimately draws recurrence
 * cards from the contract weekday, schedule-only makeup/exception cards and cards at the contract slot time
 * (calendarOccurrenceMerge.js). They are reported as coverage only.
 */
export function drawsServedSessions(result) {
  return result.expected > 0 && result.matched > 0 && result.wrongDay.length === 0;
}
