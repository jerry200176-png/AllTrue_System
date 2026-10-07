/**
 * Course action model (課程查找 redesign, docs/plans/2026-10-08-course-finder-actions.md).
 * One state-driven primary + an overflow grouped by intent (調動 / 帳務 / 結束), delete last.
 * Pure: the page passes capabilities it already computes, so this never re-derives billing or
 * scheduling rules. Item ids are the existing CourseManagement action names (onCourseManagerAction).
 */
export function courseActions(course, caps = {}) {
  const paused = String(course?.status || '').toLowerCase() === 'inactive';
  const canResume = paused && course?.closed_reason !== 'waived';
  const scheduling = caps.isManualOccurrence
    ? { id: 'manual-session', label: '＋新增下一堂' }
    : caps.isMonthly
      ? { id: 'monthly-session', label: '新增月結堂次' }
      : caps.isSession
        ? { id: 'quick-add', label: '補課／補登', ...(caps.canQuickAdd ? {} : { disabled: true, title: caps.quickAddReason || '' }) }
        : null;
  const purchase = { id: 'purchase', label: caps.purchaseLabel || '續約／加購' };

  let primary = { id: caps.fallback === 'manage' ? 'manage' : 'edit', label: caps.fallback === 'manage' ? '管理課程' : '編輯' };
  if (canResume) primary = { id: 'resume', label: '恢復課程' };
  else if (caps.needsScheduling && scheduling && !scheduling.disabled) primary = { id: scheduling.id, label: scheduling.label };
  else if (caps.renewalDue) primary = purchase;

  const groups = [
    { id: 'move', label: '調動', items: [
      { id: 'reschedule', label: '調課' },
      { id: 'substitute', label: '代課' },
      scheduling,
      { id: 'transfer', label: '轉課' },
      { id: 'duplicate', label: '換師複製' },
    ] },
    { id: 'billing', label: '帳務', items: [
      purchase,
      { id: 'contract-adjust', label: '合約／堂次調整' },
      caps.contractAmended && { id: 'contract-revert', label: '撤銷調整' },
      caps.packagePreview && { id: 'package-preview', label: '轉多科方案預檢' },
      caps.paymentNotice && { id: 'payment-slip', label: '繳費通知' },
      { id: 'invoice', label: '學生帳務' },
      { id: 'tuition', label: '前往帳務中心' },
    ] },
    { id: 'end', label: '結束', items: [
      paused ? (canResume && { id: 'resume', label: '恢復課程' }) : { id: 'pause', label: '暫停課程' },
      caps.canClose && { id: 'close', label: '結束課程（不再續課）', confirm: true },
      { id: 'delete', label: '刪除課程', danger: true, confirm: true },
    ] },
  ];
  for (const g of groups) g.items = g.items.filter((i) => i && i.id !== primary.id);
  return { primary, groups: groups.filter((g) => g.items.length) };
}
