// 收據移到正確帳單 (#5113, in-app #522): the dialog's targets -> preview -> apply flow, every refusal's plain copy, and the two ledger entry points.
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ReceiptMoveDialog from '../ReceiptMoveDialog.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import { NEEDS_HQ_COPY, REFUSAL } from '../../lib/receiptMoveCopy.js';

const q = (id) => document.querySelector(`[data-testid="${id}"]`);
const type = async (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); await flushPromises(); };
const res = (status, body) => ({ ok: status < 400, status, json: async () => body });
const TARGETS = { amount: 3000, student_id: 11, targets: [
  { invoice_id: 21, course_id: 1, subject: '數學', billing_period: '2026-11', total: 3000, remaining: 3000, status: 'unpaid' },
  { invoice_id: 22, course_id: 2, subject: '英文', billing_period: null, total: 5000, remaining: 4000, status: 'partial' },
] };
const PLAN = { preview: true, amount: 3000, needs_role: 'director', report_age_days: 3, state_hash: 'v2:' + 'h'.repeat(64), warnings: ['source_bill_becomes_unpaid'],
  from: { student_id: 10, course_id: 9, invoice_id: 20, balance_before: 0, balance_after: 3000 },
  to: { student_id: 11, course_id: 1, invoice_id: 21, balance_before: 3000, balance_after: 0 } };
let wrappers = [];
let calls;

// routes: targets / preview / apply / students: responses consumed in order, the last one repeats.
function stubFetch(routes) {
  calls = [];
  const left = Object.fromEntries(['targets', 'preview', 'apply', 'students'].map((k) => [k, [...(routes[k] ?? [])]]));
  vi.stubGlobal('fetch', vi.fn(async (url, opts = {}) => {
    const u = String(url);
    const body = opts.body ? JSON.parse(opts.body) : null;
    const kind = u.includes('/reassign-targets') ? 'targets' : u.includes('/reassign') ? (body?.preview ? 'preview' : 'apply') : u.includes('/students?') ? 'students' : null;
    if (!kind) return res(200, { summary: {}, scope: {}, student: {}, invoices: [], receipts: [], anomalies: [] });
    calls.push({ kind, url: u, body });
    return left[kind].length > 1 ? left[kind].shift() : left[kind][0];
  }));
}
async function openDialog(props = {}) {
  const w = mount(ReceiptMoveDialog, { props: { open: false, reportId: 7, studentId: 10, studentName: '合成學生', campusId: 1, amount: 3000, fromLabel: '數學，已對應帳單', ...props }, attachTo: document.body });
  wrappers.push(w);
  await w.setProps({ open: true });
  await flushPromises();
  return w;
}
const choose = async (id = 21) => { q(`rm-target-${id}`).click(); await flushPromises(); };

beforeEach(() => localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } })));
afterEach(() => { wrappers.forEach((w) => w.unmount()); wrappers = []; vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('收據移到正確帳單 · flow', () => {
  it('shows a loading state, then the bills as tappable rows without ids', async () => {
    let release;
    vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { release = () => r(res(200, TARGETS)); })));
    const w = mount(ReceiptMoveDialog, { props: { open: false, reportId: 7, studentId: 10, studentName: '合成學生', campusId: 1, amount: 3000 }, attachTo: document.body });
    wrappers.push(w);
    await w.setProps({ open: true });
    await flushPromises();
    expect(q('rm-loading')).not.toBeNull();
    release();
    await flushPromises();
    expect(q('rm-loading')).toBeNull();
    expect(q('rm-target-21').textContent).toContain('數學 · 2026/11');
    expect(q('rm-target-21').textContent).toContain('還差 NT$ 3,000');
    expect(q('rm-target-22').textContent).toContain('英文');
    expect(document.body.textContent).not.toMatch(/reassign_|invoice_id|課程編號/);
  });

  it('empty list explains the next step', async () => {
    stubFetch({ targets: [res(200, { ...TARGETS, targets: [] })] });
    await openDialog();
    expect(q('rm-empty').textContent).toContain('請先確認帳單已經開立');
  });

  it('pick -> preview -> reason required -> confirm sends the hash, the target and the reason, then reports success', async () => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(200, PLAN)], apply: [res(200, { reassignment_id: 5, old_report_id: 7, new_report_id: 8 })] });
    const w = await openDialog();
    expect(calls[0].url).toContain('/payment-reports/7/reassign-targets');
    expect(calls[0].url).not.toContain('student_id');
    await choose(21);
    expect(calls[1].body).toEqual({ target_invoice_id: 21, preview: true });
    expect(q('rm-plan').textContent).toContain('NT$ 3,000');
    expect(q('rm-from-balance').textContent).toBe('原帳單：還差 NT$ 0 → NT$ 3,000');
    expect(q('rm-to-balance').textContent).toBe('正確帳單：還差 NT$ 3,000 → NT$ 0');
    expect(q('rm-warning').textContent).toBe('原帳單會變回未繳。');
    expect(q('rm-apply').disabled).toBe(true); // reason required
    q('rm-chip-選錯學生').click();
    await flushPromises();
    expect(q('rm-reason').value).toBe('選錯學生');
    expect(q('rm-apply').disabled).toBe(false);
    q('rm-apply').click();
    await flushPromises();
    expect(calls[2].body).toEqual({ target_invoice_id: 21, state_hash: PLAN.state_hash, reason: '選錯學生' });
    expect(w.emitted('moved')).toHaveLength(1);
    expect(w.emitted('update:open')).toEqual([[false]]);
  });

  it('重選帳單 goes back to the list without any write', async () => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(200, PLAN)] });
    await openDialog();
    await choose(22);
    q('rm-back').click();
    await flushPromises();
    expect(q('rm-plan')).toBeNull();
    expect(q('rm-target-22')).not.toBeNull();
    expect(calls.filter((c) => c.kind === 'apply')).toEqual([]);
  });

  it('換一位學生 searches the same campus, then loads that student\'s bills', async () => {
    stubFetch({ targets: [res(200, TARGETS)], students: [res(200, { data: [{ id: 12, name: '合成弟弟' }] })] });
    await openDialog();
    q('rm-change-student').click();
    await flushPromises();
    await type(q('rm-query'), '弟');
    q('rm-find').dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(calls.find((c) => c.kind === 'students').url).toContain('campus_id=1');
    q('rm-student-12').click();
    await flushPromises();
    expect(calls.filter((c) => c.kind === 'targets').pop().url).toContain('student_id=12');
    expect(q('rm-pick').textContent).toContain('合成弟弟');
  });

  it('a stale hash asks for a new preview; an unsure outcome has the ledger reread; pressing again is the same safe request', async () => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(200, PLAN), res(200, { ...PLAN, state_hash: 'v2:new' })],
      apply: [res(409, { code: 'reassign_state_changed', message: 'x' })] });
    const w = await openDialog();
    await choose();
    await type(q('rm-reason'), '選錯學生');
    q('rm-apply').click();
    await flushPromises();
    expect(q('rm-refusal').textContent).toBe(REFUSAL.reassign_state_changed.text);
    expect(q('rm-apply')).toBeNull();
    q('rm-repreview').click();
    await flushPromises();
    expect(q('rm-refusal')).toBeNull();
    expect(q('rm-plan')).not.toBeNull();
    wrappers.pop().unmount(); document.body.innerHTML = '';

    stubFetch({ targets: [res(200, TARGETS)], preview: [res(200, PLAN)], apply: [res(500, { message: 'Server error' })] });
    const w2 = await openDialog();
    await choose();
    await type(q('rm-reason'), '選錯學生');
    q('rm-apply').click();
    await flushPromises();
    expect(q('rm-refusal').textContent).toBe(REFUSAL.fallback.text);
    expect(q('rm-apply').disabled).toBe(false);
    expect(w2.emitted('changed')).toHaveLength(1);
    expect(w).toBeTruthy();
  });

  it('a super_admin sees plainly that the move runs on HQ authority', async () => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(200, { ...PLAN, needs_role: 'super_admin' })] });
    await openDialog();
    await choose();
    expect(q('rm-hq-note').textContent).toContain('總部帳號');
  });
});

describe('收據移到正確帳單 · refusals', () => {
  const PREVIEW_CODES = Object.keys(REFUSAL).filter((c) => c.startsWith('reassign_'));

  it.each(PREVIEW_CODES)('%s shows one plain Chinese sentence with a next step and no code', async (code) => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(422, { code, message: 'raw english text' })] });
    await openDialog();
    await choose();
    const text = q('rm-refusal').textContent;
    expect(text).toBe(REFUSAL[code].text);
    expect(text).toMatch(/[一-鿿]/);
    expect(text).not.toMatch(/raw english|reassign_/);
    expect(text).toMatch(/請/);
  });

  it.each([['off'], ['amount'], ['age']])('over the director limit (%s) goes to HQ with a 回報給總部 button, nothing else to press', async (why) => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(422, { code: 'reassign_needs_super_admin', message: 'x', needs_role: 'super_admin', hq_reason: why })] });
    const w = await openDialog();
    await choose();
    expect(q('rm-refusal').textContent).toBe(NEEDS_HQ_COPY[why]);
    expect(q('rm-apply')).toBeNull();
    const asked = [];
    const on = (e) => asked.push(e.detail);
    window.addEventListener('alltrue-open-bug-report', on);
    q('rm-ask-hq').click();
    window.removeEventListener('alltrue-open-bug-report', on);
    expect(asked).toEqual([{ description: `移動收據時：${NEEDS_HQ_COPY[why]}`, relatedReference: '收據ID 7' }]);
    expect(w.emitted('update:open')).toEqual([[false]]);
  });

  it('a pick-type refusal puts the bill list back; 403 and a failed list read have their own sentences', async () => {
    stubFetch({ targets: [res(200, TARGETS)], preview: [res(422, { code: 'reassign_target_voided' })] });
    await openDialog();
    await choose();
    expect(q('rm-refusal').textContent).toBe(REFUSAL.reassign_target_voided.text);
    expect(q('rm-target-22')).not.toBeNull();
    wrappers.pop().unmount(); document.body.innerHTML = '';
    stubFetch({ targets: [res(403, { message: 'Forbidden' })] });
    await openDialog();
    expect(q('rm-refusal').textContent).toBe(REFUSAL.forbidden.text);
    wrappers.pop().unmount(); document.body.innerHTML = '';
    stubFetch({ targets: [res(500, { message: 'boom' }), res(200, TARGETS)] });
    await openDialog();
    expect(q('rm-refusal').textContent).toBe(REFUSAL.load_failed.text);
    q('rm-reload').click();
    await flushPromises();
    expect(q('rm-target-21')).not.toBeNull();
  });

  it('maps every refusal code the controller and service can answer', () => {
    const src = ['Services/Billing/ReceiptReassignService.php', 'Http/Controllers/ReceiptReassignController.php']
      .map((f) => readFileSync(resolve(__dirname, '../../../../backend/app', f), 'utf8')).join('\n');
    const codes = [...new Set([...src.matchAll(/(?:refuse\('|'code' => ')(reassign_[a-z_]+)'/g)].map((m) => m[1]))];
    expect(codes.length).toBeGreaterThan(25); // the extraction found the list
    expect(codes.filter((c) => c !== 'reassign_needs_super_admin' && !Object.hasOwn(REFUSAL, c))).toEqual([]);
  });
});

describe('收據移到正確帳單 · ledger entry points', () => {
  const receipt = (o) => ({ report_id: 7, student_class_id: 1, amount: 3000, status: 'confirmed', payment_date: '2026-11-10', payment_method: 'transfer', invoice_id: null, ...o });
  async function ledger(extra = {}) {
    const body = { summary: {}, scope: { student_class_id: 1 }, student: { id: 10, name: '合成學生', campus_id: 1 }, courses: [{ id: 1, subject: '數學' }], invoices: [],
      receipts: [receipt()], anomalies: [{ code: 'receipt_without_invoice', severity: 'warning', message: 'x', report_id: 7, action: { type: 'void_report', report_id: 7 } }], ...extra };
    vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).includes('/reassign') ? res(200, TARGETS) : res(200, body))));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } }, attachTo: document.body });
    wrappers.push(w);
    await flushPromises();
    return w;
  }

  it('card and receipt row offer the move only when the server says it is on', async () => {
    let w = await ledger();
    await w.get('[data-testid="ledger-fold-receipts"]').trigger('click'); // the receipt list starts folded
    expect(w.find('[data-testid="ledger-move-7"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-receipt-move-7"]').exists()).toBe(false);
    wrappers.pop().unmount(); document.body.innerHTML = '';
    w = await ledger({ can_reassign_receipt: true });
    await w.get('[data-testid="ledger-fold-receipts"]').trigger('click');
    expect(w.get('[data-testid="ledger-move-hint-7"]').text()).toBe('這張收據記在別人的帳單上。可以直接移到正確的帳單。');
    expect(w.get('[data-testid="ledger-move-7"]').text()).toBe('移到正確帳單');
    expect(w.get('[data-testid="ledger-receipt-move-7"]').text()).toBe('收錯帳單？');
  });

  it('the card button opens the dialog for that receipt', async () => {
    const w = await ledger({ can_reassign_receipt: true });
    await w.get('[data-testid="ledger-move-7"]').trigger('click');
    await flushPromises();
    expect(q('receipt-move-dialog')).not.toBeNull();
    expect(q('rm-target-21')).not.toBeNull();
    expect(q('rm-source').textContent).toContain('NT$ 3,000');
  });

  it('other anomaly codes and voided receipts get no move button', async () => {
    const w = await ledger({ can_reassign_receipt: true, receipts: [receipt({ status: 'voided' })],
      anomalies: [{ code: 'paid_amount_mismatch', severity: 'warning', message: 'x', report_id: 7, action: null }] });
    await w.get('[data-testid="ledger-fold-receipts"]').trigger('click');
    expect(w.find('[data-testid="ledger-move-7"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-receipt-move-7"]').exists()).toBe(false);
  });
});
