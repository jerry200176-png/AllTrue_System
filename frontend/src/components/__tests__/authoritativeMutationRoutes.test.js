import { describe, it, expect } from 'vitest';
import {
  buildTuitionCollectNav,
  buildStudentsCommercialNav,
  buildCourseMgmtOpsNav,
  buildBindingManagementNav,
  buildAttendanceNav,
  tuitionIntentForPaymentStatus,
} from '../../lib/authoritativeMutationRoutes.js';

describe('authoritativeMutationRoutes', () => {
  it('builds tuition-collect deep links with student and course context', () => {
    expect(buildTuitionCollectNav({ student_id: 12, id: 34 }, { intent: 'pending' })).toEqual({
      target: 'tuition-collect',
      studentId: 12,
      courseId: 34,
      intent: 'pending',
    });
  });

  it('builds students commercial deep links', () => {
    expect(buildStudentsCommercialNav({ student_id: 5, student_class_id: 9 }, { intent: 'renew' })).toEqual({
      target: 'students',
      studentId: 5,
      courseId: 9,
      intent: 'renew',
    });
  });

  it('builds course-mgmt operational deep links', () => {
    expect(buildCourseMgmtOpsNav({ student_id: 3, id: 7, teacher_id: 11 })).toEqual({
      target: 'course-mgmt',
      studentId: 3,
      courseId: 7,
      teacherId: 11,
    });
  });

  it('builds binding-management deep links', () => {
    expect(buildBindingManagementNav({ studentId: 22, studentName: '王小明' })).toEqual({
      target: 'binding-management',
      studentId: 22,
      studentName: '王小明',
    });
  });

  it('builds attendance deep links', () => {
    expect(buildAttendanceNav({ studentId: 1, courseId: 2, date: '2026-09-04T12:00:00' })).toEqual({
      target: 'attendance',
      studentId: 1,
      courseId: 2,
      sessionId: null,
      date: '2026-09-04',
    });
  });

  it('maps payment status to tuition intents', () => {
    expect(tuitionIntentForPaymentStatus('pending_report')).toBe('pending');
    expect(tuitionIntentForPaymentStatus('paid')).toBe('receipts');
    expect(tuitionIntentForPaymentStatus('unpaid')).toBe('unpaid');
  });
});
