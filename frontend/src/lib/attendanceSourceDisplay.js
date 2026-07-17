const ATTENDANCE_STATUSES = new Set(['completed', 'attended', 'late', 'absent']);

export function describeSessionAttendanceSource(row = {}) {
  const memo = String(row?.attendanceMemo || '').trim().toLowerCase();
  const recordedBy = String(row?.recordedByName || '').trim();
  const hasSignIn = Boolean(row?.attendanceSignInAt || memo || recordedBy);

  if (memo === 'swipe' || memo === 'swipe-rfid') {
    return { kind: 'attendance', label: 'RFID 刷卡' };
  }
  if (hasSignIn) {
    const fallback = memo === 'manual-match' ? '人工配對（操作者未記錄）' : '人工點名（操作者未記錄）';
    return { kind: 'attendance', label: recordedBy || fallback };
  }

  const status = String(row?.status || '').trim().toLowerCase();
  if (ATTENDANCE_STATUSES.has(status)) {
    return { kind: 'system', label: '系統堂次狀態（無出勤紀錄）' };
  }
  return null;
}
