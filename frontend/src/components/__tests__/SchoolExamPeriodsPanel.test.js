import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) } },
}));
import Panel from '../SchoolExamPeriodsPanel.vue';
import { currentTerm } from '../../lib/schoolExamTerm';

afterEach(() => vi.unstubAllGlobals());

function stub() {
  const fetchMock = vi.fn(async (input, options = {}) => {
    const url = String(input);
    const body = url.includes('/schools')
      ? { data: [{ key: '甲測試國中', school_name: '甲測試國中', student_count: 3, variants: ['甲測試國中', '甲測試國民中學'] }] }
      : options.method === 'POST' ? { data: { id: 9 } } : { data: [] };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('各校段考日期 panel', () => {
  it('maps dates to ROC term', () => {
    expect(currentTerm(new Date(2026, 9, 1))).toEqual({ year: 115, semester: 1 });
    expect(currentTerm(new Date(2027, 2, 1))).toEqual({ year: 115, semester: 2 });
    expect(currentTerm(new Date(2027, 0, 5))).toEqual({ year: 115, semester: 1 });
  });

  it('director sees counts, variant hint and POSTs a new range', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    expect(w.text()).toContain('甲測試國中');
    expect(w.text()).toContain('3 人');
    expect(w.text()).toContain('可能同校');
    const inputs = w.findAll('input[type="date"]');
    await inputs[0].setValue('2026-10-20');
    await inputs[1].setValue('2026-10-22');
    await w.findAll('button').find((b) => b.text() === '儲存').trigger('click');
    await flushPromises();
    const post = f.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(JSON.parse(post[1].body)).toMatchObject({ seq: 1, school_name: '甲測試國中', start_date: '2026-10-20', end_date: '2026-10-22' });
  });

  it('teacher gets read-only inputs and no save button', async () => {
    stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: false } });
    await flushPromises();
    expect(w.find('input[type="date"]').attributes('disabled')).toBeDefined();
    expect(w.findAll('button').some((b) => b.text() === '儲存')).toBe(false);
  });
});
