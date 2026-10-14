import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const reply = (id) => ({ ok: true, json: async () => ({ student: { id, name: `Fixture ${id}` }, contracts: [], invoices: [{ id: id * 101, invoice_no: `Fixture ${id}`, total_amount: 100, status: 'unpaid', payments: [] }], receipts: [], scope: {} }) });
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });

describe('ledger note targets follow the latest student request', () => {
  it.each([false, true])('ignores an older response (network error: %s)', async (oldError) => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    let resolveOld;
    let rejectOld;
    const old = new Promise((resolve, reject) => { resolveOld = resolve; rejectOld = reject; });
    const fetch = vi.fn().mockReturnValueOnce(old).mockResolvedValueOnce(reply(2));
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(AccountingLedgerModal, {
      props: { show: true, studentId: 1 },
      global: { stubs: { BillingNotesPanel: { props: ['subjectId'], template: '<span class="note-subject">{{ subjectId }}</span>' } } },
    });
    await wrapper.setProps({ studentId: 2 });
    await flushPromises();
    await wrapper.get('[data-testid="ledger-invoice-toggle-202"]').trigger('click');
    expect(wrapper.findAll('.note-subject').map((n) => n.text())).toContain('202');
    oldError ? rejectOld(new Error('Older request failed')) : resolveOld(reply(1));
    await flushPromises();
    expect(wrapper.findAll('.note-subject').map((n) => n.text())).toContain('202');
    expect(wrapper.findAll('.note-subject').map((n) => n.text())).not.toContain('101');
    expect(wrapper.text()).not.toContain('Older request failed');
    wrapper.unmount();
  });
});
