import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarSubstitute, substituteTeacherIds } from '../useCalendarSubstitute.js';

function makeDeps(overrides = {}) {
  return {
    branchId: computed(() => 2),
    showModal: ref(true),
    modalForm: ref({
      student_id: 10,
      subject: 'Math',
      teacher_id: 5,
      start_time: '16:00',
      end_time: '18:00',
      action_date: '2026-06-11',
    }),
    editingCourseId: ref(99),
    loadCourses: vi.fn(async () => {}),
    teachers: ref([{ id: 5, name: '王老師' }]),
    sessionDatesByCourseId: ref({}),
    allStudents: ref([{ id: 10, name: '小明' }]),
    getSubjectLabel: (v) => (v === 'Math' ? '數學' : v),
    ...overrides,
  };
}

describe('useCalendarSubstitute', () => {
  it('substituteDisplay reflects student and session slot', () => {
    const { substituteForm, substituteDisplay } = useCalendarSubstitute(makeDeps());
    substituteForm.value = {
      student_id: 10,
      subject: 'Math',
      session_date: '2026-06-11',
      start_time: '16:00',
      end_time: '18:00',
    };
    expect(substituteDisplay.value.studentName).toBe('小明');
    expect(substituteDisplay.value.subjectLabel).toBe('數學');
    expect(substituteDisplay.value.sessionSlot).toBe('2026-06-11 16:00~18:00');
  });

  it('openSubstituteModal closes session edit modal and opens substitute modal', () => {
    const showModal = ref(true);
    const { openSubstituteModal, substituteForm, showSubstituteModal } = useCalendarSubstitute(
      makeDeps({ showModal }),
    );
    openSubstituteModal();
    expect(showModal.value).toBe(false);
    expect(showSubstituteModal.value).toBe(true);
    expect(substituteForm.value.student_id).toBe(10);
    expect(substituteForm.value.session_date).toBe('2026-06-11');
  });

  it('cross-teacher drag with a time change opens atomic substitute+reschedule', () => {
    const sessionDatesByCourseId = ref({
      99: [{ id: 701, session_date: '2026-06-11', start_time: '17:30', status: 'scheduled' }],
    });
    const {
      openSubstituteFromDrag,
      showSubstituteV2Modal,
      showSubstituteModal,
      substituteV2SessionId,
      substituteV2Context,
    } = useCalendarSubstitute(makeDeps({ sessionDatesByCourseId }));

    openSubstituteFromDrag({
      id: 99,
      student_id: 10,
      subject: 'Math',
      teacher_id: 5,
      class_type: 'one_on_three',
      start_time: '17:30',
      end_time: '19:30',
    }, '2026-06-11', 8, {
      date: '2026-06-11',
      startTime: '18:00',
      endTime: '20:00',
    });

    expect(showSubstituteModal.value).toBe(false);
    expect(showSubstituteV2Modal.value).toBe(true);
    expect(substituteV2SessionId.value).toBe(701);
    expect(substituteV2Context.value.student_id).toBe(10);
    expect(substituteV2Context.value.class_type).toBe('one_on_three');
    expect(substituteV2Context.value.prefill_substitute_teacher_id).toBe(8);
    expect(substituteV2Context.value.prefill_new_start_time).toBe('18:00');
    expect(substituteV2Context.value.allow_past_same_date).toBe(true);
  });

  // in-app #376: course 2954, contract teacher 168, the 10/09 13:00 lesson taught by substitute 81.
  // The picker shows 「回正班老師」 only when original !== current, so both must be passed.
  it('substituteTeacherIds: contract teacher is original, the occurrence teacher is current', () => {
    expect(substituteTeacherIds(81, 168)).toEqual({ original_teacher_id: 168, current_teacher_id: 81 });
    expect(substituteTeacherIds(168, 168)).toEqual({ original_teacher_id: 168, current_teacher_id: 168 });
    expect(substituteTeacherIds('', 168)).toEqual({ original_teacher_id: 168, current_teacher_id: 168 });
  });

  it('click path: a substituted lesson opens the picker with both teachers (restore possible)', () => {
    const deps = makeDeps({
      modalForm: ref({ student_id: 10, subject: 'Math', teacher_id: 168, occurrence_teacher_id: 81,
        start_time: '13:00', end_time: '15:00', action_date: '2026-10-09' }),
      editingCourseId: ref(2954),
      sessionDatesByCourseId: ref({ 2954: [{ id: 43459, session_date: '2026-10-09', start_time: '13:00', status: 'scheduled', substituteNotice: true }] }),
    });
    const { openSubstituteV2Modal, substituteV2Context } = useCalendarSubstitute(deps);
    openSubstituteV2Modal();
    expect(substituteV2Context.value.original_teacher_id).toBe(168);
    expect(substituteV2Context.value.current_teacher_id).toBe(81);
    expect(substituteV2Context.value.substitute_notice).toBe(true);
  });

  // #3780 P1: an attended lesson pinned to the former contract teacher also has occurrence !== contract.
  it('click path: a history pin (no substitute notice) is not restorable', () => {
    const deps = makeDeps({
      modalForm: ref({ student_id: 10, subject: 'Math', teacher_id: 168, occurrence_teacher_id: 81,
        start_time: '13:00', end_time: '15:00', action_date: '2026-10-09' }),
      editingCourseId: ref(2954),
      sessionDatesByCourseId: ref({ 2954: [{ id: 43459, session_date: '2026-10-09', start_time: '13:00', status: 'attended', substituteNotice: false }] }),
    });
    const { openSubstituteV2Modal, substituteV2Context } = useCalendarSubstitute(deps);
    openSubstituteV2Modal();
    expect(substituteV2Context.value.substitute_notice).toBe(false);
  });

  it('drag path: a session-only course takes the contract teacher from the session row', () => {
    const deps = makeDeps({
      courses: ref([]),
      sessionDatesByCourseId: ref({ 2954: [{ id: 43459, session_date: '2026-10-09', start_time: '13:00', status: 'scheduled',
        contractTeacherId: 168, substituteNotice: true }] }),
    });
    const { openSubstituteFromDrag, substituteV2Context } = useCalendarSubstitute(deps);
    openSubstituteFromDrag({ is_exception: true, student_course_id: 2954, teacher_id: 81, student_id: 10,
      subject: 'Math', start_time: '13:00', end_time: '15:00' }, '2026-10-09', 168);
    expect(substituteV2Context.value.original_teacher_id).toBe(168);
    expect(substituteV2Context.value.current_teacher_id).toBe(81);
    expect(substituteV2Context.value.substitute_notice).toBe(true);
  });

  it('restore success shows a restore confirmation and no substitute undo', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ message: '已回復正班老師', restored_teacher_id: 168 }) }));
    const show = vi.fn();
    const api = useCalendarSubstitute(makeDeps());
    api.toastRef.value = { show };
    api.substituteV2SessionId.value = 43459;
    await api.onSubstituteV2Submit({ substitute_teacher_id: 168 });
    expect(show).toHaveBeenCalledTimes(1);
    expect(show.mock.calls[0][0].title).toBe('已回復正班老師');
    expect(show.mock.calls[0][0].onUndo).toBeUndefined();
  });

  it('drag path: the dragged substitute occurrence keeps the contract teacher as original', () => {
    const deps = makeDeps({
      courses: ref([{ id: 2954, teacher_id: 168 }]),
      sessionDatesByCourseId: ref({ 2954: [{ id: 43459, session_date: '2026-10-09', start_time: '13:00', status: 'scheduled' }] }),
    });
    const { openSubstituteFromDrag, substituteV2Context } = useCalendarSubstitute(deps);
    openSubstituteFromDrag({ is_exception: true, student_course_id: 2954, teacher_id: 81, student_id: 10,
      subject: 'Math', start_time: '13:00', end_time: '15:00' }, '2026-10-09', 168);
    expect(substituteV2Context.value.original_teacher_id).toBe(168);
    expect(substituteV2Context.value.current_teacher_id).toBe(81);
  });
});
