import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import CourseManagement from '../../pages/CourseManagement.vue';
import { ENDED_REVIEW_LABEL } from '../../lib/courseMoneyState.js';

const fixture = vi.hoisted(() => ({ courses: [], total: 0, lastPage: 1 }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'synthetic-chronology' } } })) } } }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/authedFetch', () => ({
  getAccessToken: vi.fn(async () => 'synthetic-chronology'),
  authedFetch: vi.fn(async (url) => ({ ok: true, json: async () => String(url).startsWith('/api/v1/student-classes?')
    ? { data: fixture.courses, total: fixture.total || fixture.courses.length, current_page: 1, last_page: fixture.lastPage }
    : { data: [] } })),
}));

let wrapper;
const contract = (id, date, reason = null, extra = {}) => ({
  id, student_id: 900493, student_name: '合成學生', teacher_name: `測試老師${id}`, subject: 'english',
  first_class_date: date, StartDate: date, status: reason ? 'inactive' : 'active',
  history_reason: reason, closed_reason: reason, is_active_course: !reason,
  ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, SessionCount: 8,
  sessions_used: reason ? 8 : 3, remaining_sessions: reason ? 0 : 5, payment_status: 'paid', ...extra,
});
const order = () => wrapper.findAll('.course-row, .history-course-card').map((row) => Number(row.text().match(/測試老師(\d+)/)?.[1]));
async function open(courses, role = 'director') {
  fixture.courses = courses;
  wrapper = shallowMount(CourseManagement, { props: { branchId: 15, userRole: role }, global: { stubs: { PageHeader: false, SectionCard: false, CollapsibleGroup: false, StatusBadge: false } } });
  await flushPromises();
}
const expandHistory = async () => {
  for (const button of wrapper.findAll('.history-section__toggle')) await button.trigger('click');
};
beforeEach(() => {
  fixture.total = 0; fixture.lastPage = 1;
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200,
    json: async () => ({ data: [], current_page: 1, last_page: 1, total: 0, by_class: {} }), text: async () => '{}' })));
});
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });

describe('#527: real CourseManagement newest-first contracts', () => {
  it('renders new working contracts before pending reconciliation and older history', async () => {
    await open([
      contract(3612, '2026-09-06'),
      contract(2543, '2026-07-02', 'settled_pending', { payment_status: 'unpaid' }),
      contract(820, '2026-05-10', 'settled_pending', { payment_status: 'unpaid' }),
      contract(812, '2026-03-01', 'settled'),
    ]);
    await expandHistory();
    expect(order()).toEqual([3612, 2543, 820, 812]);
    expect(wrapper.findAll('.course-row, .history-course-card').map((row) => row.text().match(/開課\s*(\d{4}-\d{2}-\d{2})/)?.[1]))
      .toEqual(['2026-09-06', '2026-07-02', '2026-05-10', '2026-03-01']);
    expect(wrapper.findAll('.course-row')).toHaveLength(3);
    expect(wrapper.findAll('.course-row').find((row) => row.text().includes('測試老師2543')).text()).toContain('尚未完成繳費');
  });

  it('#559 collects interleaved history behind one disclosure, retaining newest-first order within each list', async () => {
    await open([
      contract(50, '2026-05-01'), contract(40, '2026-04-01', 'completed'),
      contract(30, '2026-03-01'), contract(20, '2026-02-01', 'settled'), contract(10, '2026-01-01'),
    ]);
    const buttons = wrapper.findAll('.history-section__toggle');
    expect(buttons).toHaveLength(1);
    const targets = buttons.map((button) => button.attributes('aria-controls'));
    expect(new Set(targets).size).toBe(1);
    const before = fetch.mock.calls.length;
    await buttons[0].trigger('click');
    expect(order()).toEqual([50, 30, 10, 40, 20]);
    expect(buttons[0].attributes('aria-expanded')).toBe('true');
    expect(wrapper.get('.history-section__count').text()).toBe('2 筆');
    for (const id of targets) expect(wrapper.findAll(`[id="${id}"]`)).toHaveLength(1);
    await buttons[0].trigger('click');
    expect(order()).toEqual([50, 30, 10]);
    expect(fetch.mock.calls).toHaveLength(before);
  });

  it('keeps the pending reconciliation action on the working row and opens the existing ledger', async () => {
    await open([contract(2543, '2026-07-02', 'settled_pending', {
      payment_status: 'unpaid', usage_balance_status: 'review_required',
    }), contract(812, '2026-03-01', 'settled')]);
    const pending = wrapper.get('.course-row');
    expect(pending.text()).toContain('尚未完成繳費');
    await pending.get('button[aria-label="堂數待對帳"]').trigger('click');
    expect(wrapper.vm.$.setupState.ledgerOpen).toBe(true);
    expect(wrapper.vm.$.setupState.ledgerInitialAction).toBeNull();
    expect(wrapper.emitted('navigate')).toBeUndefined();
  });

  it('retains truthful pagination and the existing historical accounting action', async () => {
    fixture.total = 60; fixture.lastPage = 2;
    await open([contract(50, '2026-05-01'), contract(20, '2026-02-01', 'completed')]);
    expect(wrapper.text()).toMatch(/第\s*1\s*\/\s*2\s*頁/);
    await expandHistory();
    const history = wrapper.get('.history-course-card');
    await history.get('.action-menu-trigger').trigger('click');
    const ledger = history.findAll('[role="menuitem"]').find((button) => button.text().includes('在這裡看帳務'));
    expect(ledger).toBeTruthy();
    await ledger.trigger('click');
    expect(wrapper.vm.$.setupState.ledgerOpen).toBe(true);
    expect(wrapper.emitted('navigate')).toBeUndefined();
  });

  it('shows the declared start with a legacy fallback and marks missing starts without using closure dates', async () => {
    await open([
      contract(30, null, null, { EndDate: '2026-12-31' }),
      contract(20, null, 'completed', { StartDate: '2026-02-01 00:00:00', EndDate: '2026-10-01' }),
      contract(10, '2026-01-01', null, { EndDate: '2026-09-01' }),
    ]);
    await expandHistory();
    const rows = wrapper.findAll('.course-row, .history-course-card');
    expect(order()).toEqual([30, 10, 20]);
    expect(rows.map((row) => row.text().match(/開課\s*(\d{4}-\d{2}-\d{2}|未填)/)?.[1]))
      .toEqual(['未填', '2026-01-01', '2026-02-01']);
  });

  it('keeps a new backdated contract first across mixed working/history without rewriting its displayed start', async () => {
    await open([
      contract(10, '2026-05-01'),
      contract(30, '2025-01-01', 'settled_pending', { payment_status: 'unpaid' }),
      contract(20, '2026-06-01', 'completed'),
    ]);
    await expandHistory();
    expect(order()).toEqual([30, 10, 20]);
    const rows = wrapper.findAll('.course-row, .history-course-card');
    expect(rows.map((row) => row.text().match(/開課\s*(\d{4}-\d{2}-\d{2})/)?.[1]))
      .toEqual(['2025-01-01', '2026-05-01', '2026-06-01']);
    expect(rows[0].text()).toContain('尚未完成繳費');
  });

  it('#559 shows the seven-history total and financial attention before expansion without losing the review action', async () => {
    await open([
      contract(90, '2026-09-01', 'completed', { closed_reason: 'settled_pending', payment_status: 'review_required', usage_balance_status: 'review_required' }),
      contract(80, '2026-08-01'),
      ...[70, 60, 50, 40, 30, 20].map(id => contract(id, '2026-01-01', 'completed')),
    ]);
    expect(wrapper.findAll('.history-section__toggle')).toHaveLength(1);
    expect(wrapper.get('.history-section__count').text()).toBe('7 筆');
    expect(wrapper.get('.history-section__toggle').text()).toContain('1 筆需留意');
    expect(wrapper.findAll('.history-course-card')).toHaveLength(0);
    await expandHistory();
    expect(wrapper.findAll('.history-course-card')).toHaveLength(7);
    const review = wrapper.get('[data-contract-id="90"]');
    expect(review.text()).toContain(ENDED_REVIEW_LABEL);
    const detail = review.get('button.tag-usage-review--action');
    expect(detail.text()).toContain('堂數待對帳');
    expect(detail.text()).toContain('查看對帳明細');
    await detail.trigger('click');
    expect(wrapper.vm.$.setupState.ledgerOpen).toBe(true);
    expect(wrapper.vm.$.setupState.ledgerStudentClassId).toBe(90);
  });

  it('#559 keeps each student disclosure independent, and leaves unknown/paused and pending reconciliation in the working list', async () => {
    await open([
      contract(60, '2026-06-01', 'completed'),
      contract(50, '2026-05-01', null, { _noncanonical: true, status: 'inactive' }),
      contract(40, '2026-04-01', 'settled_pending', { payment_status: 'unpaid' }),
      contract(30, '2026-03-01', 'completed'),
      contract(20, '2026-02-01', 'completed', { student_id: 900494, student_name: '另一位合成學生' }),
    ]);
    const buttons = wrapper.findAll('.history-section__toggle');
    expect(buttons).toHaveLength(2);
    expect(new Set(buttons.map(b => b.attributes('aria-controls'))).size).toBe(2);
    expect(order()).toEqual([50, 40]);
    expect(wrapper.text()).toContain('恢復課程');
    await buttons[0].trigger('click');
    expect(order()).toEqual([50, 40, 60, 30]);
    expect(buttons[1].attributes('aria-expanded')).toBe('false');
    await buttons[1].trigger('click');
    expect(order()).toEqual([50, 40, 60, 30, 20]);
  });
});
