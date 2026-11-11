import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import BranchHealthBoard from '../../pages/BranchHealthBoard.vue';

// #4989: 分校健康 on components/ui, mounted with the API answered by a fetch stub.
const dim = (status, label) => ({ status, label, source: '來源', signals: [{ key: 's', label: '訊號', value: '3 筆' }], period: 'current', next_step: '下一步內容' });
const dimensions = { students: dim('green', '穩定'), teaching: dim('yellow', '需注意'), parents: dim('unavailable', '待接資料'), teachers: dim('red', '緊急'), operations: dim('green', '穩定') };
const rows = [
  { branch_id: 1, branch_name: '台北分校', status: 'red', headline: '教師訊號需要優先查看', dimensions },
  { branch_id: 2, branch_name: '新莊分校', status: 'green', headline: '目前沒有紅色或黃色訊號。', dimensions },
];
const respond = (body, ok = true, status = 200) => vi.fn(async () => ({ ok, status, json: async () => body }));
const mountBoard = async (fetchMock) => {
  vi.stubGlobal('fetch', fetchMock);
  const w = mount(BranchHealthBoard, { props: { token: 'tok' } });
  await flushPromises();
  return w;
};

afterEach(() => vi.unstubAllGlobals());

describe('BranchHealthBoard', () => {
  it('calls the same endpoint with the bearer token and shows the title as h2', async () => {
    const fetchMock = respond({ data: rows, meta: { generated_at: '2026-09-09T12:00:00Z' } });
    const w = await mountBoard(fetchMock);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/branch-health', { headers: { Accept: 'application/json', Authorization: 'Bearer tok' } });
    expect(w.find('h2').text()).toBe('分校健康');
    expect(w.find('h1').exists()).toBe(false);
    expect(w.text()).toContain('這不是總分或排名');
  });

  it('keeps the labelled table, the mobile list and the summary counts', async () => {
    const w = await mountBoard(respond({ data: rows, meta: {} }));
    expect(w.find('caption').text()).toBe('各分校五個營運健康維度');
    expect(w.findAll('thead th').map((th) => th.text())).toEqual(['分校', '學生', '教學', '家長', '教師', '營運', '主要狀況']);
    expect(w.find('.branch-health__mobile-list').attributes('aria-label')).toBe('分校健康清單');
    expect(w.findAll('.branch-health__mobile-card')).toHaveLength(2);
    const summary = w.find('[aria-label="分校健康摘要"]').text();
    expect(summary).toContain('目前分校2');
    expect(summary).toContain('優先處理1');
    expect(summary).toContain('資料不足2');
  });

  it('selecting a branch swaps the detail section (read only)', async () => {
    const w = await mountBoard(respond({ data: rows, meta: {} }));
    expect(w.find('#bh-detail-title').text()).toBe('台北分校 詳情');
    await w.findAll('tbody tr')[1].trigger('click');
    expect(w.find('#bh-detail-title').text()).toBe('新莊分校 詳情');
    expect(w.text()).toContain('下一步：下一步內容');
    expect(w.text()).toContain('期間：目前資料');
  });

  it('shows the empty state, and an error alert whose 重試 refetches', async () => {
    const empty = await mountBoard(respond({ data: [], meta: {} }));
    expect(empty.text()).toContain('目前沒有啟用中的分校資料');
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 503, json: async () => ({ message: '暫時無法載入' }) })
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ data: rows, meta: {} }) });
    const w = await mountBoard(fetchMock);
    expect(w.find('[role="alert"]').text()).toContain('無法載入分校健康');
    expect(w.find('[role="alert"]').text()).toContain('暫時無法載入');
    await w.find('[role="alert"] button').trigger('click');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(w.findAll('.branch-health__mobile-card')).toHaveLength(2);
  });

  it('controls are at least 48px tall (h-12) and the page adds the 6px phone gutter', async () => {
    const w = await mountBoard(respond({ data: rows, meta: {} }));
    expect(w.find('button[data-slot="button"]').classes()).toContain('tw:h-12');
    expect(w.classes().join(' ')).toContain('tw:px-[calc(var(--ds-space-4)-10px)]');
  });
});
