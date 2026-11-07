import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TelegramBindingReview from '../TelegramBindingReview.vue';

vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) } } }));
afterEach(() => vi.unstubAllGlobals());

describe('Telegram 綁定確認', () => {
  it('lists rows, falls back to 無法取得, and unbinds only after confirm', async () => {
    const list = { data: [{ student_id: 5, student_name: '合成學生', bindings: [{ slot: 0, fp: 'aaaaaaaaaaaaaaaa', telegram_name: null }, { slot: 2, fp: 'bbbbbbbbbbbbbbbb', telegram_name: 'Parent X' }] }] };
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => list }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(TelegramBindingReview, { props: { branchId: 1 }, global: { stubs: { Teleport: true } } });
    await flushPromises();
    expect(w.text()).toContain('無法取得');
    expect(w.text()).toContain('Parent X');
    await w.findAll('[data-testid="tg-unbind"]')[1].trigger('click');
    expect(fetchMock.mock.calls.filter(([, o]) => o.method === 'POST')).toHaveLength(0);
    await w.find('[data-testid="tg-confirm"]').trigger('click');
    await flushPromises();
    const post = fetchMock.mock.calls.find(([, o]) => o.method === 'POST');
    expect(post[0]).toContain('/unbind');
    expect(JSON.parse(post[1].body)).toEqual({ student_id: 5, slot: 2, fp: 'bbbbbbbbbbbbbbbb', branch_id: 1 });
  });
});
