import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import pageSource from '../../pages/ParttimePayrollPage.vue?raw';

vi.mock('../../lib/parttimePayrollApi.js', () => ({
  fetchPayrollSummary: vi.fn(async () => ({ summary: { lock_status: 'draft', anomaly_count: 0, anomalies: [] }, teachers: [] })),
  fetchTeacherSessions: vi.fn(), exportPayrollCsv: vi.fn(), lockPayroll: vi.fn(), reopenPayroll: vi.fn(),
  fetchPayrollRules: vi.fn(async () => ({ base_rates: { high: 400 }, headcount_bonus: 50, defaults: {} })),
  updatePayrollRules: vi.fn(),
  fetchTeacherPayrollRules: vi.fn(), updateTeacherPayrollRules: vi.fn(), deleteTeacherPayrollRules: vi.fn(),
}));

import ParttimePayrollPage from '../../pages/ParttimePayrollPage.vue';

// in-app #509: headcount_bonus IS used by the pay calculation, so no hint may say it has no effect.
describe('ParttimePayrollPage 人數加成 hint', () => {
  it('says how the bonus changes pay in the branch rules modal', async () => {
    const w = mount(ParttimePayrollPage, { props: { branchId: 1 }, attachTo: document.body });
    await flushPromises();
    const open = w.findAll('button').find((b) => b.text().includes('薪資計算設定'));
    await open.trigger('click');
    await flushPromises();
    const hint = document.body.textContent;
    expect(hint).toContain('同時段每多一位學生，時薪加 50 元');
    expect(hint).not.toContain('此欄位不影響薪資');
    w.unmount();
  });

  it('neither hint (branch or teacher rules) claims the bonus has no effect on pay', () => {
    expect(pageSource).not.toContain('此欄位不影響薪資');
    expect(pageSource.match(/同時段每多一位學生，時薪加/g)).toHaveLength(2);
  });
});
