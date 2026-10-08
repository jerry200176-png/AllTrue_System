import { courseActions } from '../../lib/courseActions.js';

/**
 * Course-row glue (課程查找 C-PR2): feeds the page's own capability helpers into courseActions()
 * and routes a picked action id to the handler the old row buttons already called.
 * `d` is a bag of page functions; nothing here re-derives billing or scheduling rules.
 */
export function useCourseRowActions(d) {
  function rowModel(c) {
    const open = c.status !== 'inactive' && !d.effectiveClosedReason(c);
    const planning = d.planningStatusVisible(c) ? d.planningStatusFor(c)?.action : null;
    return courseActions(c, {
      fallback: 'edit',
      details: true,
      detailsOpen: d.isDetailsOpen(c),
      isSession: d.isSessionMode(c),
      isMonthly: d.isMonthlyMode(c),
      isManualOccurrence: d.isManualOccurrenceCourse(c),
      canQuickAdd: d.canQuickAddSession(c),
      quickAddReason: d.quickAddDisabledReason(c),
      canClose: d.canCloseCourse(c),
      needsScheduling: open && ['quick_add', 'arrange_makeup'].includes(planning),
      renewalDue: open && d.isSessionMode(c) && d.purchaseActionIsRenew(c),
      purchaseLabel: d.purchaseActionLabel(c),
      contractAmended: d.effectiveClosedReason(c) === 'contract_amended',
      packagePreview: d.isSessionMode(c) && !d.isPackageMember(c),
      paymentNotice: d.isPaymentNoticeAvailable(c),
    });
  }

  // 調課／代課 open the next upcoming lesson already in that mode (same entry the date chip's dialog uses).
  async function moveNextLesson(c, mode) {
    await d.openSessionEditFromAction(c);
    if (!d.isSessionEditOpen()) return;
    if (mode === 'reschedule') d.startSessionReschedule();
    else if (d.featureSubstituteV2) d.openSubstituteV2FromEdit();
    else d.startSubstitute();
  }

  function runRowAction(c, id) {
    const map = {
      edit: () => d.editCourse(c),
      details: () => d.toggleDatesAndMakeups(c),
      resume: () => d.requestCoursePause(c),
      pause: () => d.requestCoursePause(c),
      close: () => d.closeCourseInPlace(c),
      delete: () => d.requestDelete(c),
      'manual-session': () => d.openManualSessionModal(c),
      'monthly-session': () => d.openMonthlySessionModal(c),
      'quick-add': () => d.canQuickAddSession(c) && d.openQuickAddSessionModal(c),
      reschedule: () => moveNextLesson(c, 'reschedule'),
      substitute: () => moveNextLesson(c, 'substitute'),
      transfer: () => d.openCourseTransfer(c),
      duplicate: () => d.duplicateCourseForTeacher(c),
      purchase: () => d.openCommercialPurchaseEntry(c),
      'contract-adjust': () => d.openContractAdjustmentModal(c),
      'contract-revert': () => d.openContractRevertModal(c),
      'package-preview': () => d.openPackageConversionPreview(c),
      'payment-slip': () => d.openPaymentSlip(c),
      invoice: () => d.openTuitionLedger(c),
    };
    return map[id]?.();
  }

  return { rowModel, runRowAction };
}
