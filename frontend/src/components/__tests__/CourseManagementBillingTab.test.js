import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');

describe('CourseManagement student billing tab', () => {
  it('keeps course and billing tabs on the student card header', () => {
    expect(source).toContain('student-group-header-actions');
    expect(source).toContain('selectStudentGroupTab');
    expect(source).toContain("@click.stop=\"selectStudentGroupTab(group, 'billing', $event)\"");
    expect(source).toContain('>帳務資料</button>');
    expect(source).toContain('>課程資料</button>');
    expect(source).toContain('data-testid="student-tab-billing"');
    expect(source).toContain('aria-label="帳務資料"');
    expect(source).toContain('studentGroupTab(group.key) === \'billing\'');
    expect(source).toContain('/api/v1/payment-reports?student_class_id=');
    expect(source).toContain('/api/v1/student-classes/${c.id}/invoices');
    expect(source).toContain('hasMixedPackagePaymentStatuses(group.key)');
    expect(source).toContain('共用方案的繳費狀態按科目分開顯示');
  });

  it('labels pending reports as 待對帳 and deep-links billing mutations to tuition-collect', () => {
    expect(source).toContain("if (course?.payment_status === 'pending_report') return '待對帳'");
    expect(source).toContain('前往帳務中心');
    expect(source).toContain('goToTuitionBilling');
    expect(source).not.toContain('PaymentEntryModal');
    expect(source).not.toContain('>登記已回報</button>');
    expect(source).not.toContain('openPaymentEntryForInvoice');
    expect(source).not.toContain('submitInvoiceVoid');
    expect(source).not.toContain('/api/v1/invoices/${invoice.id}/${path}');
  });

  it('offers a read-only payment notice from the billing context', () => {
    expect(source).toContain("import PaymentSlipModal from '../components/PaymentSlipModal.vue';");
    expect(source).toContain('const isPaymentNoticeAvailable = (course)');
    expect(source).toContain("['unpaid', 'partial', 'pending_report'].includes(course?.payment_status)");
    expect(source).toContain('data-testid="billing-payment-slip-action"');
    expect(source).toContain('@click="openPaymentSlip(row.course)"');
    expect(source).toContain(':student-class-id="paymentSlipStudentClassId"');
    expect(source).toContain('@close="closePaymentSlip"');
  });

  it('shows the latest payment report summary on the course card', () => {
    expect(source).toContain('coursePaymentSummary(c)');
    expect(source).toContain('formatPaymentSummary(c.latest_payment_summary)');
    expect(source).toContain('最近繳費：');
    expect(source).toContain('summary.note');
    expect(source).toContain('summary.account_last5');
  });

  it('uses the persisted rate unit for edit round-trip and course lookup pricing', () => {
    expect(source).toContain("import { getPerSessionFee, getCourseTotalFee, getRateUnitDisplayLabel } from '../lib/coursePricing';");
    expect(source).toContain('rate_unit: c.rate_unit || \'session\'');
    expect(source).toContain('rate_unit: form.rate_unit || \'session\'');
    expect(source).toContain('{{ getRateUnitDisplayLabel(c) }} ${{ sessionPrice(c) }}');
    expect(source).toContain('{{ getRateUnitDisplayLabel(hc) }} ${{ sessionPrice(hc) }}');
  });
});
