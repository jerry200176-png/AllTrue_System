// Step 2 PR 3: HQ 更正紀錄 page (super_admin, read only).
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BillCorrectionLogPage from '../../pages/BillCorrectionLogPage.vue';

const res = (status, body) => ({ ok: status < 400, status, json: async () => body });
const ROW = { id: 2, created_at: '2026-11-12 09:00:00', campus_name: '合成分校', original_invoice_id: 101, replacement_invoice_id: 201, old_total: 10000, new_total: 8000, reason: '單價改了', operator_name: '合成主任' };

beforeEach(() => localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'super_admin' } })));
afterEach(() => vi.unstubAllGlobals());

async function mountWith(handler) {
  const fetchMock = vi.fn(async (url) => handler(String(url)));
  vi.stubGlobal('fetch', fetchMock);
  const w = mount(BillCorrectionLogPage);
  await flushPromises();
  return { w, fetchMock };
}

describe('更正紀錄', () => {
  it('shows the date, campus, bills, old and new total, reason and operator', async () => {
    const { w } = await mountWith(() => res(200, { data: [ROW], current_page: 1, last_page: 1, total: 1 }));
    const t = w.get('[data-testid="bcl-table"]').text();
    for (const s of ['2026-11-12', '合成分校', '#101', '#201', 'NT$ 10,000 → NT$ 8,000', '單價改了', '合成主任']) expect(t).toContain(s);
    expect(w.find('[data-testid="bcl-empty"]').exists()).toBe(false);
    expect(w.find('[data-testid="bcl-prev"]').exists()).toBe(false); // one page
  });

  it('says there are no corrections yet when the list is empty', async () => {
    const { w } = await mountWith(() => res(200, { data: [], current_page: 1, last_page: 1, total: 0 }));
    expect(w.get('[data-testid="bcl-empty"]').text()).toContain('還沒有任何更正紀錄');
    expect(w.find('[data-testid="bcl-table"]').exists()).toBe(false);
  });

  it('pages forward and filters by month', async () => {
    const { w, fetchMock } = await mountWith((u) => res(200, { data: [ROW], current_page: Number(new URL(u, 'http://x').searchParams.get('page')), last_page: 2, total: 21 }));
    await w.get('[data-testid="bcl-next"]').trigger('click');
    await flushPromises();
    expect(String(fetchMock.mock.calls.at(-1)[0])).toContain('page=2');
    await w.get('[data-testid="bcl-month"]').setValue('2026-11');
    await w.get('[data-testid="bcl-month"]').trigger('change');
    await flushPromises();
    const last = String(fetchMock.mock.calls.at(-1)[0]);
    expect(last).toContain('month=2026-11');
    expect(last).toContain('page=1');
  });

  it('shows a plain error with a way to retry when the load fails', async () => {
    const { w } = await mountWith(() => res(500, {}));
    expect(w.get('[data-testid="bcl-error"]').text()).toContain('重新載入');
    expect(w.find('[data-testid="bcl-empty"]').exists()).toBe(false);
  });
});
