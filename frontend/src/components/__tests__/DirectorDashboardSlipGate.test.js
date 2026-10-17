// Founder D2 (#4121 review): the dashboard never offers 繳費單 for a 金額待確認 row (the API refuses it with 422).
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('../../supabase', () => {
  const q = () => {
    const chain = new Proxy({}, { get: (_, k) => (k === 'then' ? (res) => res({ data: [], error: null, count: 0 }) : () => chain) });
    return chain;
  };
  return { supabase: { from: q, rpc: q, auth: { getSession: async () => ({ data: { session: null } }) }, channel: () => ({ on() { return this; }, subscribe() { return this; } }), removeChannel() {} } };
});

import DirectorDashboard from '../../pages/DirectorDashboard.vue';

const base = { student_name: '甲', subject: '數學', alert_type: 'pending_reconciliation', remaining_sessions: 0, schedule_mode: 'date' };
const ALERTS = [
  { ...base, id: 1, student_id: 1, student_name: '確認生', payment_status: 'pending_report', amount_pending_confirmation: false },
  { ...base, id: 2, student_id: 2, student_name: '待確認生', payment_status: 'pending_report', amount_pending_confirmation: true },
];

beforeAll(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
});

describe('DirectorDashboard 繳費單 gate', () => {
  it('hides 繳費單 for a pending-confirmation row and keeps it for a confirmed one', async () => {
    global.fetch = vi.fn((url) => Promise.resolve({
      ok: true, status: 200,
      json: () => Promise.resolve(String(url).includes('/v1/alerts/tuition') ? ALERTS : []),
    }));
    const w = mount(DirectorDashboard, { props: { branchId: 1 }, global: { stubs: { teleport: true, OperationsQuickStart: true, TodayProgressCard: true, RecentSubstitutesCard: true, TeacherAssessmentFillRateCard: true, EngagementRankStrip: true, PaymentSlipModal: true } } });
    await flushPromises();
    await w.findAll('button').find((b) => b.text().includes('完整營運')).trigger('click');
    await flushPromises();
    const rows = w.findAll('.director-payment-row');
    const row = (name) => rows.find((r) => r.text().includes(name));
    expect(row('確認生').findAll('button').map((b) => b.text())).toContain('繳費單');
    expect(row('待確認生').findAll('button').map((b) => b.text())).not.toContain('繳費單');
    w.unmount();
  });
});
