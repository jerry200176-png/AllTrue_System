// 帳務中心重整 G3: the AI activity card prints the server's counts and operations; it computes nothing but the top-8 cut.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiActivityCard from '../AiActivityCard.vue';

afterEach(() => vi.unstubAllGlobals());

const REPORT = {
  days: 30,
  reads: { total: 3, refused: 1, by_source: [{ name: 'Invoice', count: 2 }, { name: 'Payment', count: 1 }], by_day: [{ date: '2026-11-17', count: 3 }] },
  operations: [{ operation: 'billing.period_rekey', strategy: 'billing_period_rekey', status: 'executed', actor: 'github-actions', created_at: '2026-11-17 09:00:00', approval_events: 1 }],
};

describe('AI 做了什麼', () => {
  it('shows read counts, tables and governed operations', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: REPORT }) }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(AiActivityCard, { props: { token: 't' } });
    await flushPromises();
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/admin/ai-activity');
    expect(w.get('[data-testid="aia-reads"]').text()).toBe('讀資料 3 次，其中被擋下 1 次。');
    expect(w.get('[data-testid="aia-sources"]').findAll('li').map((li) => li.text())).toEqual(['Invoice：2 次', 'Payment：1 次']);
    const ops = w.findAll('[data-testid="aia-op"]');
    expect(ops).toHaveLength(1);
    expect(ops[0].findAll('td').map((td) => td.text())).toEqual(['2026-11-17 09:00', 'billing.period_rekey', 'executed', '1']);
  });

  it('says there were none, and shows a failed request', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: { ...REPORT, operations: [] } }) })));
    const w = mount(AiActivityCard, { props: { token: 't' } });
    await flushPromises();
    expect(w.get('[data-testid="aia-no-ops"]').text()).toBe('沒有。');

    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) })));
    const w2 = mount(AiActivityCard, { props: { token: 't' } });
    await flushPromises();
    expect(w2.get('[data-testid="aia-error"]').text()).toBe('Forbidden');
  });
});
