import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BillingDocument from '../BillingDocument.vue';
import { paymentSlipView, formatSessionDate, statusTone } from '../../lib/billingDocumentView.js';

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
  });

  it('caps the lesson list at 40 rows and says how many are left out', () => {
    const sessions = Array.from({ length: 500 }, (_, i) => ({ date: '2026-09-01', status: 'scheduled', class_session_id: i + 1 }));
    const w = mount(BillingDocument, { props: { doc: paymentSlipView({ student_class_id: 1, schedule_mode: 'count', sessions }) } });
    expect(w.findAll('.slip-session-list li')).toHaveLength(40);
    expect(w.find('.slip-more').text()).toBe('另有 460 堂未列出（共 500 堂）');
  });
});
