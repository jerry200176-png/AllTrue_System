const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d/;

const NEXT_STEP_HINTS = {
  edit_charge_only: '堂數不能再改了，但費用還能改：請關閉本視窗，改到課程「編輯」畫面直接調整總費用，堂數維持不變即可。',
  handle_affected_scheduled_sessions_then_retry: '請先在行事曆取消或調整以下未來堂次，再回到這裡重試；系統不會自動取消。',
  record_or_cancel_past_sessions_then_retry: '請先到行事曆替下列堂次補點名，或取消這些堂次，再回到這裡重新預覽；系統不會自動取消已過日期的堂次。',
};

const PAST_UNRECORDED_CODE = 'billing_correction_past_unrecorded_sessions';

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
  const isFutureScheduleConflict = code === 'billing_correction_future_schedule_over_capacity';
  const isPastUnrecorded = code === PAST_UNRECORDED_CODE;
  const affectedSessions = normalizeAffectedScheduledSessions(
    isPastUnrecorded ? body?.past_unrecorded_sessions : body?.affected_scheduled_sessions,
  );

  let message = String(body?.message || '更正失敗');
  if (isFutureScheduleConflict) {
    message = '更正後堂數會少於目前已排堂次，因此尚未儲存。';
  } else if (isPastUnrecorded) {
    message = `有 ${affectedSessions.length} 堂已過日期、還沒點名的課會超出更正後的堂數，因此尚未儲存。`;
  }

  return {
    code,
    message,
    hint: NEXT_STEP_HINTS[body?.next_step] || null,
    affectedSessions,
    sessionListLabel: isPastUnrecorded ? '需先補點名或取消的已過日期堂次' : '需先處理的未來堂次',
    canOpenCalendar: (isFutureScheduleConflict || isPastUnrecorded) && affectedSessions.length > 0,
    firstAffectedDate: affectedSessions[0]?.sessionDate || '',
    // The preview (and its token) no longer describes a correction that can be
    // applied; the director must preview again after fixing the sessions.
    resetsPreview: isPastUnrecorded
      || code === 'billing_correction_confirmation_stale'
      || code === 'billing_correction_confirmation_required',
  };
}
