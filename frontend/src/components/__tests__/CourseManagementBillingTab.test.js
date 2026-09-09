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
    expect(source).toContain('/api/v1/accounting/ledger?student_class_id=');
    expect(source).toContain('學生歷史帳務');
    expect(source).toContain('各期帳務仍保留在原期間');
    expect(source).toContain('hasMixedPackagePaymentStatuses(group.key)');
    expect(source).toContain('共用方案的繳費狀態按科目分開顯示');
  });

  it('labels pending reports as 待對帳 and deep-links billing mutations to tuition-collect', () => {
    expect(source).toContain("if (course?.payment_status === 'pending_report') return '待對帳'");
    expect(source).toContain('前往帳務中心');
    expect(source).toContain('登記繳費回報');
    expect(source).toContain('查看待對帳');
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

  it('keeps payment status non-interactive and names the real billing action', () => {
    expect(source).toContain("'payment-status-badge'");
    expect(source).toContain('role="status"');
    expect(source).toContain('付款狀態不可直接操作');
    expect(source).not.toContain("'btn-status'");
    expect(source).not.toContain('>帳務</button>');
  });

  it('uses course type as the tutoring payment gate and fails closed on anomalies', () => {
    expect(source).toContain("const isTutoringCourse = (course) => course?.class_type === 'tutoring';");
    expect(source).toContain("if (isTutoringCourse(course)) return '無須繳費';");
    expect(source).toContain("if (isTutoringBillingAnomaly(course)) return '帳務資料需修正';");
    expect(source).toContain('const shouldShowPaymentAction = (course) => !isTutoringCourse(course);');
    expect(source).toContain('v-if="shouldShowPaymentAction(row.course)"');
    expect(source).toContain('v-if="isTutoringBillingAnomaly(row.course)"');
    expect(source).toContain('輔導課不應產生付款義務');
    expect(source).toContain("return 'tag-no-payment';");
    expect(source).toContain("return 'tag-billing-anomaly';");
  });

  it('uses the persisted rate unit for edit round-trip and course lookup pricing', () => {
    expect(source).toContain("import { getPerSessionFee, getCourseTotalFee, getRateUnitDisplayLabel } from '../lib/coursePricing';");
    expect(source).toContain('rate_unit: c.rate_unit || \'session\'');
    expect(source).toContain('rate_unit: form.rate_unit || \'session\'');
    expect(source).toContain('{{ getRateUnitDisplayLabel(c) }} ${{ sessionPrice(c) }}');
    expect(source).toContain('{{ getRateUnitDisplayLabel(hc) }} ${{ sessionPrice(hc) }}');
  });
});
