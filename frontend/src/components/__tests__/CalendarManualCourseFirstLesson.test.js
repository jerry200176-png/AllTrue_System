import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import EnrollmentConflictDecisionModal from '../EnrollmentConflictDecisionModal.vue';

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
  it('duplicate modal offers 新增下一堂 for a manual course with lessons left and nothing scheduled', async () => {
    const w = mountModal([conflict()]);
    const btn = w.findAll('button').find((b) => b.text() === '新增下一堂');
    expect(btn).toBeTruthy();
    await btn.trigger('click');
    expect(w.emitted('manual-session')[0][0].existing_course_id).toBe(4296);
  });

  it('keeps 加購／延續 when the manual course already has a lesson or none left', () => {
    for (const c of [conflict({ future_session_count: 1 }), conflict({ remaining_sessions: 0 }), conflict({ scheduling_policy: 'auto_recurrence' })]) {
      expect(mountModal([c]).findAll('button').some((b) => b.text() === '新增下一堂')).toBe(false);
    }
  });

  it('the scheduler marks a manual create; calendar and students list hand off to course management', () => {
    expect(scheduler).toContain("emit('success', { ...result, scheduling_policy: 'manual_occurrence' });");
    for (const page of [calendar, students]) {
      expect(page).toContain("emit('navigate', { target: 'course-mgmt', studentId, courseId, intent: 'manual-session' });");
      expect(page).toMatch(/result\?\.scheduling_policy === 'manual_occurrence' && result\.student_class_id/);
    }
    expect(students).toContain('@manual-session=');
  });

  it('course management opens the modal for that course, or says it cannot find it', () => {
    expect(courseMgmt).toContain("} else if (props.initialCourseIntent === 'manual-session') {");
    expect(courseMgmt).toContain('@manual-session="interceptOpenManualSessionCM"');
    expect(courseMgmt).toMatch(/if \(course\) openManualSessionModal\(course\);\s*else alert\(/);
  });
});
