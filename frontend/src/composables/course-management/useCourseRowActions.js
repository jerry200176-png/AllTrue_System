import { courseActions } from '../../lib/courseActions.js';

/**
 * Course-row glue (課程查找 C-PR2): feeds the page's own capability helpers into courseActions()
 * and routes a picked action id to the handler the old row buttons already called.
 * `d` is a bag of page functions; nothing here re-derives billing or scheduling rules.
 */
/** Does picker option `opt` describe the lesson `cur` ({ id, date, start }) the dialog is showing? */
export function lessonMatches(opt, cur) {
  if (!opt || !cur) return false;
  if (opt.id && cur.id) return Number(opt.id) === Number(cur.id);
  const date = String(cur.date || '').slice(0, 10);
  if (!date || date !== String(opt.date || '').slice(0, 10)) return false;
  const start = String(opt.unit?.startTime || '').slice(0, 5);
  return !start || !cur.start || start === String(cur.start).slice(0, 5);
}

/** The picker's selection is derived from the lesson in the dialog, never stored. '' when it matches no option. */
export function lessonPickerKey(picker, cur) {
  return picker?.options.find((o) => lessonMatches(o, cur))?.key ?? '';
}

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
  //
  // P1 (#3828): the lesson being acted on has ONE source of truth, the open lesson dialog's form
  // (or the 代課 picker's context). The picker only *displays* it (see lessonPickerKey) and never stores
  // a selection of its own, and an action starts only after the dialog has really landed on that lesson.
  async function landOn(c, opt) {
    await d.openSessionEdit(c, opt.date, opt.id, opt.unit);
    return d.isSessionEditOpen() && lessonMatches(opt, d.currentLesson());
  }
  async function enterMode(mode) {
    if (!d.isSessionEditOpen()) return;
    if (mode === 'reschedule') d.startSessionReschedule();
    else if (d.featureSubstituteV2) d.openSubstituteV2FromEdit();
    else d.startSubstitute();
  }
  async function moveNextLesson(c, mode) {
    // Only lessons the dialog can open are offered (a count-mode 預排 date has no session to edit yet).
    const options = d.upcomingLessonOptions(c, 6).filter((o) => d.canOpenLesson(c, o.unit));
    const first = options[0];
    d.setLessonPicker(null);
    if (first) {
      if (!await landOn(c, first)) return;
    } else {
      await d.openSessionEditFromAction(c);
      if (!d.isSessionEditOpen()) return;
    }
    d.setLessonPicker(options.length > 1 ? { course: c, mode, options, busy: false } : null);
    await enterMode(mode);
  }
  // One click on a specific lesson row (no picker: the lesson is already chosen).
  async function moveLesson(c, mode, unit) {
    d.setLessonPicker(null);
    const opt = { date: String(unit?.date || '').slice(0, 10), id: unit?.id, unit };
    if (!d.canOpenLesson(c, unit) || !await landOn(c, opt)) return;
    await enterMode(mode);
  }
  async function pickLesson(key) {
    const picker = d.getLessonPicker();
    const opt = picker?.options.find((o) => o.key === key);
    if (!opt || picker.busy) return;
    if (lessonMatches(opt, d.currentLesson())) return; // already on it
    d.setLessonPicker({ ...picker, busy: true });
    try {
      if (!await landOn(picker.course, opt)) return; // the dialog explains why; the picker keeps showing the real lesson
      await enterMode(picker.mode);
    } finally {
      const now = d.getLessonPicker();
      if (now) d.setLessonPicker({ ...now, busy: false });
    }
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
