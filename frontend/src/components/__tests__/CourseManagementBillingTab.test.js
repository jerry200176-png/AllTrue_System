import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');

describe('CourseManagement student billing tab', () => {
  it('keeps course and billing tabs on the same student card', () => {
    expect(source).toContain('課程資料');
    expect(source).toContain('帳務資料');
    expect(source).toContain('studentGroupTab(group.key) === \'billing\'');
    expect(source).toContain('/api/v1/payment-reports?student_class_id=');
    expect(source).toContain('/api/v1/student-classes/${c.id}/invoices');
  });

  it('labels pending reports as 待對帳 and does not treat them as paid', () => {
    expect(source).toContain("if (course?.payment_status === 'pending_report') return '待對帳'");
    expect(source).toContain("if (c.payment_status === 'pending_report')");
    expect(source).toContain('登記已回報');
    expect(source).not.toMatch(/openPaymentEntryForInvoice\(inv\)\s*>核帳</);
  });
});
