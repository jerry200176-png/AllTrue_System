const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d/;

const NEXT_STEP_HINTS = {
  edit_charge_only: '堂數不能再改了，但費用還能改：請關閉本視窗，改到課程「編輯」畫面直接調整總費用，堂數維持不變即可。',
  handle_affected_scheduled_sessions_then_retry: '請先在行事曆取消或調整以下未來堂次，再回到這裡重試；系統不會自動取消。',
};

function normalizeDate(value) {
  const date = String(value ?? '').slice(0, 10);
  return DATE_PATTERN.test(date) ? date : '';
}

function normalizeTime(value) {
  const time = String(value ?? '').slice(0, 5);
  return TIME_PATTERN.test(time) ? time : '';
}

export function normalizeAffectedScheduledSessions(value) {
  if (!Array.isArray(value)) return [];

  const seen = new Set();
  return value
    .map((session) => {
      const sessionId = Number(session?.session_id);
      const sessionDate = normalizeDate(session?.session_date);
      const startTime = normalizeTime(session?.start_time);
      const endTime = normalizeTime(session?.end_time);
      if (!Number.isSafeInteger(sessionId) || sessionId <= 0 || !sessionDate || !startTime) return null;
      return { sessionId, sessionDate, startTime, endTime };
    })
    .filter((session) => {
      if (!session || seen.has(session.sessionId)) return false;
      seen.add(session.sessionId);
      return true;
    })
    .sort((a, b) => (
      a.sessionDate.localeCompare(b.sessionDate)
      || a.startTime.localeCompare(b.startTime)
      || a.sessionId - b.sessionId
    ));
}

export function formatBillingCorrectionSessionLabel(session) {
  if (!session) return '';
  const timeRange = session.endTime
    ? `${session.startTime}–${session.endTime}`
    : session.startTime;
  return `${session.sessionDate} ${timeRange}`;
}

export function buildBillingCorrectionBlockedState(body = {}) {
  const code = String(body?.code ?? '');
  const affectedSessions = normalizeAffectedScheduledSessions(body?.affected_scheduled_sessions);
  const isFutureScheduleConflict = code === 'billing_correction_future_schedule_over_capacity';

  return {
    code,
    message: isFutureScheduleConflict
      ? '更正後堂數會少於目前已排堂次，因此尚未儲存。'
      : String(body?.message || '更正失敗'),
    hint: NEXT_STEP_HINTS[body?.next_step] || null,
    affectedSessions,
    canOpenCalendar: isFutureScheduleConflict && affectedSessions.length > 0,
    firstAffectedDate: affectedSessions[0]?.sessionDate || '',
  };
}
