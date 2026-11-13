import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import axe from 'axe-core';
import TuitionReportPage from '../../pages/TuitionReportPage.vue';

// #4989: 當月學收 on components/ui, mounted with fetch mocked (fake data only).
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
});

const payload = (over = {}) => ({
  data: [
    { student_class_id: 1, student_name: '測試學生甲', subject: '數學', teacher_name: '測試老師', class_type: 'one_on_one', monthly_sessions: 4, rate: 1200, monthly_tuition: 4800, pay_status: 'unpaid', outstanding_amount: 4800, last_paid_at: null },
    { student_class_id: 2, student_name: '測試學生乙', subject: '英文', teacher_name: '測試老師', class_type: 'one_on_two', monthly_sessions: 4, rate: 1500, monthly_tuition: 6000, pay_status: 'paid', outstanding_amount: 0, last_paid_at: '2026-11-02' },
  ],
  summary: { total_students: 2, total_sessions: 8, total_tuition: 10800, total_due: 10800, total_collected: 6000, total_outstanding: 4800, unpaid_students: 1 },
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
  ...over,
});
const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const source = readFileSync(resolve(__dirname, '../../pages/TuitionReportPage.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
let fetchMock;
const urls = () => fetchMock.mock.calls.map(([u]) => String(u));
const mountPage = async (props = {}) => {
  wrapper = mount(TuitionReportPage, { props: { branchId: 3, ...props }, attachTo: document.body });
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' }));
  fetchMock = vi.fn(async () => json(payload()));
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); });

describe('TuitionReportPage on the template', () => {
  it('is off the legacy list and uses no At* components or scoped style', () => {
    expect(legacyList).not.toContain('src/pages/TuitionReportPage.vue');
    expect(source).not.toMatch(/design-system\/At/);
    expect(source).not.toContain('<style');
  });

  it('keeps the guide hook, one h2, the same read and the summary numbers', async () => {
    await mountPage();
    expect(wrapper.find('[data-guide="tuition-report-header"]').exists()).toBe(true);
    expect(wrapper.findAll('h2')).toHaveLength(1);
    expect(urls()[0]).toContain('/api/v1/finance/branch-monthly-tuition?');
    expect(urls()[0]).toContain('branch_id=3');
    const stats = wrapper.find('.tr-stats').text();
    expect(stats).toContain('2 人');
    expect(stats).toContain('8 堂');
    expect(stats).toContain('10,800');
    expect(wrapper.findAll('.tr-table tbody tr')).toHaveLength(2);
    expect(wrapper.find('[data-testid="collection-summary"]').exists()).toBe(true);
  });

  it('month buttons are named and reload the report', async () => {
    await mountPage();
    await wrapper.get('button[aria-label="上一月"]').trigger('click');
    await flushPromises();
    expect(urls()).toHaveLength(2);
    await wrapper.get('button[aria-label="下一月"]').trigger('click');
    await flushPromises();
    expect(urls()).toHaveLength(3);
  });

  it('只看未收 sends unpaid_only and the error state retries', async () => {
    await mountPage();
    await wrapper.get('.tr-filter button[role="checkbox"]').trigger('click');
    await flushPromises();
    expect(urls().at(-1)).toContain('unpaid_only=1');

    fetchMock.mockImplementationOnce(async () => json({}, 500));
    await wrapper.get('button[aria-label="上一月"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('.tr-error[role="alert"]').text()).toContain('載入失敗（500）');
    await wrapper.get('.tr-retry-btn').trigger('click');
    await flushPromises();
    expect(wrapper.find('.tr-error').exists()).toBe(false);
    expect(wrapper.find('.tr-stats').exists()).toBe(true);
  });

  it('shows the empty state wording', async () => {
    fetchMock.mockImplementation(async () => json(payload({ data: [], summary: { total_students: 0, total_sessions: 0, total_tuition: 0, total_due: 0, total_collected: 0, total_outstanding: 0, unpaid_students: 0 } })));
    await mountPage();
    expect(wrapper.find('.tr-empty[role="status"]').text()).toContain('無有效課程紀錄');
  });

  it('has no axe violations (colour contrast is checked in the browser spec)', async () => {
    await mountPage();
    const res = await axe.run(wrapper.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(res.violations.map((v) => v.id)).toEqual([]);
  });
});
