import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import GlobalSearchResults from '../GlobalSearchResults.vue';
import { createLatestRequestGuard, fetchGlobalSearch } from '../../lib/globalSearchApi';

describe('global search V1 contract', () => {
  it('does not request empty queries and normalizes bounded server results', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ groups: [{ key: 'students', items: [{ id: 1 }] }] }),
    });
    vi.stubGlobal('fetch', fetchMock);

    await expect(fetchGlobalSearch('', 'token')).resolves.toEqual({ query: '', groups: [] });
    expect(fetchMock).not.toHaveBeenCalled();
    await expect(fetchGlobalSearch('王小', 'token')).resolves.toEqual({
      query: '王小',
      groups: [{ key: 'students', items: [{ id: 1 }] }],
    });
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/global-search?q=%E7%8E%8B%E5%B0%8F&limit=5', expect.objectContaining({
      headers: expect.objectContaining({ Authorization: 'Bearer token' }),
    }));
    vi.unstubAllGlobals();
  });

  it('ignores stale request completions through the latest-request guard', () => {
    const guard = createLatestRequestGuard();
    const first = guard.next();
    const second = guard.next();
    expect(guard.isCurrent(first)).toBe(false);
    expect(guard.isCurrent(second)).toBe(true);
  });

  it('renders grouped entity and navigation results with loading, error, and selection states', async () => {
    const wrapper = mount(GlobalSearchResults, {
      props: {
        query: '王小',
        entityGroups: [{ key: 'students', title: '學生', items: [{ id: 1, type: 'student', title: '王小明', subtitle: 'J1', meta: '分校：台北校' }] }],
        featureGroups: [{ key: 'teaching', title: '教學現場', items: [{ page: 'calendar', label: '行事曆', icon: 'calendar_today' }] }],
      },
    });
    expect(wrapper.text()).toContain('學生');
    expect(wrapper.text()).toContain('王小明');
    expect(wrapper.text()).toContain('行事曆');
    await wrapper.findAll('button')[0].trigger('click');
    expect(wrapper.emitted('select')[0][0]).toEqual(expect.objectContaining({ kind: 'entity' }));

    await wrapper.setProps({ loading: true });
    expect(wrapper.get('[role="status"]').text()).toContain('搜尋中');
    await wrapper.setProps({ loading: false, error: 'search_failed' });
    expect(wrapper.get('[role="alert"]').text()).toContain('搜尋暫時無法完成');
    await wrapper.get('.global-search-retry').trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });
});
