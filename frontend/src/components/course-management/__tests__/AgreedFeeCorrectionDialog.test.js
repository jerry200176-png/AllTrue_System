import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AgreedFeeCorrectionDialog from '../AgreedFeeCorrectionDialog.vue';
import {
  buildCorrectionBody,
  isAgreedFeeCorrectionAvailable,
  postAgreedFeeCorrection,
  singleLiveUnpaidInvoiceId,
  validateCorrectionForm,
} from '../../../lib/agreedFeeCorrection.js';

const UUID_A = '11111111-1111-4111-8111-111111111111';
const UUID_B = '22222222-2222-4222-8222-222222222222';

function jsonResponse(status, body) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

const PLAN = {
  scope: 'billed', original_invoice_id: 77, original_outstanding: 7600, old_charge: 8000, new_charge: 500,
  charge_delta: -7500, new_outstanding: 500, unchanged: {}, effects: { payment: 0 },
  message: '只更正約定費用，原帳單作廢並建立新未繳帳單，不收款。', preview_token: 'signed-token-1',
};

function mountDialog(fetchImpl, invoices = [{ id: 77, status: 'unpaid', paid_amount: 0 }]) {
  return mount(AgreedFeeCorrectionDialog, {
    props: { open: true, course: { id: 42, Charge: 8000 }, invoices, courseLabel: 'COURSE-000042', fetchImpl },
    global: { stubs: { teleport: true } },
  });
}

async function fillAndPreview(wrapper) {
  await wrapper.find('[data-testid="afc-new-charge"]').setValue('500');
  await wrapper.find('[data-testid="afc-reason"]').setValue('主任確認約定費用');
  await wrapper.find('[data-testid="afc-preview"]').trigger('click');
  await flushPromises();
}

function sentBody(fetchImpl, call) {
  return JSON.parse(fetchImpl.mock.calls[call][1].body);
}

afterEach(() => vi.restoreAllMocks());

describe('agreed-fee correction entry visibility', () => {
  it('is available only when the invoices response explicitly says true', () => {
    expect(isAgreedFeeCorrectionAvailable({ agreed_fee_correction_available: true })).toBe(true);
    expect(isAgreedFeeCorrectionAvailable({ agreed_fee_correction_available: false })).toBe(false);
    expect(isAgreedFeeCorrectionAvailable({ agreed_fee_correction_available: 'true' })).toBe(false);
    expect(isAgreedFeeCorrectionAvailable({ invoices: [] })).toBe(false);
    expect(isAgreedFeeCorrectionAvailable(null)).toBe(false);
  });

  it('CourseManagement renders the button only behind the server availability flag', () => {
    const source = readFileSync(resolve(__dirname, '../../../pages/CourseManagement.vue'), 'utf8');
    expect(source).toMatch(/v-if="row\.agreedFeeCorrectionAvailable"[\s\S]{0,400}>更正約定費用<\/button>/);
    expect(source).toContain('agreedFeeCorrectionAvailable: invRes.ok && isAgreedFeeCorrectionAvailable(invJson)');
  });
});

describe('agreed-fee correction helpers', () => {
  it('auto-selects only a single live unpaid invoice', () => {
    expect(singleLiveUnpaidInvoiceId([{ id: 5, status: 'unpaid', paid_amount: 0 }])).toBe(5);
    expect(singleLiveUnpaidInvoiceId([{ id: 5, status: 'unpaid' }, { id: 6, status: 'unpaid' }])).toBeNull();
    expect(singleLiveUnpaidInvoiceId([{ id: 5, status: 'partial', paid_amount: 100 }])).toBeNull();
    expect(singleLiveUnpaidInvoiceId([])).toBeNull();
  });

  it('validates scope, charge and reason before calling the API', () => {
    const ok = { scope: 'billed', originalInvoiceId: 5, newCharge: 500, reason: 'abc' };
    expect(validateCorrectionForm(ok)).toBe('');
    expect(validateCorrectionForm({ ...ok, originalInvoiceId: null })).not.toBe('');
    expect(validateCorrectionForm({ ...ok, newCharge: 0 })).not.toBe('');
    expect(validateCorrectionForm({ ...ok, newCharge: 1000000 })).not.toBe('');
    expect(validateCorrectionForm({ ...ok, reason: ' a ' })).not.toBe('');
    expect(validateCorrectionForm({ ...ok, scope: 'unbilled', originalInvoiceId: null })).toBe('');
    expect(buildCorrectionBody({ ...ok, scope: 'unbilled' }, UUID_A)).not.toHaveProperty('original_invoice_id');
  });

  it('returns the server refusal message verbatim', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(422, { message: '仍有收款、待核對回報', code: 'payment_evidence_blocks' }));
    const res = await postAgreedFeeCorrection(42, 'preview', {}, fetchImpl);
    expect(res).toMatchObject({ ok: false, status: 422, code: 'payment_evidence_blocks', message: '仍有收款、待核對回報' });
    expect(fetchImpl.mock.calls[0][0]).toBe('/api/v1/student-classes/42/agreed-fee-correction/preview');
  });
});

describe('AgreedFeeCorrectionDialog', () => {
  it('preview then apply sends the same idempotency key and the preview token', async () => {
    vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValueOnce(UUID_A).mockReturnValue(UUID_B);
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(jsonResponse(200, PLAN))
      .mockResolvedValueOnce(jsonResponse(200, { correction_id: 9, scope: 'billed', original_invoice_id: 77, replacement_invoice_id: 78, old_charge: 8000, new_charge: 500 }));
    const wrapper = mountDialog(fetchImpl);

    await fillAndPreview(wrapper);
    expect(fetchImpl.mock.calls[0][0]).toBe('/api/v1/student-classes/42/agreed-fee-correction/preview');
    const previewBody = sentBody(fetchImpl, 0);
    expect(previewBody).toEqual({ scope: 'billed', original_invoice_id: 77, new_charge: 500, reason: '主任確認約定費用', idempotency_key: UUID_A });
    expect(wrapper.find('[data-testid="afc-plan"]').text()).toContain('8,000');
    expect(wrapper.text()).toContain('不會建立任何收款、收據、退款或通知');

    await wrapper.find('[data-testid="afc-apply"]').trigger('click');
    await flushPromises();
    expect(fetchImpl.mock.calls[1][0]).toBe('/api/v1/student-classes/42/agreed-fee-correction/apply');
    expect(sentBody(fetchImpl, 1)).toEqual({ ...previewBody, preview_token: 'signed-token-1' });
    expect(wrapper.emitted('applied')?.[0]?.[0]).toMatchObject({ correction_id: 9 });
    expect(wrapper.text()).toContain('新未繳帳單 INV-…-000078');
  });

  it('409 state_changed shows the server message and forces a new preview', async () => {
    vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(UUID_A);
    const fetchImpl = vi.fn()
      .mockResolvedValueOnce(jsonResponse(200, PLAN))
      .mockResolvedValueOnce(jsonResponse(409, { message: '預覽已過期或帳務／課程已變更，請重新預覽。', code: 'state_changed' }))
      .mockResolvedValueOnce(jsonResponse(200, { ...PLAN, preview_token: 'signed-token-2' }))
      .mockResolvedValueOnce(jsonResponse(200, { correction_id: 9, original_invoice_id: 77, replacement_invoice_id: 78, old_charge: 8000, new_charge: 500 }));
    const wrapper = mountDialog(fetchImpl);

    await fillAndPreview(wrapper);
    await wrapper.find('[data-testid="afc-apply"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="afc-error"]').text()).toBe('預覽已過期或帳務／課程已變更，請重新預覽。');
    expect(wrapper.find('[data-testid="afc-apply"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="afc-plan"]').exists()).toBe(false);
    expect(wrapper.emitted('applied')).toBeUndefined();

    await wrapper.find('[data-testid="afc-preview"]').trigger('click');
    await flushPromises();
    await wrapper.find('[data-testid="afc-apply"]').trigger('click');
    await flushPromises();
    expect(sentBody(fetchImpl, 3).preview_token).toBe('signed-token-2');
    expect(sentBody(fetchImpl, 3).idempotency_key).toBe(UUID_A);
    expect(wrapper.emitted('applied')).toHaveLength(1);
  });

  it('editing the form after preview requires a new preview', async () => {
    vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(UUID_A);
    const fetchImpl = vi.fn().mockResolvedValueOnce(jsonResponse(200, PLAN));
    const wrapper = mountDialog(fetchImpl);
    await fillAndPreview(wrapper);
    expect(wrapper.find('[data-testid="afc-apply"]').exists()).toBe(true);
    await wrapper.find('[data-testid="afc-new-charge"]').setValue('600');
    expect(wrapper.find('[data-testid="afc-apply"]').exists()).toBe(false);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('asks for an invoice id when there is no single live unpaid invoice', async () => {
    vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(UUID_A);
    const fetchImpl = vi.fn().mockResolvedValueOnce(jsonResponse(404, { message: '此課程不在本次限定更正範圍。', code: 'course_out_of_scope' }));
    const wrapper = mountDialog(fetchImpl, []);
    expect(wrapper.find('[data-testid="afc-invoice-id"]').exists()).toBe(true);
    await wrapper.find('[data-testid="afc-invoice-id"]').setValue('91');
    await fillAndPreview(wrapper);
    expect(sentBody(fetchImpl, 0).original_invoice_id).toBe(91);
    expect(wrapper.find('[data-testid="afc-error"]').text()).toBe('此課程不在本次限定更正範圍。');
  });
});
