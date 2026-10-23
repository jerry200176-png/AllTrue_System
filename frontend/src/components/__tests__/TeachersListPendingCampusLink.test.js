import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

const { getSession } = vi.hoisted(() => ({ getSession: vi.fn() }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession } } }));

import TeachersList from '../../pages/TeachersList.vue';

const response = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, statusText: 'OK', json: async () => body });

// #4433: synthetic rows only. 3001 is active at campus 1 and asks to join the viewer's campus 2;
// 3002 asks to join campus 3, which the viewer does not manage (pending, but not approvable here).
const teachers = [
  { id: 3001, username: '甲老師', status: 'active', branch_id: 1, branch_ids: [1, 2], pending_branch_ids: [2], approvable_branch_ids: [2] },
  { id: 3002, username: '乙老師', status: 'active', branch_id: 2, branch_ids: [2, 3], pending_branch_ids: [3], approvable_branch_ids: [] },
  { id: 3003, username: '丙老師', status: 'active', branch_id: 2, branch_ids: [2], pending_branch_ids: [], approvable_branch_ids: [] },
];
const branches = [{ id: 1, name: '總校' }, { id: 2, name: '新莊分校' }, { id: 3, name: '板橋分校' }];

describe('TeachersList pending campus links (#4433)', () => {
  let fetchMock;
  beforeEach(() => {
    getSession.mockResolvedValue({ data: { session: { access_token: 'test-token' } } });
    fetchMock = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/teachers?')) return Promise.resolve(response({ data: teachers }));
      if (url.includes('/branches')) return Promise.resolve(response(branches));
      return Promise.resolve(response({ data: [] }));
    });
    globalThis.fetch = fetchMock;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
  });

  const mountTeachers = () => shallowMount(TeachersList, {
    props: { branchId: 2 },
    global: { stubs: { AtFilterBar: false, AtDialog: false, AtButton: false, teleport: true } },
  });
  const cardFor = (wrapper, name) => wrapper.findAll('.teacher-profile-card').find((c) => c.text().includes(name));

  it('lists an active teacher with a pending link to the viewer campus and approves only that campus', async () => {
    const wrapper = mountTeachers();
    await flushPromises();

    expect(wrapper.find('#teachers-tab-pending .badge--pending').text()).toBe('1');
    await wrapper.find('#teachers-tab-pending').trigger('click');

    const names = wrapper.findAll('.teacher-profile-card .tpc-name-text').map((n) => n.text());
    expect(names).toEqual(['甲老師']);
    const card = cardFor(wrapper, '甲老師');
    expect(card.find('.tpc-status').text()).toBe('在職');
    expect(card.find('.tpc-link-approve').text()).toBe('核准加入新莊分校');
    expect(card.find('.tpc-link-reject').text()).toBe('拒絕加入新莊分校');

    await card.find('.tpc-link-approve').trigger('click');
    expect(wrapper.find('[role="dialog"]').text()).toContain('核准「甲老師」加入新莊分校');
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/campus-links/'))).toBe(false);
    await wrapper.find('.teacher-link-confirm').trigger('click');
    await flushPromises();
    const call = fetchMock.mock.calls.find(([url]) => String(url).includes('/campus-links/'));
    expect(String(call[0])).toMatch(/\/profiles\/3001\/campus-links\/2\/approve$/);
    expect(call[1].method).toBe('POST');
    wrapper.unmount();
  });

  it('shows another campus pending link as pending without approve or reject buttons', async () => {
    const wrapper = mountTeachers();
    await flushPromises();

    const card = cardFor(wrapper, '乙老師');
    expect(card.text()).toContain('板橋分校（待審核）');
    expect(card.find('.tpc-link-approve').exists()).toBe(false);
    expect(card.find('.tpc-link-reject').exists()).toBe(false);
    wrapper.unmount();
  });
});
