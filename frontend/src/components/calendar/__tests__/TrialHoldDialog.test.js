// 試聽暫留 (in-app #575): the hold form sends the slot (not a student) and shows the server's refusal as-is.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TrialHoldDialog from '../TrialHoldDialog.vue';

const q = (sel) => document.querySelector(sel);
const res = (status, body) => ({ ok: status < 400, status, json: async () => body });
let wrappers = [];
let calls;

function stubFetch(response) {
  calls = [];
  vi.stubGlobal('fetch', vi.fn(async (url, opts = {}) => {
    calls.push({ url: String(url), method: opts.method, body: opts.body ? JSON.parse(opts.body) : null });
    return response;
  }));
}

async function openDialog(prefill = { teacher_id: 5, hold_date: '2026-11-21', start_time: '16:30' }) {
  const w = mount(TrialHoldDialog, {
    props: { open: false, token: 't', campusId: 9, teachers: [{ id: 5, username: '王老師' }, { id: 6, username: '李老師' }], prefill },
    attachTo: document.body,
  });
  wrappers.push(w);
  await w.setProps({ open: true });
  await flushPromises();
  return w;
}
const submit = async () => { q('[data-testid="trial-hold-form"]').closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); await flushPromises(); };

afterEach(() => { wrappers.forEach((w) => w.unmount()); wrappers = []; vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('TrialHoldDialog', () => {
  it('prefills the slot, defaults to one hour, and posts a hold without any student data', async () => {
    stubFetch(res(201, { data: { id: 1, status: 'held' } }));
    const w = await openDialog();
    expect(q('#hold-teacher').value).toBe('5');
    expect(q('#hold-end').value).toBe('17:30');
    await submit();
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toMatch(/\/v1\/trial-holds$/);
    expect(calls[0].body).toEqual({ campus_id: 9, teacher_id: 5, hold_date: '2026-11-21', start_time: '16:30', end_time: '17:30', note: null });
    expect(w.emitted('created')).toHaveLength(1);
    expect(w.emitted('update:open').at(-1)).toEqual([false]);
  });

  it('shows the server refusal and stays open', async () => {
    stubFetch(res(422, { message: '這個時段老師已經有課或已有試聽，不能暫留。沒有變更任何資料。', code: 'trial_hold_conflict' }));
    const w = await openDialog();
    await submit();
    expect(q('[data-testid="trial-hold-error"]').textContent).toContain('不能暫留');
    expect(w.emitted('created')).toBeUndefined();
  });

  it('refuses an end time before the start without calling the server', async () => {
    stubFetch(res(201, {}));
    await openDialog({ teacher_id: 5, hold_date: '2026-11-21', start_time: '23:30' });
    const end = q('#hold-end');
    end.value = '10:00';
    end.dispatchEvent(new Event('input', { bubbles: true }));
    await submit();
    expect(calls).toHaveLength(0);
    expect(q('[data-testid="trial-hold-error"]').textContent).toContain('結束時間');
  });
});
