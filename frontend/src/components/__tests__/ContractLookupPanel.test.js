import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ContractLookupPanel from '../ContractLookupPanel.vue';
import ProfileCenterPage from '../../pages/ProfileCenterPage.vue';
import { parseContractIds } from '../../lib/contractLookup';

const role = vi.hoisted(() => ({ value: 'super_admin' }));
vi.mock('../../lib/profileService', () => ({
  getMe: vi.fn(async () => ({ role: role.value, name: 'x', notification_preferences: {}, security_summary: { recent_logins: [], active_sessions: [] } })),
  getNotificationPrefs: vi.fn(async () => ({ data: {} })),
  getSecuritySummary: vi.fn(async () => ({ data: { recent_logins: [], active_sessions: [] } })),
  logoutOtherSessions: vi.fn(),
  updateMe: vi.fn(),
  updateNotificationPrefs: vi.fn(),
  uploadAvatar: vi.fn(),
}));

// Synthetic rows only.
const countRow = {
  id: 101, found: true, campus_id: 7, campus_name: '測試分校', student_id: 5, student_name: '測試學生', subject: '數學', teacher_name: '測試老師',
  schedule_mode: 'count', start_date: '2026-09-01', end_date: '2027-01-31', enabled: true, charge: 8800, rate: 900, sessions: 8, rate_unit: 'session', quantity: 8, rate_x_sessions: 7200,
  has_invoice: true, money_note: '未繳；總費用與單價×堂數不同',
};
const monthlyRow = { ...countRow, id: 102, schedule_mode: 'date', end_date: null, enabled: false, charge: null, sessions: null, quantity: null, rate_x_sessions: null, money_note: '尚未開帳單' };
const hourlyRow = { ...countRow, id: 103, rate_unit: 'hour', rate: 750, sessions: 8, quantity: 16, charge: 12000, rate_x_sessions: 12000, money_note: '未繳' };

function stubFetch(data, { ok = true, status = 200, body } = {}) {
  const calls = [];
  vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
    calls.push({ url, auth: init.headers?.Authorization });
    return { ok, status, json: async () => body ?? { data } };
  }));
  return calls;
}

const mountPanel = () => mount(ContractLookupPanel, { props: { token: 'session' }, global: { stubs: { AccountingLedgerModal: true } } });

async function search(w, text) {
  await w.get('#contract-lookup-input').setValue(text);
  await w.get('[data-testid="lookup-submit"]').trigger('click');
  await flushPromises();
}

afterEach(() => vi.unstubAllGlobals());

describe('parseContractIds', () => {
  it('splits on commas, spaces, newlines and full-width commas, keeps order, drops duplicates', () => {
    expect(parseContractIds('101, 102\n103，104、101  105;106').ids).toEqual([101, 102, 103, 104, 105, 106]);
    expect(parseContractIds('  \n ').ids).toEqual([]);
    expect(parseContractIds(null).ids).toEqual([]);
  });

  it('reports everything that is not a positive whole number instead of guessing', () => {
    expect(parseContractIds('12, abc, 3.5, -4, 0, 007, 99999999999')).toEqual({ ids: [12], invalid: ['abc', '3.5', '-4', '0', '007', '99999999999'] });
  });
});

describe('ContractLookupPanel', () => {
  it('sends the parsed ids once and shows each contract as a table row and a phone card with the money columns', async () => {
    const calls = stubFetch([countRow, monthlyRow, { id: 999, found: false }]);
    const w = mountPanel();
    await search(w, '101, 102\n999 101');

    expect(calls).toEqual([{ url: '/api/v1/admin/student-classes/lookup?ids=101,102,999', auth: 'Bearer session' }]);
    const rows = w.findAll('[data-testid="lookup-row"]');
    expect(rows).toHaveLength(3);
    expect(rows[0].text()).toContain('測試學生');
    expect(rows[0].text()).toContain('NT$ 8,800');
    expect(rows[0].text()).toContain('900 × 8 堂 = NT$ 7,200');
    expect(rows[0].text()).toContain('未繳；總費用與單價×堂數不同');
    expect(rows[1].text()).toContain('已停課');
    expect(rows[1].text()).toContain('—'); // monthly: no single total, no rate x sessions
    expect(rows[2].get('[data-testid="lookup-missing"]').text()).toBe('找不到這個編號');
    const cards = w.findAll('[data-testid="lookup-card"]');
    expect(cards).toHaveLength(3);
    expect(cards[0].text()).toContain('目前總費用');
    expect(cards[0].text()).toContain('NT$ 8,800');
    expect(cards[2].text()).toContain('找不到這個編號');
  });

  it('shows hours, not sessions, for an hourly contract (table and card)', async () => {
    stubFetch([hourlyRow]);
    const w = mountPanel();
    await search(w, '103');
    expect(w.get('[data-testid="lookup-row"]').text()).toContain('750 × 16 小時 = NT$ 12,000');
    expect(w.get('[data-testid="lookup-card"]').text()).toContain('750 × 16 小時 = NT$ 12,000');
    expect(w.get('[data-testid="lookup-row"]').text()).not.toContain('750 × 8');
  });

  it('opens the existing student billing for that contract in its own campus', async () => {
    stubFetch([countRow]);
    const w = mountPanel();
    await search(w, '101');
    expect(w.findComponent({ name: 'AccountingLedgerModal' }).props('show')).toBe(false);

    await w.get('[data-testid="lookup-open"]').trigger('click');

    const ledger = w.findComponent({ name: 'AccountingLedgerModal' });
    expect([ledger.props('show'), ledger.props('studentClassId'), ledger.props('branchId')]).toEqual([true, 101, 7]);
  });

  it('refuses bad input without calling the server', async () => {
    const calls = stubFetch([]);
    const w = mountPanel();
    await search(w, '12, abc');
    expect(w.get('[data-testid="lookup-error"]').text()).toContain('abc');
    await search(w, '   ');
    expect(w.get('[data-testid="lookup-error"]').text()).toContain('至少一個');
    await search(w, Array.from({ length: 51 }, (_, i) => i + 1).join(','));
    expect(w.get('[data-testid="lookup-error"]').text()).toContain('最多 50');
    expect(calls).toEqual([]);
  });

  it('shows the server error and keeps the previous rows out of the way', async () => {
    stubFetch(null, { ok: false, status: 403, body: { message: 'Forbidden' } });
    const w = mountPanel();
    await search(w, '101');
    expect(w.get('[data-testid="lookup-error"]').text()).toBe('Forbidden');
    expect(w.find('[data-testid="lookup-table"]').exists()).toBe(false);
  });
});

describe('ProfileCenterPage contract lookup visibility', () => {
  const mountPage = async (r) => {
    role.value = r;
    stubFetch([]);
    const w = mount(ProfileCenterPage, {
      props: { token: 'session', initialTab: 'security' },
      global: { stubs: { teleport: true, TriageTokenPanel: true, PopInappAgentKeyPanel: true, AvatarCropModal: true, AccountingLedgerModal: true } },
    });
    await flushPromises();
    return w;
  };

  it('is on the security tab for a super admin', async () => {
    const w = await mountPage('super_admin');
    expect(w.findComponent(ContractLookupPanel).exists()).toBe(true);
    expect(w.text()).toContain('用編號找合約');
  });

  it.each(['director', 'teacher'])('is hidden for a %s', async (r) => {
    const w = await mountPage(r);
    expect(w.findComponent(ContractLookupPanel).exists()).toBe(false);
    expect(w.text()).not.toContain('用編號找合約');
  });
});
