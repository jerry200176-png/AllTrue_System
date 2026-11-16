// 帳務中心重整 G2: the scoreboard prints the server's counts per campus and the totals; it computes nothing itself.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BillingDataQualityCard from '../BillingDataQualityCard.vue';

afterEach(() => vi.unstubAllGlobals());

const REPORT = {
  as_of: '2026-11-17T09:00:00+08:00',
  rules: [{ key: 'zero_paid_bill', label: '0 元卻標成已繳的帳單' }, { key: 'unpaid_over_60_days', label: '開立超過 60 天還沒收齊的帳單' }],
  campuses: [
    { campus_id: 1, campus_name: '甲分校', counts: { zero_paid_bill: 3, unpaid_over_60_days: 0 } },
    { campus_id: 2, campus_name: '', counts: { zero_paid_bill: 0, unpaid_over_60_days: 5 } },
  ],
  totals: { zero_paid_bill: 3, unpaid_over_60_days: 5 },
};

describe('帳務資料品質', () => {
  it('shows each campus row, a dash for none, and the totals', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: REPORT }) }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(BillingDataQualityCard, { props: { token: 't' } });
    await flushPromises();
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/admin/billing-data-quality');
    const cells = (id) => w.get(`[data-testid="${id}"]`).findAll('td').map((td) => td.text());
    expect(cells('bdq-campus-1')).toEqual(['甲分校', '3', '—']);
    expect(cells('bdq-campus-2')).toEqual(['分校 2', '—', '5']);
    expect(cells('bdq-total')).toEqual(['全部', '3', '5']);
    expect(w.findAll('th').map((th) => th.text())).toEqual(['分校', '0 元卻標成已繳的帳單', '開立超過 60 天還沒收齊的帳單']);
  });

  it('says so when the request fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) })));
    const w = mount(BillingDataQualityCard, { props: { token: 't' } });
    await flushPromises();
    expect(w.get('[data-testid="bdq-error"]').text()).toBe('Forbidden');
  });
});
