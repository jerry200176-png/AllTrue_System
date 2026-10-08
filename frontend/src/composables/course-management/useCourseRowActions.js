import { courseActions } from '../../lib/courseActions.js';

/**
 * Course-row glue (課程查找 C-PR2): feeds the page's own capability helpers into courseActions()
 * and routes a picked action id to the handler the old row buttons already called.
 * `d` is a bag of page functions; nothing here re-derives billing or scheduling rules.
 */
export function useCourseRowActions(d) {
  // The drawer passes { fallback: 'manage', details: false }: it is already managing, so no 管理課程/詳情 item.
  function rowModel(c, { fallback = 'edit', details = true } = {}) {
    const open = c.status !== 'inactive' && !d.effectiveClosedReason(c);
    const planning = d.planningStatusVisible(c) ? d.planningStatusFor(c)?.action : null;
    return courseActions(c, {
      fallback,
      details,
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

  // 調課／代課 open the next upcoming lesson already in that mode, with the next 6 lessons in a picker
  // (Google Calendar "which event?"). A single occurrence only: series edits stay in 合約／堂次調整.
  async function enterMode(mode) {
    if (!d.isSessionEditOpen()) return;
    if (mode === 'reschedule') d.startSessionReschedule();
    else if (d.featureSubstituteV2) d.openSubstituteV2FromEdit();
    else d.startSubstitute();
  }
  async function moveNextLesson(c, mode) {
    const options = d.upcomingLessonOptions(c, 6);
    const first = options[0];
    if (first) await d.openSessionEdit(c, first.date, first.id, first.unit);
    else await d.openSessionEditFromAction(c);
    if (!d.isSessionEditOpen()) return;
    d.setLessonPicker(options.length > 1 ? { course: c, mode, options, key: first.key } : null);
    await enterMode(mode);
  }
  // One click on a specific lesson row (no picker: the lesson is already chosen).
  async function moveLesson(c, mode, unit) {
    d.setLessonPicker(null);
    await d.openSessionEdit(c, String(unit?.date || '').slice(0, 10), unit?.id, unit);
    await enterMode(mode);
  }
  async function pickLesson(key) {
    const picker = d.getLessonPicker();
    const opt = picker?.options.find((o) => o.key === key);
    if (!opt) return;
    d.setLessonPicker({ ...picker, key });
    await d.openSessionEdit(picker.course, opt.date, opt.id, opt.unit);
    await enterMode(picker.mode);
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

  return { rowModel, runRowAction, moveLesson, pickLesson };
}
