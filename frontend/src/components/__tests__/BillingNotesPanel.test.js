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

  it('offers no edit or delete action and says notes are permanent', async () => {
    vi.stubGlobal('fetch', vi.fn().mockReturnValue(ok({ notes: [{ id: 1, body: 'x', author_name: 'A', created_at: null, legacy: false }] })));
    const w = mount(BillingNotesPanel, { props: { subjectType: 'invoice', subjectId: 1 } });
    await w.get('.billing-notes__toggle').trigger('click');
    await flushPromises();
    const labels = w.findAll('button').map((b) => b.text());
    expect(labels.some((t) => /修改|刪除|編輯/.test(t))).toBe(false);
    expect(w.text()).toContain('不能修改或刪除');
  });
});
