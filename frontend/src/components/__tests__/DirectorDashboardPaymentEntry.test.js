import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  normalizeNavigationId,
  resolveCalendarFocusCourse,
  resolveTuitionFocusRow,
} from '../../lib/workflowNavigationContext.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const dashboard = readFileSync(resolve(__dirname, '../../pages/DirectorDashboard.vue'), 'utf8');
const tuition = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');
const notifications = readFileSync(resolve(__dirname, '../../pages/NotificationsCenter.vue'), 'utf8');
const app = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');

describe('director payment shortcuts', () => {
  const rows = [
    { id: 11, student_class_id: 11, student_id: 7, student_name: '甲', subject: '英文' },
    { id: 12, studentClassId: 12, student_id: 8, student_name: '乙', subject: '數學' },
  ];

  it('resolves only safe ids and existing course context', () => {
    expect(normalizeNavigationId('12')).toBe(12);
    expect(normalizeNavigationId('12.5')).toBeNull();
    expect(resolveTuitionFocusRow(rows, { studentId: 7, courseId: 11 })).toBe(rows[0]);
    expect(resolveTuitionFocusRow(rows, { studentId: 8, courseId: 999 })).toBe(rows[1]);
    expect(resolveCalendarFocusCourse(rows, { studentId: 99, courseId: 999 })).toBeNull();
  });

  it('exposes notification and payment-detail actions from each alert', () => {
    expect(dashboard).toContain('PaymentSlipModal');
    expect(dashboard).toContain('AccountingLedgerModal');
    expect(dashboard).toContain('繳費通知');
    expect(dashboard).toContain('繳費明細');
    expect(dashboard).toContain('openPaymentSlip(student)');
    expect(dashboard).toContain('openPaymentLedger(student)');
  });

  it('keeps notice generation limited to outstanding payment states', () => {
    expect(dashboard).toContain("['unpaid', 'partial', 'pending_report'].includes(student?.payment_status)");
    expect(dashboard).toContain('invoice_id: c.invoice_id || null');
    expect(dashboard).toContain('student_class_id: c.student_class_id || c.id || c.class_id || null');
  });

  it('labels accounting-center ledger actions as payment details', () => {
    expect(tuition).toContain('title="查看學生繳費明細"');
    expect(tuition).toContain('查看繳費明細');
    expect(tuition).toContain('>繳費明細</button>');
  });

  it('keeps payment shortcuts visible for contextual workflow entry', () => {
    expect(dashboard).toContain('複製通知');
    expect(dashboard).toContain('student_class_id: c.student_class_id || c.id || c.class_id || null');
  });

  it('carries notification context into the existing billing and calendar pages', () => {
    expect(notifications).toContain('const courseId = payload.student_class_id || payload.course_id || payload.studentClassId || null;');
    expect(notifications).toContain('const date = payload.session_date || payload.schedule_date || payload.date ||');
    expect(notifications).toContain("type === 'low_sessions' ? 'renewal' : 'unpaid'");
    expect(app).toContain(':initial-student-id="tuitionInitialStudentId"');
    expect(app).toContain(':initial-course-id="calendarInitialCourseId"');
    expect(app).toContain('@clear-initial-context="clearTuitionNavigationContext"');
  });
});
