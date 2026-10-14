// #4041: notes of a bill voided by a course delete stay visible in the ledger, read-only.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

async function mountWith(extra) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200,
    json: async () => ({ summary: {}, scope: { student_class_id: 1 }, invoices: [], receipts: [], anomalies: [], ...extra }) })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
  await flushPromises();
  return w;
}

describe('學生帳務對帳 · 已作廢帳單的備註', () => {
  it('lists the notes read-only, with the voided label and no add form', async () => {
    const w = await mountWith({ voided_invoice_notes: [{ invoice_id: 7, invoice_no: 'INV-202610-000007', course_ref: 'COURSE-000003',
      read_only: true, label: '（帳單已作廢）', notes: [{ id: null, body: '[合約 #3 刪除，帳單作廢]', author_name: null, created_at: null, legacy: true }, { id: 1, body: '家長要求先暫停', author_name: '主任', created_at: '2026-10-13T10:00:00+08:00' }] }] });
    const section = w.find('[data-testid="ledger-voided-invoice-notes"]');
    expect(section.exists()).toBe(true);
    expect(section.text()).toContain('INV-202610-000007');
    expect(section.text()).toContain('（帳單已作廢）');
    expect(section.text()).toContain('家長要求先暫停');
    expect(section.text()).toContain('主任・2026/10/13');
    expect(section.text()).toContain('原備註（舊資料）');
    expect(section.text()).toContain('[合約 #3 刪除，帳單作廢]');
    expect(section.find('textarea').exists()).toBe(false);
    expect(section.find('form').exists()).toBe(false);
  });

  it('renders nothing when there are no such notes', async () => {
    const w = await mountWith({});
    expect(w.find('[data-testid="ledger-voided-invoice-notes"]').exists()).toBe(false);
  });
});
