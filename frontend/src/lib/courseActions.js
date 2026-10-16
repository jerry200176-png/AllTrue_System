/**
 * Course action model (課程查找 redesign, docs/plans/2026-10-08-course-finder-actions.md).
 * One state-driven primary + an overflow: 編輯 first and ungrouped (when a state primary displaced it),
 * then 調動 / 帳務 / 狀態, and 刪除 alone in its own danger group (the menu draws the divider).
 * Items a course can't use stay visible and greyed with a `reason` only where the page already computes one.
 * Pure: the page passes capabilities it already computes, so this never re-derives billing or
 * scheduling rules. Item ids are the existing CourseManagement action names (onCourseManagerAction).
 */
/**
 * #4188: the course's close has committed (inactive with a stored closed_reason), the same rule the
 * server applies on the locked row. Such a course is not resumed in place: the server refuses
 * (409 course_closed_use_renewal) and staff continue through 續課; a mistaken close is reported to an admin.
 */
export function isCourseCloseCommitted(course) {
  return String(course?.status || '').toLowerCase() === 'inactive' && Boolean(course?.closed_reason);
}

export const CONVERTED_TRIAL_RESUME_MESSAGE = '這堂試聽課已轉為正式課程，請到正式課程繼續上課。';

export const CLOSED_COURSE_RESUME_MESSAGE = '這門課已經結案，不能直接恢復。要繼續上課，請用「續課」建立新的一期；如果是誤結案，請用「回報問題」通知系統管理員處理。過去的上課、繳費與結案紀錄都會保留。';

export function courseActions(course, caps = {}) {
  const paused = String(course?.status || '').toLowerCase() === 'inactive';
  const closed = isCourseCloseCommitted(course);
  // A closed course keeps a 恢復課程 item (it explains 續課) but is never the state primary.
  const canResume = paused && !closed;
  // Waived is terminal; a converted trial continues in its formal course, so neither offers 恢復.
  const offerResume = paused && !['waived', 'converted_trial'].includes(course?.closed_reason);
  // 排課 (openManualSessionModal) is the row's former first-class button; 補課／補登 / 新增月結堂次 the More item.
  const schedule = (caps.isManualOccurrence || caps.isSession || caps.isMonthly)
    ? { id: 'manual-session', label: caps.isManualOccurrence ? '＋新增下一堂' : caps.isMonthly ? '排月結' : '排課' }
    : null;
  const extra = caps.isManualOccurrence ? null
    : caps.isMonthly ? { id: 'monthly-session', label: '新增月結堂次' }
      : caps.isSession
        ? { id: 'quick-add', label: '補課／補登', ...(caps.canQuickAdd ? {} : { disabled: true, reason: caps.quickAddReason || '' }) }
        : null;
  const purchase = { id: 'purchase', label: caps.purchaseLabel || '續約／加購' };

  const fallback = { id: caps.fallback === 'manage' ? 'manage' : 'edit', label: caps.fallback === 'manage' ? '管理課程' : '編輯' };
  let primary = fallback;
  if (canResume) primary = { id: 'resume', label: '恢復課程' };
  else if (caps.needsScheduling && schedule) primary = schedule;
  else if (caps.renewalDue) primary = purchase;

  const groups = [
    // 編輯 is not a "move": first and ungrouped. Filtered out below when it is the primary.
    { id: 'lead', label: '', items: [fallback, caps.details && { id: 'details', label: caps.detailsOpen ? '收起詳情' : '詳情' }] },
    { id: 'move', label: '調動', items: [
      { id: 'reschedule', label: '調課' },
      { id: 'substitute', label: '代課' },
      schedule,
      extra,
      { id: 'transfer', label: '轉課' },
      { id: 'duplicate', label: '換師複製' },
    ] },
    { id: 'billing', label: '帳務', items: [
      purchase,
      { id: 'contract-adjust', label: '合約／堂次調整' },
      caps.contractAmended && { id: 'contract-revert', label: '撤銷調整' },
      caps.packagePreview && { id: 'package-preview', label: '轉多科方案預檢' },
      caps.paymentNotice && { id: 'payment-slip', label: '繳費單' },
      { id: 'invoice', label: '在這裡看帳務' },
    ] },
    { id: 'end', label: '狀態', items: [
      paused ? (offerResume && { id: 'resume', label: '恢復課程' }) : { id: 'pause', label: '暫停課程' },
      caps.canClose && { id: 'close', label: '結束課程（不再續課）', confirm: true },
    ] },
    { id: 'danger', label: '', items: [{ id: 'delete', label: '刪除課程', danger: true, confirm: true }] },
  ];
  for (const g of groups) g.items = g.items.filter((i) => i && i.id !== primary.id);
  return { primary, groups: groups.filter((g) => g.items.length) };
}
