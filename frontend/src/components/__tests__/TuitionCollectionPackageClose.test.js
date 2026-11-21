// #5494: the paid package row is a pool; its representative member must not receive a fake whole-pool close.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const json = body => ({ ok: true, status: 200, json: async () => body });
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });
async function pageWith(rows, role = 'director') {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role } }));
  const fetch = vi.fn(async input => json(String(input).includes('/api/v1/alerts/tuition') ? rows : []));
  vi.stubGlobal('fetch', fetch);
  const page = shallowMount(TuitionCollectionPage, { props: { branchId: 1 } });
  await flushPromises();
  return { page, fetch, ledger: page.findComponent({ name: 'AccountingLedgerModal' }) };
}
const row = (id, overrides = {}) => ({ id, student_id: id + 100, student_name: '合成學生', subject: '數學', schedule_mode: 'count', remaining_sessions: 0, payment_status: 'renew_needed', payable_outstanding: 0, ...overrides });
describe('Package close boundary from actual alert rows', () => {
  it.each(['renew_needed', 'monthly_due_soon'])('keeps %s pool visible and blocks a stale close event without sending a request', async payment_status => {
    const { page, fetch, ledger } = await pageWith([row(31, { package_id: 81, payment_status })]);
    try {
      const actions = ledger.props('rowActions')[31];
      expect(actions.settle).toBe(false);
      expect(actions.settleBlockedReason).toContain('共用方案');
      ledger.vm.$emit('row-action', { kind: 'settle', courseId: 31 });
      await flushPromises();
      expect(page.find('.tc-overlay').exists()).toBe(false);
      expect(fetch.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
      expect(ledger.props('rowActions')[31]).toBeDefined();
    } finally { page.unmount(); }
  });
  it.each(['renew_needed', 'monthly_due_soon'])('preserves the existing standalone %s close dialog', async payment_status => {
    const { page, ledger } = await pageWith([row(41, { payment_status })]);
    try {
      expect(ledger.props('rowActions')[41].settle).toBe(true);
      ledger.vm.$emit('row-action', { kind: 'settle', courseId: 41 });
      await flushPromises();
      expect(page.find('.tc-overlay').text()).toContain('結案');
    } finally { page.unmount(); }
  });
  it.each([['director', true], ['teacher', false]])('opens package review only for a permitted %s role', async (role, allowed) => {
    const { page, fetch, ledger } = await pageWith([row(31, { package_id: 81 }), row(41)], role);
    const report = vi.fn();
    window.addEventListener('alltrue-open-bug-report', report);
    try {
      expect(ledger.props('canReportPackageClose')).toBe(allowed);
      ledger.vm.$emit('report-package-close', { branchId: 1, studentId: 131, courseId: null });
      expect(report).not.toHaveBeenCalled();
      ledger.vm.$emit('report-package-close', { branchId: 1, studentId: 131, courseId: 31, packageId: 81 });
      expect(report).toHaveBeenCalledTimes(allowed ? 1 : 0);
      if (allowed) {
        expect(report.mock.calls[0][0].detail.relatedReference).toMatch(/app_page=course-mgmt&branch_id=1&student_id=131&course_id=31 共用方案編號 81$/);
      }
      expect(fetch.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
    } finally {
      window.removeEventListener('alltrue-open-bug-report', report);
      page.unmount();
    }
  });
});
