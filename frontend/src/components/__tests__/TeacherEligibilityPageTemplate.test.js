import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import axe from 'axe-core';
import TeacherEligibilityPage from '../../pages/TeacherEligibilityPage.vue';

// #4989: 正職薪資要件 on components/ui, mounted with fetch mocked (fake data only).
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
});

const teacher = (over = {}) => ({
  teacher_id: 701,
  teacher_name: '測試老師甲',
  overall_status: 'qualifies',
  review_required: false,
  settlement: {
    regular_subject_count: 4, tutoring_trial_subject_count: 2, payroll_subject_count: 6, one_to_three_count: 3,
    subject_count_bonus: 1200, one_to_three_bonus: 800, multiplier_pct: 105, weighted_bonus_amount: 2100,
    total_payout: 36000, payout_is_draft: false, adjustments: [{ label: '行政加給', amount: 500 }],
    multiplier_parts: [{ key: 'base', label: '基本', pct: 100 }],
  },
  components: { weekly_16_segments: { status: 'qualifies', metrics: { total_segments: 20, meets_16_segments: true, week_count: 1, qualifying_weeks: 1, weeks: [{ metrics: { week_start: '2026-08-03', week_end: '2026-08-09', total_segments: 20, meets_16_segments: true } }] } } },
  ...over,
});
const payload = (over = {}) => ({
  teachers: [teacher(), teacher({ teacher_id: 702, teacher_name: '測試老師乙', overall_status: 'review', review_required: true })],
  period: { start: '2026-11-01', end: '2026-11-30' },
  policy_version: '115.07',
  branch_subject_total: 12,
  lock: { status: 'draft' },
  ...over,
});
const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body, text: async () => JSON.stringify(body) });
const source = readFileSync(resolve(__dirname, '../../pages/TeacherEligibilityPage.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
let fetchMock;
const urls = () => fetchMock.mock.calls.map(([u]) => String(u));
const eligibilityReads = () => urls().filter((u) => u.includes('/teacher-eligibility') && !u.includes('/inputs'));
const stubs = { TeacherEligibilityInputPanel: true };
const mountPage = async (props = {}) => {
  wrapper = mount(TeacherEligibilityPage, {
    props: { branchId: 3, userRole: 'director', ...props },
    attachTo: document.body,
    global: { stubs },
  });
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' }));
  fetchMock = vi.fn(async () => json(payload()));
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); });

describe('TeacherEligibilityPage on the template', () => {
  it('is off the legacy list and uses no At* components or scoped style', () => {
    expect(legacyList).not.toContain('src/pages/TeacherEligibilityPage.vue');
    expect(source).not.toMatch(/design-system\/At/);
    expect(source).not.toContain('<style');
  });

  it('keeps the guide hook, one h2, the same read and both layouts', async () => {
    await mountPage();
    expect(wrapper.find('[data-guide="eligibility-header"]').exists()).toBe(true);
    expect(wrapper.findAll('h2')).toHaveLength(1);
    expect(eligibilityReads()[0]).toContain('branch_id=3');
    expect(wrapper.findAll('.desktop-table tbody tr')).toHaveLength(2);
    expect(wrapper.findAll('.mobile-list .teacher-card')).toHaveLength(2);
    expect(wrapper.find('.summary-grid').text()).toContain('老師總數');
    expect(wrapper.find('.desktop-table').text()).toContain('36,000');
  });

  it('search filters both layouts and shows the empty wording', async () => {
    await mountPage();
    await wrapper.get('.search-label input').setValue('不存在');
    await flushPromises();
    expect(wrapper.text()).toContain('查詢期間沒有符合條件的正職老師');
    expect(wrapper.find('.desktop-table').exists()).toBe(false);
    expect(wrapper.find('.mobile-list').exists()).toBe(false);
  });

  it('shows a skeleton while loading and a retryable alert on error', async () => {
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = () => r(json(payload())); }));
    wrapper = mount(TeacherEligibilityPage, { props: { branchId: 3, userRole: 'director' }, global: { stubs } });
    await flushPromises();
    expect(wrapper.find('[data-testid="eligibility-loading"]').exists()).toBe(true);
    release();
    await flushPromises();
    expect(wrapper.find('[data-testid="eligibility-loading"]').exists()).toBe(false);

    fetchMock.mockImplementationOnce(async () => json({ message: '薪資要件資料暫時無法載入' }, 503));
    await wrapper.get('.header-actions button').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').text()).toContain('無法載入正職薪資要件');
    const before = eligibilityReads().length;
    const retry = wrapper.findAll('[role="alert"] button').find((b) => b.text() === '重試');
    await retry.trigger('click');
    await flushPromises();
    expect(eligibilityReads().length).toBe(before + 1);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('locked month disables recalculation; only super_admin can reopen', async () => {
    fetchMock.mockImplementation(async () => json(payload({ lock: { status: 'locked' } })));
    await mountPage();
    const labels = () => wrapper.findAll('.header-actions button').map((b) => b.text());
    expect(labels()).toContain('已鎖定');
    expect(labels()).not.toContain('重開結算');
    wrapper.unmount();
    await mountPage({ userRole: 'super_admin' });
    expect(labels()).toContain('重開結算');
  });

  it('a locked month shows what today\'s rules give next to the locked total', async () => {
    const compared = [teacher({ recalculated: { calculated_payout: 36500, calculation_status: 'calculated', difference: 500 } }),
      teacher({ teacher_id: 702, teacher_name: '測試老師乙', recalculated: { calculated_payout: 36000, calculation_status: 'calculated', difference: 0 } })];
    fetchMock.mockImplementation(async (url) => json(payload({ lock: { status: 'locked' }, ...(String(url).includes('compare=1') ? { teachers: compared } : {}) })));
    await mountPage();
    expect(eligibilityReads().some((u) => u.includes('compare=1'))).toBe(true);
    expect(wrapper.find('[data-testid="eligibility-recalculation-note"]').text()).toBe('照現在的算法重算，1 位老師金額不同，合計多 $500。鎖定的數字沒有變。');
    expect(wrapper.find('.desktop-table .total-cell').text()).toContain('現在的算法 $36,500（多 $500）');
    expect(wrapper.find('.desktop-table').text()).toContain('36,000');
  });

  it('a slow answer for a campus the user has left does not replace what is on screen', async () => {
    const answers = [];
    fetchMock.mockImplementation((url) => new Promise((release) => { answers.push({ url: String(url), release }); }));
    await mountPage();
    await wrapper.setProps({ branchId: 4 }); // the page reloads for the new campus while the first read is still out
    await flushPromises();
    const reads = answers.filter((a) => a.url.includes('/teacher-eligibility?'));
    expect(reads).toHaveLength(2);
    reads[1].release(json(payload({ teachers: [teacher({ teacher_name: '新分校的老師' })] })));
    await flushPromises();
    reads[0].release(json(payload({ teachers: [teacher({ teacher_name: '舊分校的老師' })] })));
    await flushPromises();
    expect(wrapper.find('.desktop-table').text()).toContain('新分校的老師');
    expect(wrapper.find('.desktop-table').text()).not.toContain('舊分校的老師');
  });

  it('a draft month is not compared', async () => {
    await mountPage();
    expect(eligibilityReads().some((u) => u.includes('compare=1'))).toBe(false);
    expect(wrapper.find('[data-testid="eligibility-recalculation-note"]').exists()).toBe(false);
  });

  it('the teacher multiplier is read-only: no hand-entered total', async () => {
    await mountPage();
    expect(wrapper.find('.desktop-table .multiplier-cell').text()).toContain('%');
    expect(wrapper.findAll('.desktop-table .multiplier-cell button')).toHaveLength(0);
  });

  it('has no axe violations (colour contrast is checked in the browser spec)', async () => {
    await mountPage();
    const res = await axe.run(wrapper.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(res.violations.map((v) => v.id)).toEqual([]);
  });
});
