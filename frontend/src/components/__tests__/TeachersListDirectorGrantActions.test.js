import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

const { getSession } = vi.hoisted(() => ({ getSession: vi.fn() }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession } } }));
import TeachersList from '../../pages/TeachersList.vue';

const response = (body) => ({ ok: true, status: 200, json: async () => body });
const teachers = [
  { id: 3001, username: '主任老師', status: 'active', branch_id: 1, branch_ids: [1], profile_management_blocked_by_director_grant: true, profile_management_block_reason: 'director_grant_holder' },
  { id: 3002, username: '一般老師', status: 'active', branch_id: 1, branch_ids: [1], profile_management_blocked_by_director_grant: false },
  { id: 3003, username: '舊版老師', status: 'active', branch_id: 1, branch_ids: [1] },
];

describe('teacher director-grant management hint', () => {
  let fetchMock;
  beforeEach(() => {
    getSession.mockResolvedValue({ data: { session: { access_token: 'test-token' } } });
    fetchMock = vi.fn((url) => Promise.resolve(response(String(url).includes('/teachers?') ? { data: teachers } : [])));
    globalThis.fetch = fetchMock;
  });
  afterEach(() => { vi.restoreAllMocks(); delete globalThis.fetch; });

  it('disables the four restricted actions and explains why while leaving other teachers usable', async () => {
    const wrapper = shallowMount(TeachersList, { props: { branchId: 1 }, global: { stubs: { AtFilterBar: false, AtDialog: false, AtButton: false, teleport: true } } });
    await flushPromises();
    const card = (name) => wrapper.findAll('.teacher-profile-card').find((item) => item.text().includes(name));
    const protectedCard = card('主任老師');
    expect(protectedCard.text().match(/此帳號具主任權限，請由超級管理員處理/g)).toHaveLength(1);
    const callsBeforeClicks = fetchMock.mock.calls.length;
    const buttons = protectedCard.findAll('button');
    for (const name of ['綁定', '編輯', '重設密碼', '刪除']) {
      const button = buttons.find((item) => item.text() === name);
      expect(button.element.disabled, name).toBe(true);
      expect(button.attributes('aria-describedby')).toBe('teacher-management-3001');
      await button.trigger('click');
    }
    expect(buttons.find((item) => item.text() === '課程').element.disabled).toBe(false);
    expect(fetchMock.mock.calls.slice(callsBeforeClicks).some(([, options]) => options?.method && options.method !== 'GET')).toBe(false);
    expect(wrapper.find('.modal-overlay').exists()).toBe(false);
    for (const name of ['一般老師', '舊版老師']) {
      expect(card(name).findAll('button').find((item) => item.text() === '編輯').element.disabled).toBe(false);
    }
    wrapper.unmount();
  });
});
