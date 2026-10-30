import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { mount } from '@vue/test-utils';
import BillingDocument from '../BillingDocument.vue';
import { paymentSlipView, formatSessionDate, statusTone, isDone, STATUS_ZH } from '../../lib/billingDocumentView.js';

describe('paymentSlipView', () => {
  it('invoice slip: partial balance, service period and filename', () => {
    const v = paymentSlipView({
      invoice_id: 1623, student_name: '測試', status: 'partial', remaining: 3600, total_amount: 6600, paid_amount: 3000,
      due_date: '2026-08-28', issue_date: '2026-08-15',
      items: [{ description: '月結', amount: 6600, period_start: '2026-08-15', period_end: '2026-09-13' }],
      sessions: [{ date: '2026-08-04', status: 'attended' }],
    });
    expect(v.tone).toBe('invoice');
    expect(v.amount_label).toBe('尚欠金額');
    expect(v.meta.map(m => m.label)).toEqual(['學生', '服務期間', '開立日期']);
    expect(v.filename).toBe('繳費單_測試_1623.png');
    expect(v.session_title).toBe('上課日期');
  });

  it('tuition slip: zero billed lessons with planned dates reads as planned, overdue hint shown', () => {
    const v = paymentSlipView({
      student_class_id: 9, student_name: '測試', subject: '數學', schedule_mode: 'date', period_sessions: 0,
      due_date: '2026-09-17', days_until_settlement: -3, sessions: [{ date: '2026-09-02', status: 'scheduled' }],
    });
    expect(v.items[0].period).toBe('本期預計 1 堂');
    expect(v.session_title).toBe('本期上課日期');
    expect(paymentSlipView({ student_class_id: 1, schedule_mode: 'count', sessions: [] }).session_title).toBe('課程明細');
    expect(v.due.hint).toBe('已逾期 3 天');
  });

  it('formats weekday and status tone', () => {
    expect(formatSessionDate('2026-09-01')).toBe('2026/09/01（二）');
    expect(statusTone('leave')).toBe('leave');
    expect(statusTone('expected')).toBe('planned');
    expect(statusTone('leave_requested')).toBe('leave');
    expect(isDone('excused')).toBe(false);
    expect(STATUS_ZH.leave_requested).toBe('請假申請中');
  });

  it('caps the lesson list at 40 rows and says how many are left out', () => {
    const sessions = Array.from({ length: 500 }, (_, i) => ({ date: '2026-09-01', status: 'scheduled', class_session_id: i + 1 }));
    const w = mount(BillingDocument, { props: { doc: paymentSlipView({ student_class_id: 1, schedule_mode: 'count', sessions }) } });
    expect(w.findAll('.slip-session-list li')).toHaveLength(40);
    expect(w.find('.slip-more').text()).toBe('另有 460 堂未列出（共 500 堂）');
  });
});

describe('tuition slip amount (Founder Q2)', () => {
  it('shows the balance, not the bill total, for a part-paid bill', async () => {
    const buildBillingDocumentView = paymentSlipView;
    const base = { subject: '數學', schedule_mode: 'count', student_name: '甲', payable_status: 'invoiced', payable_amount: 10000 };
    const partly = buildBillingDocumentView({ ...base, payable_outstanding: 6000 });
    expect([partly.amount, partly.amount_label]).toEqual([6000, '尚欠金額']);
    expect(partly.sub_amounts).toContain('已繳');
    const full = buildBillingDocumentView({ ...base, payable_outstanding: 10000 });
    expect([full.amount, full.amount_label]).toEqual([10000, '應繳費用']);
  });
});

describe('代收款 section (in-app #439)', () => {
  const passThrough = [
    { title: '數學講義', category: '書籍費', amount: 300, status: 'unpaid', paid_on: null },
    { title: '模擬考教材', category: '教材費', amount: 200, status: 'paid', paid_on: '2026-07-10' },
  ];

  it('renders as its own section with the unpaid subtotal and the not-tuition line, tuition amount untouched', () => {
    const doc = paymentSlipView({
      invoice_id: 5, student_name: '甲', status: 'unpaid', remaining: 6000, total_amount: 6000, paid_amount: 0,
      items: [{ description: '月結', amount: 6000 }], pass_through: passThrough, pass_through_unpaid_total: 300,
    });
    expect(doc.amount).toBe(6000);
    expect(doc.items).toHaveLength(1);
    const w = mount(BillingDocument, { props: { doc } });
    const sec = w.get('[data-testid="slip-pass-through"]');
    expect(sec.text()).toContain('代收款（非學費）');
    expect(sec.findAll('li')).toHaveLength(2);
    expect(sec.text()).toContain('數學講義');
    expect(sec.text()).toContain('未繳');
    expect(sec.text()).toContain('已繳 2026/07/10');
    expect(sec.text()).toContain('代收款未繳合計NT$ 300');
    expect(sec.text()).toContain('代收款由補習班代為收取轉付，不屬於學費');
  });

  it('is hidden when there are no pass-through rows, on both slip kinds and receipts', () => {
    const tuition = paymentSlipView({ student_class_id: 1, schedule_mode: 'count', sessions: [], pass_through: [], pass_through_unpaid_total: 0 });
    const invoice = paymentSlipView({ invoice_id: 2, items: [] });
    for (const doc of [tuition, invoice]) {
      expect(doc.pass_through).toBeNull();
      expect(mount(BillingDocument, { props: { doc } }).find('[data-testid="slip-pass-through"]').exists()).toBe(false);
    }
    expect(paymentSlipView({ student_class_id: 1, schedule_mode: 'count', pass_through: passThrough, pass_through_unpaid_total: 300 }).pass_through.rows).toHaveLength(2);
  });
});

describe('BillingDocument header layout (in-app #444)', () => {
  const src = readFileSync(resolve(process.cwd(), 'src/components/BillingDocument.vue'), 'utf8');
  it('brand name and campus never wrap and may shrink with an ellipsis', () => {
    expect(src).toMatch(/\.slip-brand-name\s*\{[^}]*white-space:\s*nowrap[^}]*text-overflow:\s*ellipsis/);
    expect(src).toMatch(/\.slip-brand-campus\s*\{[^}]*white-space:\s*nowrap/);
    expect(src).toMatch(/\.slip-brand-text\s*\{[^}]*min-width:\s*0/);
  });
  it('watermark sits behind the text so the footer is not smudged', () => {
    expect(src).toMatch(/\.slip-watermark\s*\{\s*z-index:\s*0/);
    expect(src).toContain('.slip > :not(.slip-watermark)');
  });
  it('receipt and slip share the one component', () => {
    const rcpt = readFileSync(resolve(process.cwd(), 'src/components/ReceiptModal.vue'), 'utf8');
    expect(rcpt).toContain('<BillingDocument');
  });
});
