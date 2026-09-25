/** Shared confirmation and request path for closing a course without renewal. */
export async function closeCourseNoRenew({
  course,
  studentName,
  getRemainingSessions,
  getSubjectLabel,
  isCourseSettled,
  supabase,
  reloadCourses,
  fetchImpl = globalThis.fetch,
  confirmImpl = globalThis.confirm,
  alertImpl = globalThis.alert,
}) {
  const courseId = Number(course?.id ?? course?.ID ?? 0);
  if (!courseId) { alertImpl('課程資料缺少識別碼，請重新整理後再試'); return; }
  const subject = getSubjectLabel(course?.subject);
  const remaining = Math.max(0, Number(getRemainingSessions(course) ?? 0));
  const paymentWarning = isCourseSettled(course)
    ? ''
    : '\n\n目前尚未完成繳費；結案後會標記「待對帳」，不會視為已繳費。';
  const balanceWarning = remaining > 0
    ? `\n\n目前還有 ${remaining} 堂未使用。結案會取消未來排課，並放棄這 ${remaining} 堂剩餘額度。`
    : '';
  if (!confirmImpl(`確定要結案「${studentName || '學生'}」的 ${subject} 課程嗎？${paymentWarning}${balanceWarning}\n\n結案後此課程不再排課；若尚未繳費，會保留在帳務中心的「結案待對帳」佇列。已繳費與已上課紀錄仍會保留。`)) return;

  try {
    const { data: { session } } = await supabase.auth.getSession();
    const token = session?.access_token;
    if (!token) { alertImpl('請重新登入'); return; }
    const res = await fetchImpl(`/api/v1/student-classes/${courseId}/pause`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        action: 'pause',
        reason: 'settled',
        ...(remaining > 0 ? { forfeit_remaining: true } : {}),
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { alertImpl('結案失敗：' + (json.message || res.statusText)); return; }
    alertImpl(json.pending_reconciliation
      ? '已結案，課程保留在帳務中心的「結案待對帳」佇列，尚未視為已繳費。'
      : '已結案，此課程不再出現在繳費／續課提醒中。');
    await reloadCourses();
  } catch (error) {
    alertImpl('操作失敗：' + (error?.message || '請稍後再試'));
  }
}
