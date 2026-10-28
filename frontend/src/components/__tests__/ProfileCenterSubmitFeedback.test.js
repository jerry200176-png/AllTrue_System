import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ProfileCenterPage from '../../pages/ProfileCenterPage.vue';
import { getMe, updateMe } from '../../lib/profileService';

// #4488: the save button says 已儲存 only after the server call resolved.
vi.mock('../../lib/profileService', () => ({
  getMe: vi.fn(),
  getNotificationPrefs: vi.fn(() => Promise.resolve({ data: {} })),
  getSecuritySummary: vi.fn(() => Promise.resolve({ recent_logins: [], active_sessions: [] })),
  logoutOtherSessions: vi.fn(),
  updateMe: vi.fn(),
  updateNotificationPrefs: vi.fn(),
  uploadAvatar: vi.fn(),
}));

async function mountPage() {
  getMe.mockResolvedValue({ role: 'director', name: '主任', email: 'd@example.test', notification_preferences: {} });
  vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) })));
  const wrapper = mount(ProfileCenterPage, { props: { token: 'session' } });
  await flushPromises();
  return wrapper;
}

const saveButton = (w) => w.findAll('button').find((b) => b.text().includes('儲存基本資料'));
const face = (b) => b.find('.at-submit__face.is-on').text();

describe('ProfileCenterPage save feedback', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it('shows pending while saving and 已儲存 after the server confirms', async () => {
    let resolve;
    updateMe.mockReturnValue(new Promise((r) => { resolve = r; }));
    const w = await mountPage();
    const button = saveButton(w);

    await button.trigger('click');
    expect(button.attributes('aria-busy')).toBe('true');
    expect(face(button)).not.toContain('已儲存');

    resolve({ name: '主任', email: 'd@example.test' });
    await flushPromises();
    expect(face(button)).toContain('已儲存');
    expect(w.find('.section-msg.success').text()).toBe('基本資料已更新。');
  });

  it('keeps one save in flight across a tab switch (no second updateMe)', async () => {
    let resolve;
    updateMe.mockReturnValue(new Promise((r) => { resolve = r; }));
    const w = await mountPage();
    await saveButton(w).trigger('click');
    await flushPromises();

    await w.find('#profile-tab-security').trigger('click');
    await w.find('#profile-tab-profile').trigger('click');
    await flushPromises();
    const again = saveButton(w);
    expect(again.attributes('aria-busy')).toBe('true');
    await again.trigger('click');
    await flushPromises();
    expect(updateMe).toHaveBeenCalledTimes(1);

    resolve({ name: '主任', email: 'd@example.test' });
    await flushPromises();
    expect(face(saveButton(w))).toContain('已儲存');
  });

  it('shows 未儲存 and keeps the error message when the server rejects', async () => {
    updateMe.mockRejectedValue(new Error('伺服器忙碌'));
    const w = await mountPage();
    const button = saveButton(w);

    await button.trigger('click');
    await flushPromises();
    expect(face(button)).toContain('未儲存');
    expect(w.text()).toContain('伺服器忙碌');
  });
});
