import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import { useSessionEditFlow } from '../useSessionEditFlow';
import { lessonPickerKey, useCourseRowActions } from '../useCourseRowActions';

// P1 from #3828: picking lesson B in the next-lessons picker must make 調課 / 代課 act on B (never A).
// Real useSessionEditFlow + real useCourseRowActions; only the page's data lookups are faked.

const course = { id: 42, student_name: '王小明', subject: 'math', payment_type: 'session', ScheduleMode: 'count', duration_hours: 2 };
const row = (id, date) => ({ id, studentClassId: 42, startTime: '18:30', endTime: '20:30', status: 'scheduled', teacherId: 7, teacherName: 'T', note: '', sessionCharge: 1000, contractRate: 1000, contractSessionDuration: 120, contractRateUnit: 'session', date });
const A = row(11, '2026-07-01');
const C = row(13, '2026-07-15');
const D = row(14, '2026-07-22');
const unit = (r) => ({ id: r.id, date: r.date, startTime: r.startTime, endTime: r.endTime, isProjected: false });
// B is a count-mode 預排 date: no ClassSession exists yet, so the lesson dialog cannot open it.
const B = { id: null, date: '2026-07-08', startTime: '18:30', endTime: '20:30', isProjected: true };
const units = [unit(A), B, unit(C), unit(D)];
const rowsById = { 11: A, 13: C, 14: D };

function setup({ useV2 = false, missing = [] } = {}) {
  const flow = useSessionEditFlow({
    supabase: { auth: { getSession: vi.fn().mockResolvedValue({ data: { session: { access_token: 't' } } }) } },
    branchId: { value: 16 },
    computeEndTime: vi.fn(), normalizeTo30Min: vi.fn(), dayOfWeekFromDate: vi.fn(), formatAttendanceTooltipTime: () => '',
    updateLocalSessionRow: vi.fn(), ensureCompletedSessionDatesLoaded: vi.fn(),
    displaySessions: () => units.map((u) => u.date),
    todayYmd: { value: '2026-06-27' },
    rescheduleCourse: vi.fn(), rescheduleForm: { value: {} }, fetchMakeupSlots: vi.fn(), loadCourses: vi.fn(), openQuickAddSessionModal: vi.fn(),
    reloadCourseSessions: vi.fn(async () => false),
    getSessionDisplayRow: (_c, _date, id) => (id && !missing.includes(id) ? rowsById[id] : null),
    getSessionRowsForDate: () => [],
  });
  const picker = ref(null);
  const v2 = { open: ref(false), sessionId: ref(null), context: ref({}) };
  const openSubstituteV2FromEdit = () => { // same shape as the page's openSubstituteV2FromEdit
    v2.sessionId.value = flow.sessionEditForm.value.session_id;
    v2.context.value = { session_date: flow.sessionEditForm.value.session_date, start_time: flow.sessionEditForm.value.start_time };
    flow.closeSessionEdit();
    v2.open.value = true;
  };
  const currentLesson = () => (flow.showSessionEditModal.value
    ? { id: flow.sessionEditForm.value.session_id, date: flow.sessionEditForm.value.session_date, start: flow.sessionEditForm.value.start_time }
    : { id: v2.sessionId.value, date: v2.context.value.session_date, start: v2.context.value.start_time });
  const view = computed(() => (picker.value ? { ...picker.value, key: lessonPickerKey(picker.value, currentLesson()) } : null));
  const actions = useCourseRowActions({
    effectiveClosedReason: () => '', planningStatusVisible: () => false, planningStatusFor: () => null,
    isSessionMode: () => true, isMonthlyMode: () => false, isManualOccurrenceCourse: () => false,
    canQuickAddSession: () => true, quickAddDisabledReason: () => '', canCloseCourse: () => true,
    purchaseActionIsRenew: () => false, purchaseActionLabel: () => '加購堂數', isPackageMember: () => false,
    isPaymentNoticeAvailable: () => false, isDetailsOpen: () => false,
    featureSubstituteV2: useV2,
    isSessionEditOpen: () => flow.showSessionEditModal.value,
    upcomingLessonOptions: () => units.map((u, i) => ({ key: `k${i}`, date: u.date, id: u.id, unit: u, label: u.date })),
    canOpenLesson: (_c, u) => Boolean(u?.id),
    currentLesson,
    openSessionEdit: flow.openSessionEdit, openSessionEditFromAction: flow.openSessionEditFromAction,
    startSessionReschedule: flow.startSessionReschedule, startSubstitute: flow.startSubstitute, openSubstituteV2FromEdit,
    setLessonPicker: (p) => { picker.value = p; }, getLessonPicker: () => picker.value,
  });
  // What the dialogs show / what a confirm would submit: the single source of truth is the lesson in the open dialog.
  const acting = () => {
    const cur = currentLesson();
    return { id: cur.id, date: cur.date, mode: v2.open.value ? 'substitute-v2' : flow.sessionEditMode.value };
  };
  return { flow, actions, picker, view, v2, acting };
}

afterEach(() => vi.restoreAllMocks());
vi.spyOn(globalThis, 'alert').mockImplementation(() => {});

describe('lesson picker acts on the lesson it shows (P1 #3828)', () => {
  it('every offered lesson can really be opened; the unopenable 預排 date is not offered', async () => {
    const { actions, view } = setup();
    await actions.runRowAction(course, 'reschedule');
    expect(view.value.options.map((o) => o.date)).toEqual(['2026-07-01', '2026-07-15', '2026-07-22']);
  });

  it('A → C → D → A: after each pick, 調課 is in progress on exactly that lesson and the picker shows it', async () => {
    const { actions, view, acting, flow } = setup();
    await actions.runRowAction(course, 'reschedule');
    expect(acting()).toEqual({ id: 11, date: '2026-07-01', mode: 'reschedule' });
    for (const [date, id] of [['2026-07-15', 13], ['2026-07-22', 14], ['2026-07-01', 11]]) {
      const key = view.value.options.find((o) => o.date === date).key;
      await actions.pickLesson(key);
      expect(acting()).toEqual({ id, date, mode: 'reschedule' });
      expect(view.value.key).toBe(key);
      expect(flow.sessionEditForm.value.new_date).toBe(''); // reschedule fields start clean for the new lesson
    }
  });

  it('代課 (non-V2): same guarantee', async () => {
    const { actions, view, acting } = setup();
    await actions.runRowAction(course, 'substitute');
    await actions.pickLesson(view.value.options[2].key);
    expect(acting()).toEqual({ id: 14, date: '2026-07-22', mode: 'substitute' });
  });

  it('代課 V2: the picker context and session id switch together to the picked lesson', async () => {
    const { actions, view, acting, v2 } = setup({ useV2: true });
    await actions.runRowAction(course, 'substitute');
    expect(acting()).toEqual({ id: 11, date: '2026-07-01', mode: 'substitute-v2' });
    await actions.pickLesson(view.value.options[1].key);
    expect(acting()).toEqual({ id: 13, date: '2026-07-15', mode: 'substitute-v2' });
    expect(v2.sessionId.value).toBe(13);
    await actions.pickLesson(view.value.options[0].key);
    expect(acting()).toEqual({ id: 11, date: '2026-07-01', mode: 'substitute-v2' });
    expect(view.value.key).toBe(view.value.options[0].key);
  });

  it('a lesson that fails to open leaves the real lesson untouched and the picker still showing it', async () => {
    const { actions, view, acting, flow } = setup({ missing: [13] });
    await actions.runRowAction(course, 'reschedule');
    const before = view.value.key;
    await actions.pickLesson(view.value.options[1].key); // C cannot resolve → resolve dialog
    expect(flow.chipActionDialog.value?.kind).toBe('resolve_retry');
    expect(acting()).toMatchObject({ id: 11, date: '2026-07-01' });
    expect(view.value.key).toBe(before);
    expect(view.value.busy).toBe(false);
  });

  it('inline lesson buttons: the lesson row decides, the 預排 date is refused, and no picker is shown', async () => {
    const { actions, view, acting, picker } = setup();
    await actions.moveLesson(course, 'reschedule', unit(D));
    expect(acting()).toEqual({ id: 14, date: '2026-07-22', mode: 'reschedule' });
    expect(picker.value).toBeNull();
    expect(view.value).toBeNull();
    await actions.moveLesson(course, 'substitute', B); // unopenable: nothing changes
    expect(acting()).toEqual({ id: 14, date: '2026-07-22', mode: 'reschedule' });
  });

  it('ignores a second pick while one is still opening', async () => {
    const { actions, view, acting } = setup();
    await actions.runRowAction(course, 'reschedule');
    const [, c, d] = view.value.options;
    const first = actions.pickLesson(c.key);
    const second = actions.pickLesson(d.key); // busy → ignored
    await Promise.all([first, second]);
    expect(acting()).toEqual({ id: 13, date: '2026-07-15', mode: 'reschedule' });
  });
});
