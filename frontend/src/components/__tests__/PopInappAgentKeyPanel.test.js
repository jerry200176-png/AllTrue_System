import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import PopInappAgentKeyPanel from '../PopInappAgentKeyPanel.vue';
import ProfileCenterPage from '../../pages/ProfileCenterPage.vue';

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

const SECRET = 'alltrue_pop_inapp_SECRET';
const campuses = [{ id: 1, name: '內湖分校', code: 'neihu' }, { id: 2, name: '東湖分校', code: 'donghu' }];
const row = { id: 7, name: 'Claude 雲端回報處理', campus_ids: [1, 2], scopes: ['pop:draft', 'pop:dry-run'], active: true, created_at: '2026-10-23T08:00:00+08:00', expires_at: '2027-01-21T08:00:00+08:00', expired: false };

function stubApi({ campusList = campuses, list = { ok: true } } = {}) {
  let rows = [];
  const calls = [];
  vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
    const method = init.method || 'GET';
    calls.push({ method, url, body: init.body ? JSON.parse(init.body) : null });
    if (url === '/api/v1/campuses') return { ok: true, status: 200, text: async () => JSON.stringify(campusList) };
    if (method === 'POST') {
      rows = [row];
      return { ok: true, status: 201, json: async () => ({ key: SECRET, record: row }) };
    }
    if (method === 'DELETE') {
      rows = [{ ...row, active: false }];
      return { ok: true, status: 200, json: async () => ({ revoked: true, id: 7, cancelled_request_ids: ['req-1', 'req-2'] }) };
    }
    if (!list.ok) return { ok: false, status: 500, json: async () => ({ message: 'boom' }) };
    return { ok: true, status: 200, json: async () => ({ data: rows, ttl_days: 90 }) };
  }));
  return calls;
}

const mountPanel = () => mount(PopInappAgentKeyPanel, { props: { token: 'session' }, global: { stubs: { teleport: true } } });

async function issueKey(w) {
  await w.get('[data-testid="pop-key-name"]').setValue('Claude 雲端回報處理');
  await w.get('[data-testid="pop-key-select-all"]').trigger('click');
  await w.get('form').trigger('submit');
  await flushPromises();
}

afterEach(() => vi.unstubAllGlobals());

describe('PopInappAgentKeyPanel', () => {
  it('shows the key once with its campuses and expiry, copies it, then drops it on close', async () => {
    const calls = stubApi();
    const writeText = vi.fn(async () => {});
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const w = mountPanel();
    await flushPromises();
    expect(w.text()).toContain('目前沒有任何金鑰');
    expect(w.text()).toContain('建立後 90 天自動失效');

    await issueKey(w);
    const post = calls.find((c) => c.method === 'POST');
    expect(post.url).toBe('/api/v1/admin/pop-inapp-agent-clients');
    expect(post.body).toEqual({ name: 'Claude 雲端回報處理', campus_ids: [1, 2] });
    expect(w.get('[data-testid="pop-key-secret"]').element.value).toBe(SECRET);
    expect(w.text()).toContain('只會顯示一次，請立即存到雲端環境的 secret');
    expect(w.findAll('[data-testid="pop-key-row"]')).toHaveLength(1);
    expect(w.get('[data-testid="pop-key-row"]').text()).toContain('內湖分校、東湖分校');
    expect(w.get('[data-testid="pop-key-issue"]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid="pop-key-copy"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith(SECRET);
    expect(w.get('[data-testid="pop-key-copy"]').text()).toContain('已複製');

    await w.get('[data-testid="pop-key-dismiss"]').trigger('click');
    expect(w.find('[data-testid="pop-key-secret"]').exists()).toBe(false);
    expect(w.html()).not.toContain(SECRET);
    expect(w.emitted('update:pending').map((e) => e[0])).toEqual([true, false]);
  });

  it('blocks issuing until at least one campus is ticked', async () => {
    const calls = stubApi();
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="pop-key-name"]').setValue('x');
    expect(w.get('[data-testid="pop-key-issue"]').attributes('disabled')).toBeDefined();
    expect(w.find('[data-testid="pop-key-campus-required"]').exists()).toBe(true);
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await w.findAll('[data-testid="pop-key-campus"]')[1].setValue(true);
    expect(w.get('[data-testid="pop-key-issue"]').attributes('disabled')).toBeUndefined();
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST').body.campus_ids).toEqual([2]);
  });

  it('blocks issuing when the campus list cannot be loaded', async () => {
    const calls = stubApi({ campusList: [] });
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="pop-key-name"]').setValue('x');
    expect(w.find('[data-testid="pop-key-campus-error"]').exists()).toBe(true);
    expect(w.get('[data-testid="pop-key-issue"]').attributes('disabled')).toBeDefined();
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('revokes only after the confirm dialog and reports the cancelled requests', async () => {
    const calls = stubApi();
    const w = mountPanel();
    await flushPromises();
    await issueKey(w);
    await w.get('[data-testid="pop-key-dismiss"]').trigger('click');

    await w.get('[data-testid="pop-key-revoke"]').trigger('click');
    expect(w.get('[data-testid="pop-key-revoke-text"]').text()).toContain('還沒執行的申請會一起取消');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);

    await w.get('[data-testid="pop-key-confirm"]').trigger('click');
    await flushPromises();
    expect(calls.filter((c) => c.method === 'DELETE').map((c) => c.url)).toEqual(['/api/v1/admin/pop-inapp-agent-clients/7']);
    expect(w.get('[data-testid="pop-key-notice"]').text()).toContain('取消 2 筆還沒執行的申請');
    expect(w.find('[data-testid="pop-key-revoke-text"]').exists()).toBe(false);
    expect(w.get('[data-testid="pop-key-row"]').text()).toContain('已撤銷');
    expect(w.find('[data-testid="pop-key-revoke"]').exists()).toBe(false);
  });

  it('shows the list error without the empty state', async () => {
    stubApi({ list: { ok: false } });
    const w = mountPanel();
    await flushPromises();
    expect(w.text()).toContain('boom');
    expect(w.find('[data-testid="pop-key-empty"]').exists()).toBe(false);
  });

  it('treats a 2xx issue without a key as an error and shows no dialog', async () => {
    stubApi();
    const base = globalThis.fetch;
    vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => (init.method === 'POST'
      ? { ok: true, status: 201, json: async () => ({ record: row }) }
      : base(url, init))));
    const w = mountPanel();
    await flushPromises();
    await issueKey(w);
    expect(w.find('[data-testid="pop-key-secret"]').exists()).toBe(false);
    expect(w.text()).toContain('金鑰可能已建立，但沒有收到內容');
  });
});

describe('ProfileCenterPage key panel visibility', () => {
  const mountPage = async (r) => {
    role.value = r;
    stubApi();
    const w = mount(ProfileCenterPage, {
      props: { token: 'session', initialTab: 'security' },
      global: { stubs: { teleport: true, TriageTokenPanel: true, PopInappAgentKeyPanel: true, AvatarCropModal: true } },
    });
    await flushPromises();
    return w;
  };

  it('renders the panel for a super admin', async () => {
    const w = await mountPage('super_admin');
    expect(w.findComponent(PopInappAgentKeyPanel).exists()).toBe(true);
  });

  it('keeps the security tab open while a key is being issued', async () => {
    const w = await mountPage('super_admin');
    w.findComponent(PopInappAgentKeyPanel).vm.$emit('update:pending', true);
    await w.get('#profile-tab-notifications').trigger('click');
    expect(w.find('#profile-panel-security').exists()).toBe(true);
    w.findComponent(PopInappAgentKeyPanel).vm.$emit('update:pending', false);
    await w.get('#profile-tab-notifications').trigger('click');
    expect(w.find('#profile-panel-security').exists()).toBe(false);
  });

  it.each(['director', 'teacher'])('does not render the panel for a %s', async (r) => {
    const w = await mountPage(r);
    expect(w.findComponent(PopInappAgentKeyPanel).exists()).toBe(false);
  });
});
