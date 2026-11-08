import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import LearningRecordsPage from '../../pages/LearningRecordsPage.vue';
import { supabase } from '../../supabase';

vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'fixture-token' } } })) } } }));

const record = (id = 501, Status = 'approved') => ({
  id, Status, student_id: 1, StudentClassID: 10, TeacherID: 9001,
  student_name: '測試學生', Subject: '數學', SessionDate: '2026-11-01',
  StartTime: '10:00', EndTime: '11:00', Progress: '已儲存進度', Comment: '已儲存評語',
});
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
let wrapper, fetchMock, rollbackResponse;
const buttons = (root, text) => root.findAll('button').filter(b => b.text() === text);
const click = async (root, text) => { const b = buttons(root, text)[0]; expect(b, text).toBeTruthy(); await b.trigger('click'); await flushPromises(); };
const modal = () => wrapper.find('.lr-modal--learning-form');
const rollbackCalls = () => fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/rollback-approval'));

async function start({ width = 1440, role = 'director', status = 'approved' } = {}) {
  window.innerWidth = width;
  wrapper = mount(LearningRecordsPage, { props: { branchId: 1, userRole: role, userId: 9001 }, attachTo: document.body });
  await flushPromises();
  // Exercise the real queue controls, rather than injecting page state.
  await wrapper.find(`#lr-${role === 'teacher' ? 'teacher' : 'review'}-tab-${status}`).trigger('click');
  await flushPromises();
}
async function edit() {
  await click(wrapper, '編輯評量');
  expect(modal().exists()).toBe(true);
  await modal().find('textarea[placeholder="紀錄本次上課內容..."]').setValue('尚未儲存進度');
  await modal().find('textarea[placeholder="綜合評語與聯絡事項..."]').setValue('尚未儲存評語');
}
function expectDraft() {
  expect(modal().find('textarea[placeholder="紀錄本次上課內容..."]').element.value).toBe('尚未儲存進度');
  expect(modal().find('textarea[placeholder="綜合評語與聯絡事項..."]').element.value).toBe('尚未儲存評語');
}

beforeEach(() => {
  localStorage.clear();
  vi.spyOn(window, 'prompt').mockReturnValue('重新確認');
  vi.spyOn(window, 'alert').mockImplementation(() => {});
  rollbackResponse = () => Promise.resolve(json({ ...record(), Status: 'pending', ReviewNote: '重新確認', ApprovedBy: null, ApprovedAt: null }));
  fetchMock = vi.fn(async (url) => {
    if (String(url).endsWith('/rollback-approval')) return rollbackResponse();
    if (String(url).includes('/learning-records?')) return json({ data: [record(), record(502, 'pending')], status_counts: { approved: 1, pending: 1 } });
    return json({ data: [], created: 0 });
  });
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe.each([1440, 390])('approval rollback through real page @%i', width => {
  it('offers the director operation in the list/card and editor; success keeps dirty inputs', async () => {
    await start({ width });
    expect(buttons(wrapper, '退回待審')).toHaveLength(1);
    await edit();
    await click(modal(), '退回待審');
    expect(rollbackCalls()).toHaveLength(1);
    expect(JSON.parse(rollbackCalls()[0][1].body)).toEqual({ ReviewNote: '重新確認' });
    expect(modal().text()).not.toContain('✓ 已核准');
    expect(buttons(modal(), '退回待審')).toHaveLength(0);
    expectDraft();
  });
  it('supports the list/card entry without opening an editor', async () => {
    await start({ width });
    await click(wrapper, '退回待審');
    expect(rollbackCalls()).toHaveLength(1);
    expect(modal().exists()).toBe(false);
    expect(buttons(wrapper, '退回待審')).toHaveLength(0);
  });
  it('cancel sends nothing and preserves dirty inputs and approved status', async () => {
    await start({ width }); await edit();
    window.prompt.mockReturnValue(null);
    await click(modal(), '退回待審');
    expect(rollbackCalls()).toHaveLength(0);
    expect(modal().text()).toContain('✓ 已核准'); expectDraft();
  });
  it.each(['http', 'network'])('%s failure preserves inputs and status and allows retry', async kind => {
    await start({ width }); await edit();
    rollbackResponse = () => kind === 'http' ? Promise.resolve(json({ message: '拒絕此操作' }, 403)) : Promise.reject(new Error('offline'));
    await click(modal(), '退回待審');
    expect(window.alert).toHaveBeenCalled();
    expect(modal().text()).toContain('✓ 已核准'); expectDraft();
    expect(buttons(modal(), '退回待審')[0].element.disabled).toBe(false);
  });
  it('deduplicates across editor and list/card and blocks concurrent save', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    const rollback = buttons(modal(), '退回待審')[0];
    await rollback.trigger('click'); await flushPromises();
    expect(rollback.element.disabled).toBe(true);
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(true);
    for (const b of buttons(wrapper, '處理中…')) await b.trigger('click');
    expect(rollbackCalls()).toHaveLength(1);
    pending.resolve(json({ ...record(), Status: 'pending' })); await flushPromises(); expectDraft();
  });
  it('an old completion cannot change a reopened editor for the same record', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審'); await click(modal(), '關閉'); await edit();
    pending.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    expect(modal().text()).toContain('✓ 已核准'); expectDraft();
  });
  it('an old failure cannot alert in a new campus context', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審');
    await wrapper.setProps({ branchId: 2 }); await flushPromises();
    pending.resolve(json({ message: 'old failure' }, 409)); await flushPromises();
    expect(window.alert).not.toHaveBeenCalled(); expect(modal().exists()).toBe(false);
  });
  it('does not send a request after token lookup outlives the editor', async () => {
    await start({ width }); await edit();
    const token = deferred();
    supabase.auth.getSession.mockReturnValueOnce(token.promise);
    await click(modal(), '退回待審'); await click(modal(), '關閉');
    token.resolve({ data: { session: { access_token: 'fixture-token' } } }); await flushPromises();
    expect(rollbackCalls()).toHaveLength(0);
  });
  it('does not patch a different record opened before success', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審'); await click(modal(), '關閉');
    await wrapper.find('#lr-review-tab-pending').trigger('click'); await flushPromises(); await edit();
    pending.resolve(json({ ...record(), Status: 'pending', ReviewNote: 'old note' })); await flushPromises();
    expectDraft();
    // The new pending record remains editable and has no old approval/review state.
    expect(modal().text()).not.toContain('old note');
    expect(modal().text()).not.toContain('✓ 已核准');
  });
  it.each([409, 'malformed', 'wrong-id'])('rejects %s without changing the editor', async kind => {
    await start({ width }); await edit();
    rollbackResponse = () => Promise.resolve(kind === 409
      ? json({ message: '狀態已變更' }, 409)
      : json(kind === 'malformed' ? {} : { ...record(999), Status: 'pending' }));
    await click(modal(), '退回待審');
    expect(window.alert).toHaveBeenCalled(); expectDraft();
    expect(modal().text()).toContain('✓ 已核准');
  });
  it('hides rollback for pending records', async () => {
    await start({ width, status: 'pending' });
    expect(buttons(wrapper, '退回待審')).toHaveLength(0);
    await edit(); expect(buttons(modal(), '退回待審')).toHaveLength(0);
  });
  it('hides rollback from teachers for approved and pending records', async () => {
    await start({ width, role: 'teacher' });
    expect(buttons(wrapper, '退回待審')).toHaveLength(0);
    await click(wrapper, '檢視評量'); expect(buttons(modal(), '退回待審')).toHaveLength(0);
    await click(modal(), '關閉'); await wrapper.find('#lr-teacher-tab-pending').trigger('click');
    expect(buttons(wrapper, '退回待審')).toHaveLength(0);
    await edit(); expect(buttons(modal(), '退回待審')).toHaveLength(0);
  });
});
