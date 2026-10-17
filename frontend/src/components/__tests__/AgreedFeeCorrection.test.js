import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AgreedFeeCorrectionModal from '../AgreedFeeCorrectionModal.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const plan = { old_charge: 8000, original_invoice_total: 7600, new_charge: 500, charge_delta: -7500, obligation_delta: -7100, new_outstanding: 500,
  unchanged: { Rate: 1000, SessionCount: 8, RemainingSessions: 5, UsedSessions: 3, StartDate: '2026-10-01', EndDate: '2026-12-31' },
  message: '只更正約定費用，原帳單作廢並建立新未繳帳單，不收款。', preview_token: 'signed-state' };
const ok = (json) => ({ ok: true, status: 200, json: async () => json });
afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('local disabled agreed-fee slice', () => {
  it('previews stored Charge separately from old bill; retries exact payload and UUID after a lost response', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const calls = []; let applies = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      const body = JSON.parse(options.body); calls.push({ url, body });
      if (url.endsWith('/preview')) return ok(plan);
      if (++applies === 1) throw new Error('Lost response');
      return ok({ correction_id: 1 });
    }));
    const w = mount(AgreedFeeCorrectionModal, { props: { courseId: 7, invoiceId: 8 }, global: { stubs: { Teleport: true } } });
    await w.get('[data-testid="fee-charge"]').setValue('500'); await w.get('[data-testid="fee-reason"]').setValue('約定費用誤植');
    await w.get('form').trigger('submit'); await flushPromises();
    expect(w.text()).toContain('NT$8,000'); expect(w.text()).toContain('NT$7,600'); expect(w.text()).toContain('堂數 8');
    expect(w.text()).toContain('新增收款 0');
    await w.get('[data-testid="fee-apply"]').trigger('click'); await flushPromises();
    await w.get('[data-testid="fee-apply"]').trigger('click'); await flushPromises();
    expect(calls[1].body).toEqual({ ...calls[0].body, preview_token: 'signed-state' }); expect(calls[2].body).toEqual(calls[1].body);
    expect(w.emitted('changed')).toHaveLength(1); w.unmount();
  });

  it.each([false, true])('student-only ledger shows per-course action only with server capability %s', async (enabled) => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const courses = [7, 9].map((id) => ({ id, subject: `試聽${id}`, schedule_mode: 'count', charge: 8000, paid: false, package_id: null }));
    vi.stubGlobal('fetch', vi.fn(async (url) => String(url).includes('/accounting/ledger')
      ? ok({ capabilities: { agreed_fee_correction: enabled }, summary: {}, scope: {}, courses, receipts: [], anomalies: [],
        invoices: [{ id: 80, student_class_id: 7, status: 'unpaid', total_amount: 8000, payments: [] }] })
      : ok({ data: [] })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentId: 1 }, global: { stubs: { Teleport: true } } }); await flushPromises();
    expect(w.find('[data-testid="fee-correction-open-7"]').exists()).toBe(enabled);
    expect(w.find('[data-testid="fee-correction-open-9"]').exists()).toBe(enabled);
    if (enabled) {
      await w.get('[data-testid="fee-correction-open-9"]').trigger('click');
      expect(w.getComponent(AgreedFeeCorrectionModal).props()).toMatchObject({ courseId: 9, invoiceId: null });
      await w.getComponent(AgreedFeeCorrectionModal).vm.$emit('close');
      await w.get('[data-testid="fee-correction-open-7"]').trigger('click');
      expect(w.getComponent(AgreedFeeCorrectionModal).props()).toMatchObject({ courseId: 7, invoiceId: 80 });
    }
    w.unmount();
  });
});
