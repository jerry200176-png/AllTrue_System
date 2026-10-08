import { describe, expect, it, vi } from 'vitest';
import { useCourseRowActions } from '../useCourseRowActions.js';

const bag = (over = {}) => {
  const fn = () => vi.fn();
  return {
    effectiveClosedReason: () => '', planningStatusVisible: () => false, planningStatusFor: () => null,
    isSessionMode: () => true, isMonthlyMode: () => false, isManualOccurrenceCourse: () => false,
    canQuickAddSession: () => true, quickAddDisabledReason: () => '', canCloseCourse: () => true,
    purchaseActionIsRenew: () => false, purchaseActionLabel: () => '加購堂數', isPackageMember: () => false,
    isPaymentNoticeAvailable: () => false, featureSubstituteV2: false, isDetailsOpen: () => false,
    isSessionEditOpen: () => true, requestDelete: fn(), openCourseTransfer: fn(),
    openSessionEditFromAction: vi.fn(async () => {}), startSessionReschedule: fn(), startSubstitute: fn(), openSubstituteV2FromEdit: fn(),
    editCourse: fn(), toggleDatesAndMakeups: fn(), requestCoursePause: fn(), closeCourseInPlace: fn(), openManualSessionModal: fn(),
    openMonthlySessionModal: fn(), openQuickAddSessionModal: fn(), duplicateCourseForTeacher: fn(), openCommercialPurchaseEntry: fn(),
    openContractAdjustmentModal: fn(), openContractRevertModal: fn(), openPackageConversionPreview: fn(), openPaymentSlip: fn(), openTuitionLedger: fn(),
    ...over,
  };
};
const course = { id: 7, status: 'active' };
const ids = (m) => m.groups.flatMap((g) => g.items.map((i) => i.id));

describe('useCourseRowActions', () => {
  it('a plain course: primary 編輯, 詳情 + 調動 items in ⋯, never a second primary', () => {
    const { rowModel } = useCourseRowActions(bag());
    const m = rowModel(course);
    expect(m.primary).toEqual({ id: 'edit', label: '編輯' });
    expect(ids(m)).toEqual(expect.arrayContaining(['details', 'reschedule', 'substitute', 'transfer', 'close', 'delete']));
    expect(ids(m)).not.toContain('edit');
  });

  it('state decides the primary: paused → 恢復課程, low remaining → 續報加購, planning hint → 排課', () => {
    expect(useCourseRowActions(bag()).rowModel({ ...course, status: 'inactive' }).primary.id).toBe('resume');
    expect(useCourseRowActions(bag({ purchaseActionIsRenew: () => true, purchaseActionLabel: () => '續報加購' })).rowModel(course).primary)
      .toEqual({ id: 'purchase', label: '續報加購' });
    expect(useCourseRowActions(bag({ planningStatusVisible: () => true, planningStatusFor: () => ({ action: 'quick_add' }) })).rowModel(course).primary.id)
      .toBe('manual-session');
  });

  it('a closed or settled course is never pushed to renew or schedule', () => {
    const d = bag({ effectiveClosedReason: () => 'settled', purchaseActionIsRenew: () => true, planningStatusVisible: () => true, planningStatusFor: () => ({ action: 'quick_add' }) });
    expect(useCourseRowActions(d).rowModel(course).primary.id).toBe('edit');
  });

  it('monthly courses are not renewal-due just because the purchase action is 結算', () => {
    const d = bag({ isSessionMode: () => false, isMonthlyMode: () => true, purchaseActionIsRenew: () => true });
    expect(useCourseRowActions(d).rowModel(course).primary.id).toBe('edit');
  });

  it('補課 stays visible but disabled with the page reason', () => {
    const d = bag({ canQuickAddSession: () => false, quickAddDisabledReason: () => '課程已暫停，請先恢復後再補課' });
    const item = useCourseRowActions(d).rowModel(course).groups.flatMap((g) => g.items).find((i) => i.id === 'quick-add');
    expect(item).toMatchObject({ disabled: true, reason: '課程已暫停，請先恢復後再補課' });
  });

  it('routes ids to the page handlers; disabled quick-add does nothing', async () => {
    const d = bag();
    const { runRowAction } = useCourseRowActions(d);
    runRowAction(course, 'edit'); runRowAction(course, 'details'); runRowAction(course, 'delete'); runRowAction(course, 'transfer');
    runRowAction(course, 'purchase'); runRowAction(course, 'invoice'); runRowAction(course, 'close'); runRowAction(course, 'quick-add');
    expect(d.editCourse).toHaveBeenCalledWith(course);
    expect(d.toggleDatesAndMakeups).toHaveBeenCalledWith(course);
    expect(d.requestDelete).toHaveBeenCalledWith(course);
    expect(d.openCourseTransfer).toHaveBeenCalledWith(course);
    expect(d.openCommercialPurchaseEntry).toHaveBeenCalledWith(course);
    expect(d.openTuitionLedger).toHaveBeenCalledWith(course);
    expect(d.closeCourseInPlace).toHaveBeenCalledWith(course);
    expect(d.openQuickAddSessionModal).toHaveBeenCalledWith(course);
    const blocked = bag({ canQuickAddSession: () => false });
    useCourseRowActions(blocked).runRowAction(course, 'quick-add');
    expect(blocked.openQuickAddSessionModal).not.toHaveBeenCalled();
  });

  it('調課 / 代課 open the next lesson in that mode, and only when the lesson modal really opened', async () => {
    const d = bag();
    const { runRowAction } = useCourseRowActions(d);
    await runRowAction(course, 'reschedule');
    expect(d.openSessionEditFromAction).toHaveBeenCalledWith(course);
    expect(d.startSessionReschedule).toHaveBeenCalledTimes(1);
    await runRowAction(course, 'substitute');
    expect(d.startSubstitute).toHaveBeenCalledTimes(1);
    const v2 = bag({ featureSubstituteV2: true });
    await useCourseRowActions(v2).runRowAction(course, 'substitute');
    expect(v2.openSubstituteV2FromEdit).toHaveBeenCalledTimes(1);
    const closed = bag({ isSessionEditOpen: () => false });
    await useCourseRowActions(closed).runRowAction(course, 'reschedule');
    expect(closed.startSessionReschedule).not.toHaveBeenCalled();
  });
});
