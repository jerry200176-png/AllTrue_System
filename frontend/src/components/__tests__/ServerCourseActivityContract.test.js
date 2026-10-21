import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as M from '../../lib/courseMoneyState.js';

// in-app #427: 進行中 / history is computed once on the server (StudentClassActivity) and put on every
// student-classes row; the 「已有進行中課程」 prompt and the create guard use the same verdict.
// The pages must read those flags, never re-derive them.
const __dirname = dirname(fileURLToPath(import.meta.url));
const studentsList = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
const courseManagement = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

describe('server-owned 進行中 verdict (in-app #427)', () => {
  it('reads the flags and nothing else', () => {
    expect(M.courseHistoryReason({ history_reason: 'completed', closed_reason: null })).toBe('completed');
    expect(M.courseHistoryReason({ closed_reason: 'settled', remaining_sessions: 0, payment_status: 'paid' })).toBeNull();
    expect(M.isActiveCourse({ is_active_course: true })).toBe(true);
    expect(M.isActiveCourse({ status: 'active', remaining_sessions: 5 })).toBe(false);
  });

  it('has no client-side copy of the rule', () => {
    for (const name of ['isHistoryCourse', 'isHiddenHistoricalCourse', 'isStudentPageHistoryCourse', 'isStudentPageActiveCourse']) {
      expect(M[name]).toBeUndefined();
    }
  });

  it('學生管理 maps and reads the server flags', () => {
    expect(studentsList.match(/history_reason: c\.history_reason \?\? null/g)).toHaveLength(2);
    expect(studentsList.match(/is_active_course: c\.is_active_course \?\? null/g)).toHaveLength(2);
    expect(studentsList).toContain('const isHistoryCourseByReason = (course) => courseHistoryReason(course) !== null;');
    expect(studentsList).toContain('getStudentAllCourses(id).filter((c) => !isHistoryCourseByReason(c))');
    expect(studentsList).toContain('/api/v1/students/${sid}/active-courses`');
  });

  it('課程管理 groups by the same verdict', () => {
    expect(courseManagement).toContain('filter(c => !isCourseManagementHistory(c))');
    expect(courseManagement).toContain('visibleCourses.filter(isActiveCourse)');
    expect(courseManagement).toContain(`v-if="courseHistoryReason(hc) === 'completed'"`);
    expect(courseManagement).not.toMatch(/\bisHistoryCourse\(/);
  });
});
