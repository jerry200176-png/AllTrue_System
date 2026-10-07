import { ENDED_PENDING_LABEL, TUITION_STATUS_CONFIG } from './courseMoneyState.js';
import { authedFetch, getAccessToken } from './authedFetch';

/** Shared confirmation and request path for closing a course without renewal. */
export async function closeCourseNoRenew({
  course,
  studentName,
  getRemainingSessions,
  getSubjectLabel,
  isCourseSettled,
  reloadCourses,
  confirmImpl = globalThis.confirm,
  alertImpl = globalThis.alert,
}) {
  const courseId = Number(course?.id ?? course?.ID ?? 0);
  if (!courseId) { alertImpl('課程資料缺少識別碼，請重新整理後再試'); return; }
  const subject = getSubjectLabel(course?.subject);
  const remaining = Math.max(0, Number(getRemainingSessions(course) ?? 0));
  const settled = isCourseSettled(course);
  if (settled === null) { alertImpl('繳費狀態載入中，請重新整理後再結案'); return; }
  const paymentWarning = settled
    ? ''
    : `\n\n目前尚未完成繳費；結案後會標記「${ENDED_PENDING_LABEL}」，不會視為已收。`;
  const balanceWarning = remaining > 0
    ? `\n\n目前還有 ${remaining} 堂未使用。結案會取消未來排課，並放棄這 ${remaining} 堂剩餘額度。`
    : '';
  if (!confirmImpl(`確定要結案「${studentName || '學生'}」的 ${subject} 課程嗎？${paymentWarning}${balanceWarning}\n\n結案後此課程不再排課；若尚未繳費，會保留在帳務中心的「${TUITION_STATUS_CONFIG.pending_reconciliation.label}」分頁。已繳費與已上課紀錄仍會保留。`)) return;

  try {
    const token = await getAccessToken();
    if (!token) { alertImpl('請重新登入'); return; }
    const res = await authedFetch(`/api/v1/student-classes/${courseId}/pause`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        action: 'pause',
        reason: 'settled',
        ...(remaining > 0 ? { forfeit_remaining: true } : {}),
      }),
    }, token);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { alertImpl('結案失敗：' + (json.message || res.statusText)); return; }
    alertImpl(json.pending_reconciliation
      ? `已結案，課程保留在帳務中心的「${TUITION_STATUS_CONFIG.pending_reconciliation.label}」分頁，尚未視為已收。`
      : '已結案，此課程不再出現在繳費／續課提醒中。');
    await reloadCourses();
  } catch (error) {
    alertImpl('操作失敗：' + (error?.message || '請稍後再試'));
  }
}
