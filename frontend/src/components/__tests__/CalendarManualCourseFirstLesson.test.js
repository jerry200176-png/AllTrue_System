import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import EnrollmentConflictDecisionModal from '../EnrollmentConflictDecisionModal.vue';
import { needsFirstManualLesson, normalizeDuplicateConflicts } from '../../lib/enrollmentConflictDecision.js';

// in-app #382: a 逐堂手動排課 course has no lesson until 新增下一堂. Every create entry without a course card must hand
// off to that flow, and the duplicate check must offer it instead of 加購 / a second empty course.
const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const scheduler = read('../UniversalClassScheduler.vue');
const calendar = read('../../pages/SmartCalendar.vue');
const students = read('../../pages/StudentsList.vue');
const courseMgmt = read('../../pages/CourseManagement.vue');

const conflict = (over = {}) => ({
  existing_course_id: 4296, subject: 'Math', class_type: 'tutoring', remaining_sessions: 1,
  scheduling_policy: 'manual_occurrence', future_session_count: 0, ...over,
});
const mountModal = (conflicts) => mount(EnrollmentConflictDecisionModal, {
  props: { show: true, conflicts, classType: 'tutoring', subjectLabelFn: (s) => s },
});

describe('manual course → first lesson (in-app #382)', () => {
  it('trial duplicate modal offers 新增下一堂 (not 加購) for an empty manual trial (#3745)', async () => {
    const w = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [conflict({ class_type: 'trial' })], classType: 'trial', subjectLabelFn: (s) => s },
    });
    const btn = w.findAll('button').find((b) => b.text() === '新增下一堂');
    expect(btn).toBeTruthy();
    await btn.trigger('click');
    expect(w.emitted('manual-session')[0][0].existing_course_id).toBe(4296);
    expect(w.findAll('button').some((b) => b.text() === '續課')).toBe(false);
    // A trial that already has lessons keeps the action column hidden.
    const plain = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [conflict({ class_type: 'trial', future_session_count: 2 })], classType: 'trial' },
    });
    expect(plain.text()).not.toContain('操作');
  });

  it('duplicate modal offers 新增下一堂 for a manual course with lessons left and nothing scheduled', async () => {
    const w = mountModal([conflict()]);
    const btn = w.findAll('button').find((b) => b.text() === '新增下一堂');
    expect(btn).toBeTruthy();
    await btn.trigger('click');
    expect(w.emitted('manual-session')[0][0].existing_course_id).toBe(4296);
    // 加購／延續 stays available next to it.
    expect(w.findAll('button').some((b) => b.text() === '延續輔導課（不收費）')).toBe(true);
  });

  it('teachers get no 續課 (no students page for them) and the calendar passes that capability (#3965)', () => {
    const regular = { existing_course_id: 51, subject: 'Math', class_type: 'one_on_one', remaining_sessions: 4, schedule_mode: 'count' };
    const teacher = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [regular], classType: 'one_on_one', canPurchase: false, canManualSession: false },
    });
    expect(teacher.findAll('button').some((b) => b.text() === '續課')).toBe(false);
    expect(teacher.findAll('th').map((th) => th.text())).not.toContain('操作');
    expect(teacher.text()).not.toContain('從既有課程延續');
    const director = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [regular], classType: 'one_on_one' },
    });
    expect(director.findAll('button').some((b) => b.text() === '續課')).toBe(true);
    expect(director.text()).toContain('從既有課程延續');
    expect(calendar).toContain(':can-purchase="!isTeacher"');
  });

  it('an overlapping date range does not offer 建立下一期續報, which the server always refuses (#3965)', () => {
    const base = { existing_course_id: 52, subject: 'Math', class_type: 'one_on_one', remaining_sessions: 4, schedule_mode: 'count' };
    const overlap = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, classType: 'one_on_one', attemptedPaymentType: 'session',
        conflicts: [{ ...base, existing_range: ['2026-10-01', '2026-12-31'], new_range: ['2026-11-01', '2027-01-31'] }] },
    });
    expect(overlap.text()).not.toContain('建立下一期續報');
    expect(overlap.text()).toContain('建立獨立課程');
    const duplicate = mount(EnrollmentConflictDecisionModal, {
      props: { show: true, classType: 'one_on_one', attemptedPaymentType: 'session', conflicts: [base] },
    });
    expect(duplicate.text()).toContain('建立下一期續報');
  });

  it('pages keep the server fields through the shared mapper, so the modal can still decide', () => {
    // Raw 409 conflict as EnrollmentService returns it; every page maps it with normalizeDuplicateConflicts.
    const [mapped] = normalizeDuplicateConflicts([{ existing_course_id: 4296, subject: 'Math', class_type: 'tutoring',
      remaining_sessions: 1, scheduling_policy: 'manual_occurrence', future_session_count: 0 }]);
    expect(needsFirstManualLesson(mapped)).toBe(true);
    expect(mountModal([mapped]).findAll('button').some((b) => b.text() === '新增下一堂')).toBe(true);
    for (const page of [calendar, students, courseMgmt]) {
      expect(page).toContain('duplicateConflicts.value = normalizeDuplicateConflicts(evt?.conflicts);');
    }
    expect(needsFirstManualLesson({ ...mapped, future_session_count: undefined })).toBe(false);
  });

  it('keeps 加購／延續 when the manual course already has a lesson or none left', () => {
    for (const c of [conflict({ future_session_count: 1 }), conflict({ remaining_sessions: 0 }), conflict({ scheduling_policy: 'auto_recurrence' })]) {
      expect(mountModal([c]).findAll('button').some((b) => b.text() === '新增下一堂')).toBe(false);
    }
  });

  it('the scheduler marks a manual create; calendar and students list hand off to course management', () => {
    expect(scheduler).toContain("emit('success', { ...result, scheduling_policy: 'manual_occurrence' });");
    for (const page of [calendar, students]) {
      expect(page).toContain("emit('navigate', buildManualSessionNav(studentId, courseId))");
      expect(page).toMatch(/result\?\.scheduling_policy === 'manual_occurrence' && result\.student_class_id/);
    }
    expect(students).toContain('@manual-session=');
    // a forced ("create anyway") manual course takes the same path in both pages
    for (const page of [students, courseMgmt, calendar]) {
      expect(page).toMatch(/payload\.scheduling_policy === 'manual_occurrence' && result\?\.student_class_id/);
    }
    expect(students).toContain('interceptPendingStudent?._laravelId ?? interceptPendingStudent?.id');
  });

  it('course management opens the modal for that course, or says it cannot find it', () => {
    expect(courseMgmt).toContain("} else if (props.initialCourseIntent === 'manual-session') {");
    expect(courseMgmt).toContain('@manual-session="interceptOpenManualSessionCM"');
    expect(courseMgmt).toMatch(/if \(course\) \{\s*handoffNarrowedList\.value = true;\s*openManualSessionModal\(course\);/);
    // its own scheduler hands a manual create to the same flow; the narrowed list comes back when the modal closes
    expect(courseMgmt).toMatch(/async function handleUniversalBackfillSuccess[\s\S]{0,400}pendingManualSessionId\.value = Number\(result\.student_class_id\)/);
    expect(courseMgmt).toMatch(/if \(handoffNarrowedList\.value\) \{\s*handoffNarrowedList\.value = false;\s*loadCourses\(1\);/);
  });
});

describe('calendar trial duplicate must not create a second trial (#3738 review)', () => {
  const trialMount = (blockCreate) => mount(EnrollmentConflictDecisionModal, {
    props: { show: true, conflicts: [conflict({ class_type: 'trial' })], classType: 'trial', blockCreate },
  });
  it('blockCreate hides 建立試聽, and emits no create_trial', async () => {
    const w = trialMount(true);
    expect(w.findAll('button').some((b) => b.text() === '建立試聽')).toBe(false);
    w.vm.$.setupState.emitDecision('create_trial');
    expect(w.emitted('decision')).toBeUndefined();
  });
  it('SmartCalendar blocks create for trial payloads and refuses to force one', () => {
    expect(calendar).toContain(":block-create=\"interceptOriginalPayload?.class_type === 'trial'\"");
    expect(calendar).toContain("if (!payload || payload.class_type === 'trial')");
  });
});

describe('calendar conflict 去加購 and force-create reason (#3738)', () => {
  it('navigates to students with intent purchase, not a bare course-mgmt open', () => {
    expect(calendar).toMatch(/buildStudentsCommercialNav\(\{ id: conflict\.existing_course_id[^)]*\}, \{ intent: 'purchase' \}\)/);
    expect(calendar).not.toMatch(/function interceptGoToPurchase[\s\S]{0,300}buildCourseMgmtOpsNav/);
  });
  it('uses the shared decision modal and buildForceOverrideFields, never a bare force: true', () => {
    expect(calendar).toContain('<EnrollmentConflictDecisionModal');
    expect(calendar).toContain('buildForceOverrideFields(decision)');
    expect(calendar).not.toMatch(/force:\s*true/);
  });
});

describe('monthly contracts do not offer generic renewal (#3738 review)', () => {
  const renewalBtn = (mode) => mount(EnrollmentConflictDecisionModal, {
    props: { show: true, conflicts: [conflict({ class_type: 'one_on_one', schedule_mode: mode })], classType: 'one_on_one' },
  }).findAll('button').some((b) => b.text() === '建立下一期續報');
  it('hides 建立下一期續報 for a monthly conflict and keeps it for a session-count one', () => {
    expect(renewalBtn('date')).toBe(false);
    expect(renewalBtn('count')).toBe(true);
  });
  it('hides 建立下一期續報 when the attempted payload is monthly, even if the existing course is count-mode', () => {
    const btn = (attempted) => mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [conflict({ class_type: 'one_on_one', schedule_mode: 'count' })], classType: 'one_on_one', attemptedPaymentType: attempted },
    }).findAll('button').some((b) => b.text() === '建立下一期續報');
    expect(btn('monthly')).toBe(false);
    expect(btn('session')).toBe(true);
  });
  it('pages pass the attempted payment type to the modal', () => {
    expect(calendar).toContain(':attempted-payment-type=');
    expect(courseMgmt).toContain(':attempted-payment-type=');
  });
});

describe('teachers are not offered 新增下一堂 (#3738 review)', () => {
  it('hides the manual-session CTA when canManualSession is false, shows it by default', () => {
    const has = (props) => mount(EnrollmentConflictDecisionModal, {
      props: { show: true, conflicts: [conflict()], classType: 'tutoring', ...props },
    }).findAll('button').some((b) => b.text() === '新增下一堂');
    expect(has({})).toBe(true);
    expect(has({ canManualSession: false })).toBe(false);
    expect(calendar).toContain(':can-manual-session="!isTeacher"');
  });
});
