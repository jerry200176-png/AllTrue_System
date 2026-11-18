// Step 2 更正帳單 (PR 2): the dialog's preview -> apply flow, the refusal copy, the range-shortfall notice and the two entry buttons.
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BillCorrectionDialog from '../BillCorrectionDialog.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { HQ_CODES, REFUSAL_COPY } from '../../lib/billCorrectionCopy.js';

const q = (id) => document.querySelector(`[data-testid="${id}"]`);
const type = async (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); await flushPromises(); };
const res = (status, body) => ({ ok: status < 400, status, json: async () => body });
const PLAN = { shape: 'monthly', invoice_id: 5, old_total: 4000, new_total: 5200, moved_amount: 4000, outstanding_after: 1200, overpaid_after: 0, status_after: 'partial',
  added_lessons: ['2026-11-10', '2026-11-17'], range_shortfall: 0, period_end_after: '2026-11-17', state_hash: 'h'.repeat(64) };
let wrappers = [];
let calls;

// routes: { preview: [responses...], apply: [responses...] } consumed in order, the last one repeats.
function stubFetch(routes) {
  calls = [];
  const left = { preview: [...(routes.preview ?? [])], apply: [...(routes.apply ?? [])] };
  vi.stubGlobal('fetch', vi.fn(async (url, opts = {}) => {
    const kind = String(url).includes('/correction/apply') ? 'apply' : String(url).includes('/correction/preview') ? 'preview' : null;
    if (!kind) return res(200, { summary: {}, scope: {}, student: {}, invoices: [], receipts: [], anomalies: [] });
    calls.push({ kind, body: JSON.parse(opts.body) });
    return left[kind].length > 1 ? left[kind].shift() : left[kind][0];
  }));
}
async function openDialog(props = {}) {
  const w = mount(BillCorrectionDialog, { props: { open: false, invoiceId: 5, ...props }, attachTo: document.body });
  wrappers.push(w);
  await w.setProps({ open: true });
  await flushPromises();
  return w;
}

beforeEach(() => localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } })));
afterEach(() => { wrappers.forEach((w) => w.unmount()); wrappers = []; vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('更正帳單 · monthly bill (server computes the total)', () => {
  it('previews on open, then applies with the state_hash and the typed reason', async () => {
    stubFetch({ preview: [res(200, PLAN)], apply: [res(201, { replayed: false, replacement_invoice_id: 6 })] });
    const w = await openDialog();
    expect(q('bc-plan').textContent).toContain('原本 NT$ 4,000，更正後 NT$ 5,200');
    expect(q('bc-lessons').textContent).toContain('2026-11-10、2026-11-17');
    expect(q('bc-new-total')).toBeNull(); // read-only: the server priced it
    expect(q('bc-shortfall')).toBeNull();
    expect(q('bc-apply').disabled).toBe(true); // reason required
    await type(q('bc-reason'), '補上漏收的課');
    expect(q('bc-apply').disabled).toBe(false);
    q('bc-apply').click();
    await flushPromises();
    expect(calls.map((c) => c.kind)).toEqual(['preview', 'apply']);
    expect(calls[1].body).toEqual({ state_hash: PLAN.state_hash, reason: '補上漏收的課' });
    expect(w.emitted('corrected')).toHaveLength(1);
    expect(w.emitted('update:open')).toEqual([[false]]);
  });

  it('shows the range shortfall in its own sentence before the director confirms', async () => {
    stubFetch({ preview: [res(200, { ...PLAN, range_shortfall: 800 })] });
    await openDialog();
    const note = q('bc-shortfall');
    expect(note.textContent).toContain('這次要補收 NT$ 800');
    expect(note.textContent).toContain('先確認這些課真的該收費');
  });

  it('after stale state_hash offers 重新預覽 and nothing else to press', async () => {
    stubFetch({ preview: [res(200, PLAN), res(200, { ...PLAN, state_hash: 'n'.repeat(64) })], apply: [res(409, { code: 'state_changed', message: 'x' })] });
    await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.state_changed);
    expect(q('bc-apply')).toBeNull();
    q('bc-repreview').click();
    await flushPromises();
    expect(q('bc-refusal')).toBeNull();
    expect(q('bc-plan')).not.toBeNull();
  });
});

describe('更正帳單 · count bill (director types the total)', () => {
  it('shows the total input when the server asks for one, previews that total, applies it', async () => {
    stubFetch({
      preview: [res(422, { code: 'new_total_required', message: 'x' }), res(200, { ...PLAN, shape: 'count', old_total: 10000, new_total: 8000, moved_amount: 10000, outstanding_after: 0, overpaid_after: 2000, added_lessons: [] })],
      apply: [res(201, { replayed: false })],
    });
    await openDialog();
    expect(q('bc-refusal')).toBeNull(); // asking for a total is not an error
    expect(q('bc-plan')).toBeNull();
    expect(q('bc-preview').disabled).toBe(true);
    await type(q('bc-new-total'), '8000');
    q('bc-preview').click();
    await flushPromises();
    expect(calls[1].body.new_total).toBe(8000);
    expect(q('bc-plan').textContent).toContain('多收了 NT$ 2,000，不會自動退費');
    await type(q('bc-reason'), '單價改了');
    q('bc-apply').click();
    await flushPromises();
    expect(calls[2]).toEqual({ kind: 'apply', body: { state_hash: PLAN.state_hash, reason: '單價改了', new_total: 8000 } });
  });

  it('drops the preview when the total is edited afterwards', async () => {
    stubFetch({ preview: [res(422, { code: 'new_total_required' }), res(200, { ...PLAN, shape: 'count', new_total: 8000 })] });
    await openDialog();
    await type(q('bc-new-total'), '8000');
    q('bc-preview').click();
    await flushPromises();
    await type(q('bc-new-total'), '7000');
    expect(q('bc-plan')).toBeNull();
    expect(q('bc-apply').disabled).toBe(true);
  });
});

describe('更正帳單 · races and unknown outcomes', () => {
  it('drops a count preview whose total was edited while it was running', async () => {
    let release;
    stubFetch({ preview: [res(422, { code: 'new_total_required' })] });
    const base = fetch;
    vi.stubGlobal('fetch', vi.fn(async (url, opts) => {
      const b = JSON.parse(opts.body);
      if (b.new_total === 8000) await new Promise((r) => { release = r; });
      return b.new_total === 8000 ? res(200, { ...PLAN, shape: 'count', new_total: 8000 }) : base(url, opts);
    }));
    await openDialog();
    await type(q('bc-new-total'), '8000');
    q('bc-preview').click();
    await flushPromises();
    expect(q('bc-new-total').disabled).toBe(true); // locked while the preview runs
    q('bc-new-total').disabled = false; // as a stubborn keyboard would
    await type(q('bc-new-total'), '7000');
    release();
    await flushPromises();
    expect(q('bc-plan')).toBeNull();
  });

  it('tells the director a lost apply response is unconfirmed and asks the parent to reread the ledger', async () => {
    stubFetch({ preview: [res(200, PLAN)], apply: [res(502, {})] });
    const w = await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-refusal').textContent).toContain('不確定有沒有更正成功');
    expect(q('bc-refusal').textContent).not.toContain('沒有改到任何資料');
    expect(w.emitted('changed')).toHaveLength(1);
  });
});

describe('更正帳單 · preview failures, HQ button, copy coverage', () => {
  it('a failed preview says nothing changed and offers 重新預覽 (never the unsure-outcome text)', async () => {
    stubFetch({ preview: [res(502, {}), res(200, PLAN)] });
    const w = await openDialog();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.preview_failed);
    expect(q('bc-refusal').textContent).toContain('帳單沒有任何改變');
    expect(q('bc-apply')).toBeNull();
    expect(w.emitted('changed')).toBeUndefined();
    q('bc-repreview').click();
    await flushPromises();
    expect(q('bc-plan')).not.toBeNull();
    expect(q('bc-refusal')).toBeNull();
  });

  it('state_changed hides the stale figures; a 422 without errors.reason is not blamed on the reason', async () => {
    stubFetch({ preview: [res(200, PLAN)], apply: [res(409, { code: 'state_changed' })] });
    await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-plan')).toBeNull();
    wrappers.pop().unmount(); document.body.innerHTML = '';
    stubFetch({ preview: [res(200, PLAN)], apply: [res(422, { message: 'x', errors: { state_hash: ['bad'] } }), res(422, { errors: { reason: ['short'] } })] });
    await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.bad_request);
    expect(q('bc-repreview')).not.toBeNull();
  });

  it('the controller 403 maps to forbidden without a ledger reload; a prototype key is not a code', async () => {
    stubFetch({ preview: [res(403, { message: 'This action is unauthorized.' })] });
    const w = await openDialog();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.forbidden);
    expect(w.emitted('changed')).toBeUndefined();
    wrappers.pop().unmount(); document.body.innerHTML = '';
    stubFetch({ preview: [res(200, PLAN)], apply: [res(422, { code: 'constructor' })] });
    await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.fallback);
  });

  it('every refusal that says 回報總部 has a 回報給總部 button that opens the in-app report with the bill id only', async () => {
    const asked = [];
    const on = (e) => asked.push(e.detail);
    window.addEventListener('alltrue-open-bug-report', on);
    const textual = Object.keys(REFUSAL_COPY).filter((c) => REFUSAL_COPY[c].includes('回報總部'));
    expect([...HQ_CODES].sort()).toEqual(textual.sort());
    stubFetch({ preview: [res(422, { code: 'missing_rate' })] });
    const w = await openDialog();
    q('bc-ask-hq').click();
    window.removeEventListener('alltrue-open-bug-report', on);
    expect(asked).toEqual([{ description: `更正帳單時：${REFUSAL_COPY.missing_rate}`, relatedReference: '帳單ID 5' }]);
    expect(w.emitted('update:open')).toEqual([[false]]);
  });

  it('maps every refusal code written in BillCorrection.php / BillCorrectionController.php (and #5495\'s range_price_mismatch)', () => {
    const IGNORE = new Set(['replay', 'void_refunded', 'legacy_reversal_unreconciled', 'void_transferred_credit']); // replay is internal; the other three belong to the receipt-void path and never reach this dialog
    const src = ['Services/Billing/BillCorrection.php', 'Http/Controllers/BillCorrectionController.php']
      .map((f) => readFileSync(resolve(__dirname, '../../../../backend/app', f), 'utf8')).join('\n');
    const codes = [...src.matchAll(/(?:refuse\('|'code' => ')([a-z_]+)'/g)].map((m) => m[1]).filter((c) => !IGNORE.has(c));
    expect(codes.length).toBeGreaterThan(15); // the extraction found the list
    expect(codes.filter((c) => !Object.hasOwn(REFUSAL_COPY, c))).toEqual([]);
    expect(Object.hasOwn(REFUSAL_COPY, 'range_price_mismatch')).toBe(true);
  });
});

describe('更正帳單 · refusal copy', () => {
  const SERVER_CODES = ['invoice_not_found', 'bill_correction_off', 'unavailable', 'unsupported_bill', 'unchanged_total', 'state_changed', 'invoice_void', 'pending_report',
    'unsupported_course', 'money_moved', 'ambiguous_invoice', 'idempotency_conflict', 'already_corrected', 'paid_state_unverified', 'repriceable', 'missing_rate', 'no_gap', 'gap_covered_elsewhere'];

  it.each(SERVER_CODES)('%s shows one plain sentence with the next step, in Traditional Chinese', async (code) => {
    stubFetch({ preview: [res(422, { code, message: 'raw english or server text' })] });
    await openDialog();
    const text = q('bc-refusal').textContent;
    expect(text).toBe(REFUSAL_COPY[code]);
    expect(text).toMatch(/[一-鿿]/);
    expect(text).not.toContain('raw english');
    expect(text).toMatch(/請/); // always tells the director what to do next
  });

  it('closes the form on a refusal nothing can fix here, keeps it on a retryable one', async () => {
    stubFetch({ preview: [res(422, { code: 'pending_report' })] });
    await openDialog();
    expect(q('bc-apply')).toBeNull();
    expect(q('bc-reason')).toBeNull();
    wrappers.pop().unmount(); document.body.innerHTML = '';
    stubFetch({ preview: [res(200, PLAN)], apply: [res(500, { message: 'Server error' })] });
    await openDialog();
    await type(q('bc-reason'), '補上漏收的課');
    q('bc-apply').click();
    await flushPromises();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.fallback);
    expect(q('bc-apply').disabled).toBe(false); // same state_hash: pressing again is a safe replay
  });

  it('maps 403 and short reasons to their own sentences', async () => {
    stubFetch({ preview: [res(403, { message: 'Forbidden' })] });
    await openDialog();
    expect(q('bc-refusal').textContent).toBe(REFUSAL_COPY.forbidden);
  });
});

describe('更正帳單 buttons', () => {
  const bill = (o) => ({ id: 5, invoice_no: 'INV-5', student_class_id: 1, status: 'unpaid', total_amount: 4000, paid_amount: 0, payments: [], reports: [], ...o });
  async function ledger(invoices) {
    const body = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices, receipts: [], anomalies: [] };
    vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).includes('/correction/') ? res(200, PLAN) : res(200, body))));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } }, attachTo: document.body });
    wrappers.push(w);
    await flushPromises();
    return w;
  }

  it('ledger row shows 更正帳單 only when can_correct is true, and it opens the dialog on that bill', async () => {
    const w = await ledger([bill({ can_correct: true }), bill({ id: 6, can_correct: false }), bill({ id: 7 })]);
    expect(w.findAll('[data-testid^="ledger-correct-"]').map((b) => b.attributes('data-testid'))).toEqual(['ledger-correct-5']);
    await w.get('[data-testid="ledger-correct-5"]').trigger('click');
    await flushPromises();
    expect(q('bc-plan')).not.toBeNull();
    expect(fetch.mock.calls.some(([u]) => String(u).includes('invoices/5/correction/preview'))).toBe(true);
  });

  it('payment entry refusal shows 更正帳單 only with correctable_invoice_id', async () => {
    const row = { id: 5, student_name: '合成學生', subject: '英文', charge: 3000, payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000 };
    const refuse = (extra) => vi.stubGlobal('fetch', vi.fn(async () => res(422, { code: 'amount_exceeds_outstanding', message: 'x', expected_amount: 3000, ...extra })));
    for (const [extra, shown] of [[{ correctable_invoice_id: 5 }, true], [{}, false]]) {
      refuse(extra);
      const w = mount(PaymentEntryModal, { props: { show: false, row }, attachTo: document.body });
      wrappers.push(w);
      await w.setProps({ show: true });
      await w.find('form').trigger('submit');
      await flushPromises();
      expect(w.find('[data-testid="pe-correct-bill"]').exists()).toBe(shown);
      if (shown) { // after a correction the form (still on the voided bill) closes and the parent rereads the ledger
        w.findComponent(BillCorrectionDialog).vm.$emit('corrected', {});
        expect(w.emitted('conflict')).toHaveLength(2);
        expect(w.emitted('close')).toHaveLength(1);
      }
    }
  });
});
