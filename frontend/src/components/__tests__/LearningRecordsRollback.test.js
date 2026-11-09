import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import LearningRecordsPage from '../../pages/LearningRecordsPage.vue';
import AtToast from '../AtToast.vue';
import { useToast } from '../../composables/useToast';
import { supabase } from '../../supabase';

vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'fixture-token' } } })) } } }));

const record = (id = 501, Status = 'approved') => ({
  id, Status, student_id: 1, StudentClassID: 10, ClassSessionID: id + 1000, TeacherID: 9001,
  student_name: '測試學生', Subject: '數學', SessionDate: '2026-11-01',
  StartTime: '10:00', EndTime: '11:00', Progress: '已儲存進度', Comment: '已儲存評語',
});
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const recordsJson = (body, status = 200) => json({ current_page: 1, last_page: 1, total: body.data?.length ?? 0, ...body }, status);
let wrapper, toastWrapper, fetchMock, rollbackResponse, serverRecords, readResponse, pageSize, hiddenPending;
const buttons = (root, text) => root.findAll('button').filter(b => b.text() === text);
const click = async (root, text) => { const b = buttons(root, text)[0]; expect(b, text).toBeTruthy(); await b.trigger('click'); await flushPromises(); };
const modal = () => wrapper.find('.lr-modal--learning-form');
const rollbackCalls = () => fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/rollback-approval'));
const listResponse = url => {
  const params = new URL(String(url), 'http://local').searchParams;
  const counts = { approved: serverRecords.filter(r => r.Status === 'approved').length, pending: serverRecords.filter(r => r.Status === 'pending' && !hiddenPending.has(r.id)).length };
  let rows = serverRecords;
  if (params.has('for_conflict_lookup')) return json({ data: rows.filter(r => r.ClassSessionID === Number(params.get('class_session_id'))) });
  rows = rows.filter(r => r.Status !== 'pending' || !hiddenPending.has(r.id));
  if (params.has('before_id')) return json({ data: rows.filter(r => r.id < Number(params.get('before_id'))).sort((a,b) => b.id-a.id).slice(0,1) });
  if (params.has('status')) rows = rows.filter(r => r.Status === params.get('status'));
  const total = rows.length, page = Number(params.get('page') || 1);
  return json({ data: rows.slice((page - 1) * pageSize, page * pageSize), current_page: page, last_page: Math.max(1, Math.ceil(total / pageSize)), total, status_counts: counts });
};

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
  toastWrapper = mount(AtToast, { attachTo: document.body });
  vi.spyOn(window, 'prompt').mockReturnValue('重新確認');
  vi.spyOn(window, 'alert').mockImplementation(() => {});
  serverRecords = [record(), record(502, 'pending')];
  pageSize = 50; hiddenPending = new Set();
  readResponse = null;
  rollbackResponse = () => Promise.resolve(json({ ...record(), Status: 'pending', ReviewNote: '重新確認', ApprovedBy: null, ApprovedAt: null }));
  fetchMock = vi.fn(async (url) => {
    if (String(url).endsWith('/rollback-approval')) {
      const response = await rollbackResponse(url);
      const body = await response.clone().json().catch(() => null);
      if (response.ok && body?.Status === 'pending') serverRecords = serverRecords.map(r => r.id === body.id ? { ...r, ...body } : r);
      return response;
    }
    if (String(url).includes('/learning-records?')) {
      if (readResponse) return readResponse(url);
      return listResponse(url);
    }
    return json({ data: [], created: 0 });
  });
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
  wrapper?.unmount(); wrapper = null;
  const toast = useToast();
  [...toast.toasts].forEach(item => toast.dismiss(item.id));
  toastWrapper?.unmount();
  vi.restoreAllMocks(); vi.unstubAllGlobals(); document.body.innerHTML = '';
});

describe.each([1440, 390])('approval rollback through real page @%i', width => {
  it.each([false, true])('recovers a pending record intentionally hidden by a leave session (lost response=%s)', async lost => {
    hiddenPending.add(501);
    await start({ width }); await edit();
    if (lost) rollbackResponse = () => { serverRecords[0] = { ...serverRecords[0], Status: 'pending' }; return Promise.reject(new Error('response lost')); };
    await click(modal(), '退回待審');
    if (lost) await click(modal(), '重新查詢狀態');
    expectDraft();
    expect(modal().text()).not.toContain('✓ 已核准');
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(false);
    expect(wrapper.text()).not.toContain('狀態重新整理未完成');
  });
  it('reloads the filtered first page so removing one result does not skip an unseen record', async () => {
    serverRecords = [record(), record(502), record(503)]; pageSize = 2;
    await start({ width });
    expect(buttons(wrapper, '退回待審')).toHaveLength(2);
    await click(wrapper, '退回待審');
    expect(buttons(wrapper, '退回待審')).toHaveLength(2);
    expect(buttons(wrapper, '載入下一頁')).toHaveLength(0);
  });
  it('restarts the current tab query after rollback supersedes its in-flight read', async () => {
    serverRecords = [record(), record(502, 'pending'), record(503, 'pending')];
    await start({ width });
    const mutation = deferred(), old = deferred(); rollbackResponse = () => mutation.promise;
    await click(wrapper, '退回待審');
    let delayed = false;
    readResponse = url => {
      const params = new URL(String(url), 'http://local').searchParams;
      if (!delayed && params.get('status') === 'pending') { delayed = true; return old.promise; }
      return Promise.resolve(listResponse(url));
    };
    await wrapper.find('#lr-review-tab-pending').trigger('click'); await flushPromises();
    mutation.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    expect(buttons(wrapper, '編輯評量')).toHaveLength(3);
    old.resolve(recordsJson({ data: [record(502, 'pending'), record(503, 'pending')] })); await flushPromises();
    expect(buttons(wrapper, '編輯評量')).toHaveLength(3);
  });
  it.each([{}, { data: [], current_page: 'bad', last_page: 1, total: 0 }])('keeps writes blocked when a 200 queue refresh has invalid shape %j', async invalid => {
    await start({ width }); await edit();
    readResponse = url => {
      const params = new URL(String(url), 'http://local').searchParams;
      return Promise.resolve(params.has('with_status_counts') || params.has('for_conflict_lookup') ? listResponse(url) : json(invalid));
    };
    await click(modal(), '退回待審'); expectDraft();
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(true);
    await click(modal(), '重新查詢狀態');
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(true);
    readResponse = null;
    await click(modal(), '重新查詢狀態');
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(false);
  });
  it.each(['next', 'all'])('discards an old %s page after rollback resets the offset window', async mode => {
    serverRecords = Array.from({ length: 6 }, (_, i) => record(501 + i)); pageSize = 2;
    await start({ width });
    const old = deferred();
    readResponse = url => {
      const params = new URL(String(url), 'http://local').searchParams;
      return params.get('page') === '2' || params.has('before_id') ? old.promise : Promise.resolve(listResponse(url));
    };
    const more = () => wrapper.findAll('button').find(b => b.text().includes('載入下一頁'));
    const control = mode === 'all' ? wrapper.findAll('button').find(b => b.text().includes('載入全部記錄')) : more();
    await control.trigger('click'); await flushPromises();
    readResponse = null;
    await click(wrapper, '退回待審');
    old.resolve(recordsJson({ data: [record(503), record(504)], current_page: 2, last_page: 3, total: 6, pagination: mode === 'all' ? 'keyset' : undefined })); await flushPromises();
    expect(buttons(wrapper, '退回待審')).toHaveLength(2);
    await more().trigger('click'); await flushPromises();
    expect(buttons(wrapper, '退回待審')).toHaveLength(4);
  });
  it('retires load-all on an ordinary refresh and lets the next page load', async () => {
    serverRecords = Array.from({ length: 6 }, (_, i) => record(501 + i)); pageSize = 2;
    await start({ width });
    const old = deferred();
    readResponse = url => new URL(String(url), 'http://local').searchParams.has('before_id') ? old.promise : Promise.resolve(listResponse(url));
    await wrapper.findAll('button').find(b => b.text().includes('載入全部記錄')).trigger('click'); await flushPromises();
    await wrapper.find('#lr-review-tab-pending').trigger('click'); await flushPromises();
    await wrapper.find('#lr-review-tab-approved').trigger('click'); await flushPromises();
    old.resolve(recordsJson({ data: [record(499)], pagination: 'keyset' })); await flushPromises();
    const more = wrapper.findAll('button').find(b => b.text().includes('載入下一頁'));
    expect(more.element.disabled).toBe(false);
    readResponse = null;
    await more.trigger('click'); await flushPromises();
    expect(buttons(wrapper, '退回待審')).toHaveLength(4);
  });
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
  it('a definite 403 refusal preserves inputs and allows retry', async () => {
    await start({ width }); await edit();
    rollbackResponse = () => Promise.resolve(json({ message: '拒絕此操作' }, 403));
    await click(modal(), '退回待審');
    expect(toastWrapper.text()).toContain('退回待審未執行');
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
  it('success after reopening reconciles status without replacing dirty text', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審'); await click(modal(), '關閉'); await edit();
    pending.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    expect(modal().text()).not.toContain('✓ 已核准'); expectDraft();
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(wrapper.find('#lr-review-tab-pending').text()).toContain('2');
  });
  it('an old failure cannot notify in a new campus context', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審');
    await wrapper.setProps({ branchId: 2 }); await flushPromises();
    pending.resolve(json({ message: 'old failure' }, 409)); await flushPromises();
    expect(toastWrapper.text()).toBe(''); expect(modal().exists()).toBe(false);
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
  it('a definite 409 refusal does not overwrite the editor', async () => {
    await start({ width }); await edit();
    rollbackResponse = () => Promise.resolve(json({ message: '狀態已變更' }, 409));
    await click(modal(), '退回待審');
    expect(toastWrapper.text()).toContain('退回待審未執行'); expectDraft();
    expect(modal().text()).toContain('✓ 已核准');
  });
  it.each(['network', 'invalid-json', 'malformed', 'wrong-id'])('%s after server completion requires a read before retry', async kind => {
    await start({ width }); await edit();
    rollbackResponse = () => {
      serverRecords[0] = { ...serverRecords[0], Status: 'pending' };
      if (kind === 'network') return Promise.reject(new Error('response lost'));
      if (kind === 'invalid-json') return Promise.resolve(new Response('broken', { status: 200 }));
      return Promise.resolve(json(kind === 'malformed' ? {} : { ...record(999), Status: 'pending' }));
    };
    await click(modal(), '退回待審'); expectDraft();
    expect(toastWrapper.text()).toBe('');
    expect(wrapper.text()).toContain('無法確認退回結果');
    expect(buttons(modal(), '退回待審')[0].element.disabled).toBe(true);
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(true);
    await buttons(modal(), '退回待審')[0].trigger('click'); expect(rollbackCalls()).toHaveLength(1);
    await click(modal(), '重新查詢狀態');
    expect(modal().text()).not.toContain('✓ 已核准'); expectDraft();
    expect(buttons(modal(), '退回待審')).toHaveLength(0);
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(wrapper.find('#lr-review-tab-pending').text()).toContain('2');
  });
  it('failed reconciliation stays locked across close/reopen, then a confirmed approved read permits retry', async () => {
    await start({ width }); await edit();
    rollbackResponse = () => Promise.reject(new Error('connection lost'));
    await click(modal(), '退回待審');
    readResponse = () => Promise.resolve(json({}, 503));
    await click(modal(), '重新查詢狀態'); expectDraft();
    expect(buttons(modal(), '退回待審')[0].element.disabled).toBe(true);
    await click(modal(), '關閉'); await edit();
    expect(buttons(modal(), '退回待審')[0].element.disabled).toBe(true);
    readResponse = null;
    await click(modal(), '重新查詢狀態'); expectDraft();
    expect(buttons(modal(), '退回待審')[0].element.disabled).toBe(false);
    rollbackResponse = () => Promise.resolve(json({ ...record(), Status: 'pending' }));
    await click(modal(), '退回待審'); expect(rollbackCalls()).toHaveLength(2);
  });
  it('success after closing still updates the list and counts', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審'); await click(modal(), '關閉');
    pending.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    expect(buttons(wrapper, '退回待審')).toHaveLength(0);
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(wrapper.find('#lr-review-tab-pending').text()).toContain('2');
  });
  it.each([[false, 200], [false, 503], [true, 200], [true, 503]])('releases obsolete list loading after rollback (recheck=%s, old response=%i)', async (viaRecheck, responseStatus) => {
    await start({ width }); await edit();
    if (viaRecheck) {
      rollbackResponse = () => {
        serverRecords[0] = { ...serverRecords[0], Status: 'pending' };
        return Promise.reject(new Error('response lost'));
      };
      await click(modal(), '退回待審');
    }
    const refresh = () => wrapper.findAll('button').find(b => b.text().endsWith('重新整理'));
    const old = deferred();
    readResponse = () => old.promise;
    await refresh().trigger('click'); await flushPromises();
    expect(refresh().element.disabled).toBe(true);
    readResponse = null;
    await click(modal(), viaRecheck ? '重新查詢狀態' : '退回待審'); expectDraft();
    expect(refresh().element.disabled).toBe(false);
    const next = deferred();
    readResponse = () => next.promise;
    await refresh().trigger('click'); await flushPromises();
    expect(refresh().element.disabled).toBe(true);
    old.resolve(recordsJson({ data: [record()], status_counts: { approved: 1, pending: 1 } }, responseStatus)); await flushPromises();
    expect(refresh().element.disabled).toBe(true); // An old completion cannot clear a newer request's loading state.
    next.resolve(recordsJson({ data: serverRecords, status_counts: { approved: 0, pending: 2 } })); await flushPromises();
    expect(refresh().element.disabled).toBe(false);
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(wrapper.find('#lr-review-tab-pending').text()).toContain('2');
    expect(modal().text()).not.toContain('✓ 已核准');
  });
  it('a reconciliation response cannot overwrite another modal opened during the read', async () => {
    await start({ width }); await edit();
    rollbackResponse = () => { serverRecords[0] = { ...serverRecords[0], Status: 'pending' }; return Promise.reject(new Error('response lost')); };
    await click(modal(), '退回待審');
    const read = deferred();
    readResponse = url => String(url).includes('for_conflict_lookup=') ? read.promise : Promise.resolve(recordsJson({ data: serverRecords, status_counts: { approved: 0, pending: 2 } }));
    await click(modal(), '重新查詢狀態'); await click(modal(), '關閉');
    await wrapper.find('#lr-review-tab-pending').trigger('click'); await flushPromises(); await edit();
    read.resolve(recordsJson({ data: [{ ...record(), Status: 'pending', ReviewNote: 'old review note' }] })); await flushPromises();
    expectDraft(); expect(modal().text()).not.toContain('old review note');
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
  });
  it('success for the previous campus refreshes current scope without touching its modal', async () => {
    await start({ width }); await edit();
    const pending = deferred(); rollbackResponse = () => pending.promise;
    await click(modal(), '退回待審');
    serverRecords = [record(777)];
    await wrapper.setProps({ branchId: 2 }); await flushPromises(); await edit();
    pending.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    expectDraft(); expect(modal().text()).toContain('✓ 已核准');
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('1');
  });
  it('two successful rows finishing in parallel cannot restore older counts', async () => {
    serverRecords = [record(), record(502)];
    await start({ width });
    const a = deferred(), b = deferred(), oldCount = deferred();
    rollbackResponse = url => String(url).includes('/501/') ? a.promise : b.promise;
    const controls = buttons(wrapper, '退回待審');
    await controls[0].trigger('click'); await controls[1].trigger('click'); await flushPromises();
    let counts = 0;
    readResponse = url => {
      const params = new URL(String(url), 'http://local').searchParams;
      if (params.has('with_status_counts') && ++counts === 1) return oldCount.promise;
      const rows = params.has('for_conflict_lookup') ? serverRecords.filter(r => r.ClassSessionID === Number(params.get('class_session_id'))) : serverRecords;
      return Promise.resolve(recordsJson({ data: rows, status_counts: { approved: serverRecords.filter(r => r.Status === 'approved').length, pending: serverRecords.filter(r => r.Status === 'pending').length } }));
    };
    a.resolve(json({ ...record(), Status: 'pending' })); await flushPromises();
    b.resolve(json({ ...record(502), Status: 'pending' })); await flushPromises();
    oldCount.resolve(recordsJson({ data: [], status_counts: { approved: 1, pending: 1 } })); await flushPromises();
    expect(rollbackCalls()).toHaveLength(2);
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(wrapper.find('#lr-review-tab-pending').text()).toContain('2');
    expect(modal().exists()).toBe(false);
  });
  it('confirmed success with failed refresh keeps the known state and blocks writes until read recovery', async () => {
    await start({ width }); await edit();
    readResponse = () => Promise.resolve(json({}, 503));
    await click(modal(), '退回待審'); expectDraft();
    expect(modal().text()).not.toContain('✓ 已核准');
    expect(modal().text()).toContain('已退回待審；狀態重新整理未完成');
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(true);
    readResponse = null;
    await click(modal(), '重新查詢狀態'); expectDraft();
    expect(buttons(modal(), '儲存變更')[0].element.disabled).toBe(false);
    expect(wrapper.find('#lr-review-tab-approved').text()).toContain('0');
    expect(rollbackCalls()).toHaveLength(1);
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
