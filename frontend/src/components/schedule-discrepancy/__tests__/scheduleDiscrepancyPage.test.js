import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

// #4989: 課表回報管理 on components/ui. Wording, tabs and the API calls are unchanged: these tests pin them.
const api = vi.hoisted(() => ({
  fetchDiscrepancies: vi.fn(),
  fetchDiscrepancySummary: vi.fn(),
  updateDiscrepancyStatus: vi.fn(),
}));
vi.mock('../../../lib/scheduleDiscrepanciesApi', async (importOriginal) => ({
  ...(await importOriginal()),
  ...api,
}));

import ScheduleDiscrepancyPage from '../../../pages/ScheduleDiscrepancyPage.vue';

const row = (o = {}) => ({
  id: 7, reporter_name: '', branch_name: '', student_name: '小明', session_date: '2026-08-03',
  time_range: '10:00–12:00', corrected_time_range: '11:00–13:00', discrepancy_type_label: '時段不符',
  status: 'pending', notes: '', class_session_id: 88, ...o,
});
const mountPage = async (props = { branchId: 1 }) => {
  const w = mount(ScheduleDiscrepancyPage, { props, global: { stubs: { teleport: true } } });
  await flushPromises();
  return w;
};

describe('ScheduleDiscrepancyPage (shadcn-vue template)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchDiscrepancies.mockResolvedValue({ data: [row()] });
    api.fetchDiscrepancySummary.mockResolvedValue({ pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 });
    api.updateDiscrepancyStatus.mockResolvedValue({});
  });

  it('never calls the API without a branch and says so', async () => {
    const w = await mountPage({ branchId: null });
    expect(api.fetchDiscrepancies).not.toHaveBeenCalled();
    expect(w.text()).toContain('請先選擇要管理的分校');
  });

  it('loads the pending tab for the branch with the same parameters', async () => {
    await mountPage({ branchId: 3 });
    expect(api.fetchDiscrepancies).toHaveBeenCalledWith({ branchId: 3, status: 'pending', perPage: 50 });
    expect(api.fetchDiscrepancySummary).toHaveBeenCalledWith(3);
  });

  it('the SOP help toggle keeps an accessible name that mentions the SOP', async () => {
    const w = await mountPage();
    const summary = w.find('[data-testid="sdp-help"] > summary');
    expect(summary.attributes('aria-label')).toContain('快速處理 SOP');
    expect(summary.attributes('aria-label')).toContain(w.find('[data-testid="sdp-help"] h2').text());
  });

  it('every page-guide step target matches an element in the mounted page (a rename must not silently drop steps)', async () => {
    const { resolvePageGuideSteps } = await import('../../../lib/pageGuideConfig.js');
    const w = mount(ScheduleDiscrepancyPage, { props: { branchId: 1 }, attachTo: document.body, global: { stubs: { teleport: true } } });
    await flushPromises();
    const steps = resolvePageGuideSteps('schedule-discrepancy');
    expect(steps.length).toBeGreaterThan(0);
    for (const step of steps) {
      expect(document.querySelector(step.target), `guide step "${step.title}" target ${step.target}`).not.toBeNull();
    }
    w.unmount();
  });

  it('page title is an h2 (the app shell owns the h1) and the table keeps its fallbacks', async () => {
    const w = await mountPage();
    expect(w.find('h1').exists()).toBe(false);
    expect(w.find('h2').text()).toContain('課表回報管理');
    const cells = w.findAll('.dsc-table tbody tr:first-child td');
    expect(cells[1].text()).toBe('未提供老師姓名');
    expect(cells[2].text()).toBe('未指定分校');
    expect(w.text()).toContain('待處理');
  });

  it('shows the error state with a retry that reloads', async () => {
    api.fetchDiscrepancies.mockRejectedValueOnce(new Error('課表回報暫時無法載入'));
    const w = await mountPage();
    expect(w.find('.dsc-state-error').attributes('role')).toBe('alert');
    expect(w.find('.dsc-state-error').text()).toContain('課表回報暫時無法載入');
    await w.find('.dsc-state-error button').trigger('click');
    await flushPromises();
    expect(api.fetchDiscrepancies).toHaveBeenCalledTimes(2);
    expect(w.find('.dsc-state-error').exists()).toBe(false);
  });

  it('shows the empty state wording for the tab', async () => {
    api.fetchDiscrepancies.mockResolvedValue({ data: [] });
    const w = await mountPage();
    expect(w.find('.dsc-state-empty').text()).toContain('目前沒有待處理的回報');
  });

  it('acknowledge calls the same endpoint and drops the row from the pending list', async () => {
    const w = await mountPage();
    const ack = w.findAll('.dsc-table tbody button').find((b) => b.text() === '標記已確認');
    await ack.trigger('click');
    await flushPromises();
    expect(api.updateDiscrepancyStatus).toHaveBeenCalledWith(7, 'acknowledged');
    expect(w.findAll('.dsc-table tbody tr')).toHaveLength(0);
  });

  it('resolve needs 10 characters and sends the trimmed note', async () => {
    const w = await mountPage();
    await w.find('.dsc-table tbody button[aria-controls="sdp-detail-7"]').trigger('click');
    const submit = () => w.findAll('.dsc-detail button').find((b) => b.text() === '標記已修正');
    expect(submit().attributes('disabled')).toBeDefined();
    await w.find('#sdp-note-7').setValue('  已修正時段並確認一致  ');
    expect(submit().attributes('disabled')).toBeUndefined();
    await submit().trigger('click');
    await flushPromises();
    expect(api.updateDiscrepancyStatus).toHaveBeenCalledWith(7, 'resolved', '已修正時段並確認一致');
  });

  it('a template button fills the note', async () => {
    const w = await mountPage();
    await w.find('.dsc-table tbody button[aria-controls="sdp-detail-7"]').trigger('click');
    await w.findAll('.dsc-template-row button').find((b) => b.text() === '時段已修正').trigger('click');
    expect(w.find('#sdp-note-7').element.value).toContain('已依老師回報修正堂次時段');
  });

  it('mobile card list renders eagerly with its own form ids', async () => {
    const w = await mountPage();
    expect(w.findAll('.dsc-mcard')).toHaveLength(1);
    await w.find('.dsc-mcard button[aria-controls="sdp-mobile-detail-7"]').trigger('click');
    expect(w.find('.dsc-mcard #sdp-m-note-7').attributes('placeholder')).toBe('至少 10 字');
  });
});
