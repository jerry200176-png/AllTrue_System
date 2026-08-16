/**
 * Confirm-dialog hint when marking a non-present attendance status.
 * Registry: AttendanceStatus — absent and leave are both non-deductible;
 * only leave triggers cascade / 順延.
 */
export function attendanceMarkConfirmHint(status) {
  if (status === 'leave' || status === 'excused') {
    return '請假將不扣堂並順延課程。';
  }
  if (status === 'absent') {
    return '缺席將不扣堂，也不順延課程。';
  }
  return '';
}
