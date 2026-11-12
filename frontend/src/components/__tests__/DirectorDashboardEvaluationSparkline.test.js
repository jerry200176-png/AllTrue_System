import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

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

let wrapper;

beforeEach(() => {
  localStorage.clear();
  sessionStorage.clear();
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-test-token' }));
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-12T04:00:00Z'));
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('director pending-evaluation trend completeness (#5002)', () => {
  it.each([
    { loaded: 100, total: 101, visible: false },
    { loaded: 49, total: 50, visible: false },
    { loaded: 100, total: 100, visible: true },
    { loaded: 99, total: 99, visible: true },
    { loaded: 99, total: undefined, visible: true },
    { loaded: 100, total: undefined, visible: false },
  ])('shows a trend only for complete data: $loaded loaded, total $total', async ({ loaded, total, visible }) => {
    const payload = { data: Array.from({ length: loaded }, (_, id) => ({ id: id + 1, SessionDate: '2026-11-12' })) };
    if (total !== undefined) payload.total = total;
    vi.stubGlobal('fetch', vi.fn(async (url) => ({
      ok: true, status: 200,
      json: async () => String(url).includes('/v1/learning-records?') ? payload : [],
    })));
    wrapper = mount(DirectorDashboard, { props: { branchId: 1 }, global: { stubs: {
      teleport: true, OperationsQuickStart: true, TodayProgressCard: true, RecentSubstitutesCard: true,
      TeacherAssessmentFillRateCard: true, EngagementRankStrip: true, PaymentSlipModal: true,
      BlurFade: { template: '<div><slot /></div>' },
      NumberTicker: { props: ['value'], template: '<span>{{ value }}</span>' },
    } } });
    await flushPromises();
    const card = wrapper.get('[data-testid="kpi-evals"]');
    // Preserve the total and navigation even when the sample cannot support a trend.
    expect(card.get('.director-num').text()).toBe(`${total ?? loaded}份`);
    const trend = card.find('svg[role="img"][aria-label="近 7 天上課日的待審評量數"]');
    expect(trend.exists()).toBe(visible);
    await card.get('button').trigger('click');
    expect(wrapper.emitted('navigate').at(-1)).toEqual([{ target: 'learning' }]);
  });
});
