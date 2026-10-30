import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');
// #4150: the badge verdict (labels, tutoring gate) lives in the shared coursePaymentBadge.
const moneyState = readFileSync(resolve(__dirname, '../../lib/courseMoneyState.js'), 'utf8');

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

  it('labels payment status via the shared plain labels and deep-links billing mutations to tuition-collect', () => {
    // #4150: the label comes from the shared coursePaymentBadge (courseMoneyState.js), never a local 未繳 fallback.
    expect(source).toContain('const paymentStatusButtonLabel = (course) => paymentBadge(course).label;');
    expect(source).toContain('在這裡看帳務');
    expect(source).toContain('登記收款');
    expect(source).toContain('return `查看「${REPORT_STATUS_LABELS.pending}」`;');
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
    expect(source).toContain('isPaymentNoticeStatus(course?.payment_status)');
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
    expect(source).toContain('class="payment-status-and-action"');
    expect(source).toContain("'payment-status-badge'");
    expect(source).toContain('role="status"');
    expect(source).toContain('付款狀態不可直接操作');
    expect(source).toContain('class="small ghost payment-next-action"');
    expect(source).toContain('@click="onPaymentNextAction(c)"');
    // PRD v2 D2: every billing action opens the ledger panel in place, never another page
    // In-app #398: 登記收款 (report_payment) opens the ledger with the entry form; other next actions open the ledger only.
    expect(source).toContain("const onPaymentNextAction = (course) => openLedgerForCourse(course, course?.id && paymentBadge(course).nextAction === 'report_payment' ? 'record' : null);");
    expect(source).toContain(':initial-action="ledgerInitialAction"');
    expect(source).toContain('ledgerInitialAction.value = initialAction;');
    expect(source).toContain('const openTuitionLedger = (course) => openLedgerForCourse(course);');
    expect(source).not.toMatch(/emit\('navigate', buildTuitionCollectNav/);
    expect(source).toContain('<th>堂數</th>');
    expect(source).not.toContain('<th>剩餘堂數</th>');
    expect(source).not.toContain("'btn-status'");
    expect(source).not.toContain('>帳務</button>');
  });

  it('uses course type as the tutoring payment gate and fails closed on anomalies', () => {
    expect(source).toContain("const isTutoringCourse = (course) => course?.class_type === 'tutoring';");
    expect(moneyState).toContain("if (course?.class_type === 'tutoring') {");
    expect(moneyState).toContain("{ label: '帳務資料需修正', tone: 'anomaly', nextAction: 'none' }");
    expect(moneyState).toContain("{ label: '無須繳費', tone: 'no_payment', nextAction: 'none' }");
    expect(source).toContain('const shouldShowPaymentAction = (course) => !isTutoringCourse(course);');
    expect(source).toContain('v-else-if="showsBillingRowNextAction(row.course)"');
    expect(source).toContain('v-if="isTutoringBillingAnomaly(row.course)"');
    expect(source).toContain('輔導課不應產生付款義務');
    expect(source).toContain("anomaly: 'tag-billing-anomaly', no_payment: 'tag-no-payment'");
  });

  it('uses the persisted rate unit for edit round-trip and course lookup pricing', () => {
    expect(source).toContain("import { getPerSessionFee, getCourseTotalFee, getRateUnitDisplayLabel } from '../lib/coursePricing';");
    expect(source).toContain('rate_unit: c.rate_unit || \'session\'');
    expect(source).toContain('rate_unit: form.rate_unit || \'session\'');
    expect(source).toContain('{{ getRateUnitDisplayLabel(c) }} ${{ sessionPrice(c) }}');
    expect(source).toContain('{{ getRateUnitDisplayLabel(hc) }} ${{ sessionPrice(hc) }}');
  });

  it('shows the contract ref and period column on the course billing table (in-app #396 #404)', () => {
    expect(source).toContain('<th>合約／期間</th>');
    expect(source).toContain('data-testid="billing-contract-period"');
    expect(source).toContain('formatCourseRef(row.course.id)');
    expect(source).toContain('formatCoursePeriod(');
    expect(source).toContain('colspan="6" class="student-billing-state"');
  });

  it('receipt column says a confirmed report can issue a receipt (in-app #395-#404)', () => {
    expect(source).toContain(`r.status === 'confirmed')" class="hint">可開收據</span>`);
    expect(source).toContain('<span v-else class="hint">核對入帳後才能開收據</span>');
    expect(source).not.toContain('確認入帳後可開');
    expect(moneyState).toContain("if (status === 'review_required') return { label: REVIEW_REQUIRED_LABEL, tone, nextAction };");
  });
});

describe('shared package billing rows (in-app #442)', () => {
  it('only the package lead row keeps 繳費單 / 登記收款; followers point to it', () => {
    expect(source).toContain('const billingPackageLead = (row, group)');
    expect(source).toContain('isPaymentNoticeAvailable(row.course) && !billingPackageLead(row, group)');
    expect(source).toContain('data-testid="billing-package-follower"');
    expect(source).toContain('v-else-if="showsBillingRowNextAction(row.course)"');
  });
});
