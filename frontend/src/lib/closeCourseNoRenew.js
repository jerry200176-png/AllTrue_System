import { ENDED_PENDING_LABEL, TUITION_STATUS_CONFIG } from './courseMoneyState.js';
import { authedFetch, getAccessToken } from './authedFetch';

/**
 * Shared confirmation and request path for closing a course without renewal.
 * Resolves true only when the course was closed. A refresh failure after a successful close
 * still resolves true (with its own notice), so an already-closed course is never retried.
 */
export const RELOAD_AFTER_CLOSE_FAILED_MESSAGE = '已結束課程，但列表重新整理失敗，請重新整理頁面';

export async function closeCourseNoRenew({
  course,
  studentName,
  getRemainingSessions,
  getSubjectLabel,
  isCourseSettled,
  reloadCourses,
  onClosed,
  confirmImpl = globalThis.confirm,
  alertImpl = globalThis.alert,
}) {
  const courseId = Number(course?.id ?? course?.ID ?? 0);
  if (!courseId) { alertImpl('課程資料缺少識別碼，請重新整理後再試'); return false; }
  const subject = getSubjectLabel(course?.subject);
  const remaining = Math.max(0, Number(getRemainingSessions(course) ?? 0));
  const settled = isCourseSettled(course);
  if (settled === null) { alertImpl('繳費狀態載入中，請重新整理後再結案'); return false; }
  const paymentWarning = settled
    ? ''
    : `\n\n目前尚未完成繳費；結案後會標記「${ENDED_PENDING_LABEL}」，不會視為已收。`;
  const balanceWarning = remaining > 0
    ? `\n\n目前還有 ${remaining} 堂未使用。結案會取消未來排課，並放棄這 ${remaining} 堂剩餘額度。`
    : '';
  // The 2nd arg is read by the in-app dialog (askConfirm); the native confirm ignores it.
  if (!await confirmImpl(`確定要結案「${studentName || '學生'}」的 ${subject} 課程嗎？${paymentWarning}${balanceWarning}\n\n結案後此課程不再排課；若尚未繳費，會保留在帳務中心的「${TUITION_STATUS_CONFIG.pending_reconciliation.label}」分頁。已繳費與已上課紀錄仍會保留。`, { title: `結束${subject}？`, confirmLabel: '結束課程', danger: true })) return false;

  try {
    const token = await getAccessToken();
    if (!token) { alertImpl('請重新登入'); return false; }
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
    if (!res.ok) { alertImpl('結案失敗：' + (json.message || res.statusText)); return false; }
    onClosed?.({ pendingReconciliation: Boolean(json.pending_reconciliation) });
    alertImpl(json.pending_reconciliation
      ? `已結案，課程保留在帳務中心的「${TUITION_STATUS_CONFIG.pending_reconciliation.label}」分頁，尚未視為已收。`
      : '已結案，此課程不再出現在繳費／續課提醒中。');
  } catch (error) {
    alertImpl('操作失敗：' + (error?.message || '請稍後再試'));
    return false;
  }
  // The close is committed here; a failed list refresh must not be reported as a failed close.
  try {
    await reloadCourses();
  } catch {
    alertImpl(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
  }
  return true;
}

const courseIdOf = (course) => Number(course?.id ?? course?.ID ?? 0);

export const CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE = '已結束課程。目前篩選只顯示進行中，可切到「全部」或「歷史課程」查看。';
// An unpaid close (settled_pending) stays in the main list under 「全部」, never in 歷史課程.
export const CLOSED_PENDING_HIDDEN_BY_ACTIVE_FILTER_NOTICE = '已結束課程（尚未收款）。目前篩選只顯示進行中，可切到「全部」查看，課程會標「課已結束，尚未收款」。';
export const CLOSED_HIDDEN_BY_FILTER_NOTICE = '已結束課程。目前的篩選或分頁沒有顯示這門課，可調整篩選後查看。';

/**
 * in-app #415: after a close the page only runs its normal reload. When the current filter hides
 * the closed course (e.g. 進行中), return a non-error notice instead of injecting rows.
 * `canShowAll` means switching 課程狀態 to 「全部」 can reveal it. `pendingReconciliation` (the close
 * response flag, i.e. closed_reason settled_pending) keeps 歷史課程 out of the text for unpaid closes.
 */
export function closedCourseHiddenNotice({ course, courses = [], courseStatus = '', pendingReconciliation = false }) {
  const id = courseIdOf(course);
  if (!id || (courses || []).some((x) => courseIdOf(x) === id)) return null;
  if (courseStatus === 'active') {
    const message = pendingReconciliation ? CLOSED_PENDING_HIDDEN_BY_ACTIVE_FILTER_NOTICE : CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE;
    return { courseId: id, message, canShowAll: true };
  }
  return { courseId: id, message: CLOSED_HIDDEN_BY_FILTER_NOTICE, canShowAll: Boolean(courseStatus) };
}

/**
 * One close at a time across the row menu and the 管理課程 drawer. A drawer close only dismisses
 * the drawer when it still shows the course that started the close (the user may have opened
 * another course's drawer while the request was pending).
 */
export function createCourseCloseFlow({ closeCourse, getDrawerCourse, closeDrawer }) {
  let pending = false;
  async function close(course) {
    if (pending) return false;
    pending = true;
    try {
      return await closeCourse(course);
    } finally {
      pending = false;
    }
  }
  async function closeFromDrawer(course) {
    const closed = await close(course);
    if (closed && courseIdOf(getDrawerCourse()) === courseIdOf(course)) closeDrawer();
    return closed;
  }
  return { close, closeFromDrawer, isPending: () => pending };
}
