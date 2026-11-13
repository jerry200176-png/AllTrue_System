import { SNAPSHOT_MAX_CHARS } from './pageDiagnostics.js';
import { classificationUnknown, courseHistoryReason, isCourseManagementHistory, ownRemainingSessions } from './courseMoneyState.js';

export const COURSE_SNAPSHOT_HISTORY_LIMIT = 20;
export const COURSE_SNAPSHOT_ROW_KEYS = ['id', 'history_reason', 'status', 'payment_type', 'remaining', 'noncanonical'];

const ENUM = /^[a-z_]{1,32}$/;
const enumOrNull = (v) => (typeof v === 'string' && ENUM.test(v.toLowerCase()) ? v.toLowerCase() : null);

/**
 * Non-PII classification inputs for in-app reports from 課程查找: list source, working/history counts and
 * up to 20 rendered history rows (ids and enums only: no names, amounts or free text).
 * @param {{ source: 'primary'|'fallback'|null, courses: object[] }} input
 */
export function buildCourseListSnapshot({ source, courses }) {
  const list = Array.isArray(courses) ? courses : [];
  const history = list.filter(isCourseManagementHistory);
  const snapshot = {
    listSource: source === 'primary' || source === 'fallback' ? source : null,
    working: list.length - history.length,
    unknown: list.filter(classificationUnknown).length,
    history: history.length,
    historyRows: history.slice(0, COURSE_SNAPSHOT_HISTORY_LIMIT).map((c) => ({
      id: Number(c?.id ?? c?.ID) || null,
      history_reason: enumOrNull(courseHistoryReason(c)),
      status: enumOrNull(c?.status),
      payment_type: enumOrNull(c?.payment_type),
      remaining: ownRemainingSessions(c),
      noncanonical: c?._noncanonical === true,
    })),
  };
  // Counts and listSource always stay; rows are trimmed from the end until the snapshot fits, never nulled.
  const fit = () => { while (snapshot.historyRows.length && JSON.stringify(snapshot).length > SNAPSHOT_MAX_CHARS) snapshot.historyRows.pop(); };
  fit();
  if (snapshot.historyRows.length < history.length) {
    snapshot.truncated = true;
    fit();
  }
  return snapshot;
}
