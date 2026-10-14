import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BillingNotesPanel from '../BillingNotesPanel.vue';

const ok = (json, status = 200) => Promise.resolve({ ok: true, status, json: async () => json });

afterEach(() => vi.unstubAllGlobals());

describe('BillingNotesPanel (#3836 append-only billing notes)', () => {
  it('loads on open, shows the legacy note first and read-only, and appends a new note', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ok({ notes: [
        { id: null, body: '現金，收據已交給家長', author_name: null, created_at: null, legacy: true },
        { id: 7, body: '家長說下週補差額', author_name: '王主任', created_at: '2026-10-13T08:00:00+08:00', legacy: false },
      ] }))
      .mockReturnValueOnce(ok({ id: 8, body: '已補齊', author_name: '王主任', created_at: '2026-10-13T09:00:00+08:00', legacy: false }, 201));
    vi.stubGlobal('fetch', fetchMock);

    const w = mount(BillingNotesPanel, { props: { subjectType: 'payment', subjectId: 5 } });
    expect(fetchMock).not.toHaveBeenCalled();
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/accounting/notes?subject_type=payment&subject_id=5');
    const items = w.findAll('.billing-notes__list li');
    expect(items[0].text()).toContain('原備註');
    expect(items[0].text()).toContain('現金，收據已交給家長');
    expect(items[1].text()).toContain('王主任');

    await w.get('textarea').setValue('  已補齊  ');
    await w.get('form').trigger('submit');
    await flushPromises();
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toBe('/api/v1/accounting/notes');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({ subject_type: 'payment', subject_id: 5, body: '已補齊' });
    expect(w.findAll('.billing-notes__list li').at(-1).text()).toContain('已補齊');
    expect(w.get('textarea').element.value).toBe('');
  });

  it('does not submit while a load is in flight, so an older list cannot overwrite a saved note', async () => {
    let resolveLoad;
    const fetchMock = vi.fn().mockReturnValueOnce(new Promise((r) => { resolveLoad = r; }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 3 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await w.get('textarea').setValue('更正');
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeDefined();
    await w.get('form').trigger('submit');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    resolveLoad({ ok: true, status: 200, json: async () => ({ notes: [] }) });
    await flushPromises();
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeUndefined();
  });

  it('offers no edit or delete action and says notes are permanent', async () => {
    vi.stubGlobal('fetch', vi.fn().mockReturnValue(ok({ notes: [{ id: 1, body: 'x', author_name: 'A', created_at: null, legacy: false }] })));
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 1 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    const labels = w.findAll('button').map((b) => b.text());
    expect(labels.some((t) => /修改|刪除|編輯/.test(t))).toBe(false);
    expect(w.text()).toContain('不能修改或刪除');
  });

  it('drops a stale GET started during a save, so it cannot overwrite the new note (#3990)', async () => {
    const pending = [];
    const deferred = () => new Promise((r) => pending.push(r));
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ok({ notes: [{ id: 1, body: '舊備註', author_name: 'A', created_at: null, legacy: false }] }))
      .mockImplementationOnce(deferred) // POST
      .mockImplementationOnce(deferred) // GET from reopening during the POST (predates the note)
      .mockReturnValueOnce(ok({ notes: [
        { id: 1, body: '舊備註', author_name: 'A', created_at: null, legacy: false },
        { id: 2, body: '新備註', author_name: 'A', created_at: null, legacy: false },
      ] }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 9 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    await w.get('textarea').setValue('新備註');
    await w.get('form').trigger('submit');
    await w.get('.billing-notes__toggle').trigger('click');
    await w.get('.billing-notes__toggle').trigger('click');
    const [resolvePost, resolveStaleGet] = pending;
    resolvePost({ ok: true, status: 201, json: async () => ({ id: 2, body: '新備註', author_name: 'A', created_at: null, legacy: false }) });
    await flushPromises();
    resolveStaleGet({ ok: true, status: 200, json: async () => ({ notes: [{ id: 1, body: '舊備註', author_name: 'A', created_at: null, legacy: false }] }) });
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledTimes(4);
    const texts = w.findAll('.billing-notes__list li').map((li) => li.text());
    expect(texts).toHaveLength(2);
    expect(texts[1]).toContain('新備註');
  });

  it('keeps the add form disabled after a failed history load until a reload succeeds (#3990)', async () => {
    const fetchMock = vi.fn()
      .mockReturnValueOnce(Promise.resolve({ ok: false, status: 500, json: async () => ({ message: '伺服器忙碌' }) }))
      .mockReturnValueOnce(ok({ notes: [] }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 4 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    expect(w.find('[role="alert"]').exists()).toBe(true);
    await w.get('textarea').setValue('更正');
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeDefined();
    await w.get('form').trigger('submit');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await w.get('.billing-notes__reload').trigger('click');
    await flushPromises();
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeUndefined();
  });

  it('requires a successful reload after a lost POST response before the draft can be resent (#3990)', async () => {
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ok({ notes: [] }))
      .mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')))
      .mockReturnValueOnce(ok({ notes: [{ id: 5, body: '已收現金', author_name: 'A', created_at: null, legacy: false }] }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'payment', subjectId: 2 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    await w.get('textarea').setValue('已收現金');
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toContain('重新載入');
    expect(w.get('textarea').element.value).toBe('已收現金');
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeDefined();
    await w.get('form').trigger('submit');
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await w.get('.billing-notes__reload').trigger('click');
    await flushPromises();
    expect(fetchMock.mock.calls[2][0]).toContain('/api/v1/accounting/notes?');
    expect(w.findAll('.billing-notes__list li').map((li) => li.text()).join()).toContain('已收現金');
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeUndefined();
  });

  it('ignores a GET started during a save whose outcome is uncertain, and needs a fresh reload (#4040 review)', async () => {
    const pending = [];
    const deferred = () => new Promise((r) => pending.push(r));
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ok({ notes: [] }))
      .mockImplementationOnce(deferred) // POST
      .mockImplementationOnce(deferred) // GET from reopening during the POST
      .mockReturnValueOnce(ok({ notes: [{ id: 3, body: '補差額', author_name: 'A', created_at: null, legacy: false }] }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 6 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    await w.get('textarea').setValue('補差額');
    await w.get('form').trigger('submit');
    await w.get('.billing-notes__toggle').trigger('click');
    await w.get('.billing-notes__toggle').trigger('click');
    const [resolvePost, resolveEarlyGet] = pending;
    resolvePost({ ok: false, status: 502, json: async () => null });
    await flushPromises();
    resolveEarlyGet({ ok: true, status: 200, json: async () => ({ notes: [] }) });
    await flushPromises();
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeDefined();
    await w.get('.billing-notes__reload').trigger('click');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledTimes(4);
    expect(w.findAll('.billing-notes__list li').map((li) => li.text()).join()).toContain('補差額');
  });

  it('does not duplicate a note that a concurrent load already returned (#4040 review)', async () => {
    const pending = [];
    const deferred = () => new Promise((r) => pending.push(r));
    const note = { id: 4, body: '新備註', author_name: 'A', created_at: null, legacy: false };
    const fetchMock = vi.fn()
      .mockReturnValueOnce(ok({ notes: [] }))
      .mockImplementationOnce(deferred) // POST
      .mockReturnValueOnce(ok({ notes: [note] })); // GET from reopening, after the insert committed
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 7 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    await w.get('textarea').setValue('新備註');
    await w.get('form').trigger('submit');
    await w.get('.billing-notes__toggle').trigger('click');
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    pending[0]({ ok: true, status: 201, json: async () => note });
    await flushPromises();
    expect(w.findAll('.billing-notes__list li')).toHaveLength(1);
  });

  it('treats an unreadable history body as a failed load (#4040 review)', async () => {
    const fetchMock = vi.fn()
      .mockReturnValueOnce(Promise.resolve({ ok: true, status: 200, json: async () => { throw new SyntaxError('bad'); } }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 8 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    expect(w.find('[role="alert"]').exists()).toBe(true);
    expect(w.find('.billing-notes__reload').exists()).toBe(true);
    await w.get('textarea').setValue('更正');
    expect(w.get('form button[type="submit"]').attributes('disabled')).toBeDefined();
  });

  it('hides the add form and shows the label when the notes are read-only (#4055)', async () => {
    const fetchMock = vi.fn().mockReturnValueOnce(ok({
      notes: [{ id: 4, body: '已退費', author_name: 'A', created_at: null, legacy: false }],
      read_only: true,
      read_only_label: '（帳單已作廢）',
    }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 12 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    expect(w.text()).toContain('已退費');
    expect(w.get('.billing-notes__readonly').text()).toContain('（帳單已作廢）');
    expect(w.find('form').exists()).toBe(false);
    expect(w.find('textarea').exists()).toBe(false);
    // The "add another note to correct it" hint would be impossible advice here (#4062 review).
    expect(w.text()).not.toContain('再新增一則更正');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('keeps the add form for a response without read_only (#4055)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(ok({ notes: [] })));
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 13 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    expect(w.find('.billing-notes__readonly').exists()).toBe(false);
    expect(w.find('form').exists()).toBe(true);
  });
});
