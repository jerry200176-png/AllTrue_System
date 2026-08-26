import { describe, it, expect } from 'vitest';
import {
  adoptionErrorType,
  sanitizeAdoptionMeta,
  workflowDurationMs,
  workflowEventName,
} from './adoptionTelemetry.js';

describe('sanitizeAdoptionMeta (E-OPS-TRUST)', () => {
  it('strips names, phones, and linkable student ids', () => {
    const out = sanitizeAdoptionMeta({
      key: 'stranded_paid',
      student_id: 123,
      student_class_id: 456,
      student_name: '王小明',
      phone: '0912345678',
      severity: 'critical',
      target: 'course-mgmt',
    });
    expect(out).toEqual({
      key: 'stranded_paid',
      severity: 'critical',
      target: 'course-mgmt',
    });
  });
});

describe('workflow telemetry helpers', () => {
  it('only builds known workflow lifecycle events', () => {
    expect(workflowEventName('billing', 'started')).toBe('workflow_billing_started');
    expect(workflowEventName('calendar', 'returned')).toBe('workflow_calendar_returned');
    expect(workflowEventName('student', 'started')).toBe('');
    expect(workflowEventName('billing', 'deleted')).toBe('');
  });

  it('bounds durations and classifies errors without exposing messages', () => {
    expect(workflowDurationMs(1000, 1250)).toBe(250);
    expect(workflowDurationMs(0, 999999999)).toBe(300000);
    expect(adoptionErrorType('validation')).toBe('validation');
    expect(adoptionErrorType(422)).toBe('http_4xx');
    expect(adoptionErrorType({ status: 503, message: '學生姓名' })).toBe('http_5xx');
    expect(adoptionErrorType(new Error('家長電話'))).toBe('network');
  });
});
