import { describe, it, expect } from 'vitest';
import { sanitizeAdoptionMeta } from './adoptionTelemetry.js';

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
