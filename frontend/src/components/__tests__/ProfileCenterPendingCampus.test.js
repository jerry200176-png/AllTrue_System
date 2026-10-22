import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ProfileCenterPage from '../../pages/ProfileCenterPage.vue';
import { getMe, updateMe } from '../../lib/profileService';

// Founder policy 2026-10-22: a campus a teacher adds to themselves waits for that campus's director.
vi.mock('../../lib/profileService', () => ({
  getMe: vi.fn(),
  getNotificationPrefs: vi.fn(() => Promise.resolve({ data: {} })),
  getSecuritySummary: vi.fn(() => Promise.resolve({ recent_logins: [], active_sessions: [] })),
  logoutOtherSessions: vi.fn(),
  updateMe: vi.fn(),
  updateNotificationPrefs: vi.fn(),
  uploadAvatar: vi.fn(),
}));

const BRANCHES = [
  { id: 1, name: '總校' },
  { id: 2, name: '新分校' },
];

function teacherMe(pendingBranchIds) {
  return {
    role: 'teacher',
    name: '老師',
    email: 't@example.test',
    notification_preferences: {},
    security_summary: { recent_logins: [], active_sessions: [] },
    teacher_config: {
      branch_id: 1,
      branch_ids: [1, 2],
      pending_branch_ids: pendingBranchIds,
      subject_ids: [],
      subject_level_scopes: [],
    },
  };
}

async function mountPage(me) {
  getMe.mockResolvedValue(me);
  vi.stubGlobal('fetch', vi.fn((url) => Promise.resolve({
    ok: true,
    json: () => Promise.resolve(String(url).includes('/branches') ? BRANCHES : []),
  })));
  const wrapper = mount(ProfileCenterPage, { props: { token: 'session' } });
  await flushPromises();
  return wrapper;
}

function chipText(wrapper, name) {
  return wrapper.findAll('.chip').map((c) => c.text()).find((t) => t.startsWith(name));
}

describe('ProfileCenterPage pending campus', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it('marks a campus that still waits for its director and explains who approves it', async () => {
    const wrapper = await mountPage(teacherMe([2]));

    expect(chipText(wrapper, '新分校')).toBe('新分校（待審核）');
    expect(chipText(wrapper, '總校')).toBe('總校');
    expect(wrapper.text()).toContain('新加入的分校要等該分校主任核准後才會生效');
  });

  it('says the new campus waits for that campus director after saving', async () => {
    const wrapper = await mountPage(teacherMe([]));
    expect(chipText(wrapper, '新分校')).toBe('新分校');

    updateMe.mockResolvedValue({ teacher_config: teacherMe([2]).teacher_config });
    const save = wrapper.findAll('button').find((b) => b.text().includes('儲存教學設定'));
    await save.trigger('click');
    await flushPromises();

    expect(updateMe).toHaveBeenCalledWith('session', expect.objectContaining({ branch_id: 1, multi_branches: [2] }));
    expect(chipText(wrapper, '新分校')).toBe('新分校（待審核）');
    expect(wrapper.find('.section-msg.success').text()).toBe(
      '教學設定已更新。標示「待審核」的分校，要等該分校主任核准後才會生效。'
    );
  });
});
