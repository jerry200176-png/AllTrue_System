import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

// in-app #382: a 逐堂手動排課 course created from the calendar has no lesson, so the calendar must hand off to
// course management's 新增下一堂 instead of leaving the course invisible (and the duplicate check pointing at 加購).
const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const scheduler = read('../UniversalClassScheduler.vue');
const calendar = read('../../pages/SmartCalendar.vue');
const courseMgmt = read('../../pages/CourseManagement.vue');

describe('calendar manual course → first lesson (in-app #382)', () => {
  it('the scheduler marks a manual create in its success payload', () => {
    expect(scheduler).toContain("emit('success', { ...result, scheduling_policy: 'manual_occurrence' });");
  });

  it('the calendar opens 新增下一堂 in course management after a manual create', () => {
    expect(calendar).toContain("if (result?.scheduling_policy === 'manual_occurrence' && result.student_class_id) {");
    expect(calendar).toContain("emit('navigate', { target: 'course-mgmt', studentId, courseId, intent: 'manual-session' });");
  });

  it('the duplicate modal offers 新增下一堂 for a manual course with nothing upcoming', () => {
    expect(calendar).toContain(`v-if="c.scheduling_policy === 'manual_occurrence' && !c.future_session_count"`);
    expect(calendar).toContain('openManualSessionInCourseMgmt(interceptOriginalPayload?.student_id, c.existing_course_id)');
  });

  it('course management opens the manual-session modal for that course once loaded', () => {
    expect(courseMgmt).toContain("} else if (props.initialCourseIntent === 'manual-session') {");
    expect(courseMgmt).toMatch(/pendingManualSessionId\.value\);\s*pendingManualSessionId\.value = 0;[\s\S]{0,80}if \(course\) openManualSessionModal\(course\);/);
  });
});
