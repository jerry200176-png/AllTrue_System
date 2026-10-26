import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineAsyncComponent, defineComponent, h, ref } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { openStudentsAfterDashboardOpened } from '../../../e2e/fixtures/directorDashboardTelemetry.js';

vi.mock('../../supabase', () => {
  const query = () => {
    const chain = new Proxy({}, { get: (_, key) => key === 'then'
      ? (resolve) => resolve({ data: [], error: null, count: 0 })
      : () => chain });
    return chain;
  };
  return { supabase: { from: query, rpc: query, auth: { getSession: async () => ({ data: { session: null } }) },
    channel: () => ({ on() { return this; }, subscribe() { return this; } }), removeChannel() {} } };
});

import DirectorDashboard from '../../pages/DirectorDashboard.vue';

let wrappers;
let telemetry;

beforeEach(() => {
  wrappers = [];
  telemetry = [];
  localStorage.clear();
  sessionStorage.clear();
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'isolated-fixture-token' }));
  vi.stubGlobal('fetch', vi.fn(async (url, options) => {
    if (String(url) === '/api/v1/adoption/events') telemetry.push(JSON.parse(options.body));
    return { ok: true, status: 200, json: async () => [] };
  }));
});

afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount());
  vi.unstubAllGlobals();
});

function lazyHomepage() {
  let resolveDashboard;
  const pending = new Promise((resolve) => { resolveDashboard = resolve; });
  const Dashboard = defineAsyncComponent(() => pending);
  const active = ref('director');
  // Campus bootstrap is already complete. The remaining App.vue boundary is
  // the real async Dashboard chunk; the sidebar exists before it resolves.
  const wrapper = mount(defineComponent({ setup: () => () => h('div', [
    h('button', { 'data-test': 'students-nav', onClick: () => { active.value = 'students'; } }, '學生管理'),
    active.value === 'director' ? h(Dashboard, { branchId: 1 }) : h('div', 'student-page'),
  ]) }), { global: { stubs: { teleport: true, OperationsQuickStart: true, TodayProgressCard: true,
    RecentSubstitutesCard: true, TeacherAssessmentFillRateCard: true, EngagementRankStrip: true, PaymentSlipModal: true } } });
  wrappers.push(wrapper);
  const click = vi.fn(() => wrapper.get('[data-test="students-nav"]').trigger('click'));
  return { page: { getByRole: () => ({ click }) }, click, active, resolve: () => resolveDashboard(DirectorDashboard) };
}

describe('production tutoring acceptance: real dashboard producer readiness', () => {
  it('reproduces zero events when navigation cancels the delayed homepage mount', async () => {
    const home = lazyHomepage();
    await home.click();
    home.resolve();
    await flushPromises();
    expect(home.active.value).toBe('students');
    expect(telemetry).toEqual([]);
  });

  it('observes the real delayed dashboard event before opening students', async () => {
    const home = lazyHomepage();
    const timer = setTimeout(home.resolve, 25);
    try {
      await openStudentsAfterDashboardOpened(home.page, telemetry, 1);
      await new Promise((resolve) => setTimeout(resolve, 50));
      await flushPromises();
      expect(home.active.value).toBe('students');
      expect(telemetry.filter((event) => event.event === 'dashboard_opened')).toHaveLength(1);
      expect(telemetry[0]).toMatchObject({ branch_id: 1, meta: { role: 'director', page: 'director-dashboard' } });
    } finally { clearTimeout(timer); }
  });

  it('fails closed without an observed event and never navigates', async () => {
    const click = vi.fn();
    const page = { getByRole: () => ({ click }) };
    await expect(openStudentsAfterDashboardOpened(page, [], 1)).rejects.toThrow(/dashboard_opened/);
    expect(click).not.toHaveBeenCalled();
  }, 12_000);

  it('does not navigate on an event from a different campus', async () => {
    const click = vi.fn();
    const page = { getByRole: () => ({ click }) };
    const otherCampus = [{ event: 'dashboard_opened', branch_id: 2,
      meta: { role: 'director', page: 'director-dashboard' } }];
    await expect(openStudentsAfterDashboardOpened(page, otherCampus, 1)).rejects.toThrow(/dashboard_opened/);
    expect(click).not.toHaveBeenCalled();
  }, 12_000);
});
