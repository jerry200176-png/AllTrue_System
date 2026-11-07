import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) } },
}));
import Panel from '../SchoolMergePanel.vue';

afterEach(() => vi.unstubAllGlobals());

function stub() {
  const f = vi.fn(async (input, options = {}) => {
    const url = String(input);
    let data = [];
    if (url.includes('suggestions')) data = [{ names: [{ name: '再興國中', count: 3 }, { name: '在興國中', count: 2 }], student_count: 5, reason: 'similar' }];
    else if (url.includes('history')) data = [{ request_id: 'r-1', occurred_at: '2026-11-07 10:00:00', canonical_name: '再興國中', student_count: 2 }];
    else if (options.method === 'POST') data = { students_updated: 2, students_restored: 2, request_id: 'r-2' };
    return { ok: true, json: async () => ({ data }) };
  });
  vi.stubGlobal('fetch', f);
  return f;
}
const btn = (w, t) => w.findAll('button').find((b) => b.text() === t);

describe('學校整理 panel', () => {
  it('previews the count, needs a confirm click, then POSTs the merge', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1 } });
    await flushPromises();
    expect(w.text()).toContain('拼寫相近');
    expect(w.text()).toContain('會改 2 位學生');
    await w.findAll('input[type="radio"]')[1].setValue();
    expect(w.text()).toContain('會改 3 位學生');
    await btn(w, '合併').trigger('click');
    expect(f.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
    await btn(w, '確定合併').trigger('click');
    await flushPromises();
    const post = f.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(JSON.parse(post[1].body)).toEqual({ campus_id: 1, canonical: '在興國中', variants: ['再興國中', '在興國中'] });
  });

  it('shows 180-day history and undoes by request id', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1 } });
    await flushPromises();
    expect(w.text()).toContain('180 天內可復原');
    await btn(w, '復原').trigger('click');
    await flushPromises();
    expect(f.mock.calls.some(([u, o]) => String(u).endsWith('/school-merge/r-1/undo') && o?.method === 'POST')).toBe(true);
  });
});
