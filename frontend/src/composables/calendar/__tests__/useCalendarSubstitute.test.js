import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarSubstitute } from '../useCalendarSubstitute.js';

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
    expect(substituteV2Context.value.prefill_substitute_teacher_id).toBe(8);
    expect(substituteV2Context.value.prefill_new_start_time).toBe('18:00');
    expect(substituteV2Context.value.allow_past_same_date).toBe(true);
  });

  it('drag on substituted card keeps contract teacher as original (CM parity / 游喨鈞→Coco)', () => {
    const sessionDatesByCourseId = ref({
      2366: [{ id: 22359, session_date: '2026-07-28', start_time: '14:00', status: 'scheduled' }],
    });
    const courses = ref([
      { id: 2366, student_id: 41, subject: 'English', teacher_id: 70, teacher_name: 'Coco' },
    ]);
    const teachers = ref([
      { id: 70, name: 'Coco' },
      { id: 202, name: '鄒宇旻' },
    ]);
    const {
      openSubstituteFromDrag,
      substituteV2Context,
      showSubstituteV2Modal,
    } = useCalendarSubstitute(makeDeps({ sessionDatesByCourseId, courses, teachers }));

    // Week card after merge: teacher_id is effective substitute (鄒宇旻), not contract Coco.
    openSubstituteFromDrag({
      id: 2366,
      student_course_id: 2366,
      is_exception: true,
      student_id: 41,
      subject: 'English',
      teacher_id: 202,
      teacher_name: '鄒宇旻',
      start_time: '14:00',
      end_time: '16:00',
    }, '2026-07-28', 70);

    expect(showSubstituteV2Modal.value).toBe(true);
    expect(substituteV2Context.value.original_teacher_id).toBe(70);
    expect(substituteV2Context.value.original_teacher_name).toBe('Coco');
    expect(substituteV2Context.value.current_teacher_id).toBe(202);
    expect(substituteV2Context.value.current_teacher_name).toBe('鄒宇旻');
    expect(substituteV2Context.value.prefill_substitute_teacher_id).toBe(70);
  });

  it('openSubstituteV2Modal uses modalForm contract vs current_teacher_id', () => {
    const sessionDatesByCourseId = ref({
      2366: [{ id: 22359, session_date: '2026-07-28', start_time: '14:00', status: 'scheduled' }],
    });
    const modalForm = ref({
      student_id: 41,
      subject: 'English',
      teacher_id: 70,
      current_teacher_id: 202,
      current_teacher_name: '鄒宇旻',
      start_time: '14:00',
      end_time: '16:00',
      action_date: '2026-07-28',
    });
    const teachers = ref([
      { id: 70, name: 'Coco' },
      { id: 202, name: '鄒宇旻' },
    ]);
    const showModal = ref(true);
    const {
      openSubstituteV2Modal,
      substituteV2Context,
      showSubstituteV2Modal,
    } = useCalendarSubstitute(makeDeps({
      sessionDatesByCourseId,
      modalForm,
      showModal,
      editingCourseId: ref(2366),
      teachers,
    }));

    openSubstituteV2Modal();
    expect(showModal.value).toBe(false);
    expect(showSubstituteV2Modal.value).toBe(true);
    expect(substituteV2Context.value.original_teacher_id).toBe(70);
    expect(substituteV2Context.value.current_teacher_id).toBe(202);
  });
});
