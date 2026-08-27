const MOVABLE_STATUSES = new Set(['attended', 'completed', 'late']);

export function isRecoverableCancelledSession(session) {
  const status = String(session?.status || '').toLowerCase();
  if (status !== 'cancelled') return false;
  return Boolean(
    session?.recoverableCancelled
      || session?.hasLearningRecordHistory
      || session?.hasAttendanceHistory
      || session?.learningRecordId
      || session?.attendanceSignInAt
  );
}

export function buildTransferableSessionOption(session) {
  const status = String(session?.status || '').toLowerCase();
  if (MOVABLE_STATUSES.has(status)) {
    return { id: Number(session.id), date: session.date, status, recoverableCancelled: false };
  }
  if (isRecoverableCancelledSession(session)) {
    return {
      id: Number(session.id),
      date: session.date,
      status: 'cancelled_recoverable',
      originalStatus: 'cancelled',
      recoverableCancelled: true,
    };
  }
  return null;
}

export function hasRecoverableCancelledSelection(sessions, selectedIds) {
  const selected = new Set((selectedIds || []).map(Number));
  return (sessions || []).some((session) => selected.has(Number(session?.id)) && session?.recoverableCancelled);
}
