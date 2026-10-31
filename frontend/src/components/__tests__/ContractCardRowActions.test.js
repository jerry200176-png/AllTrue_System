// Billing UX F-5a1: the old table's 改成不收 / 撤銷收款 / 結案 live on the drawer's contract card. The card only
// offers and emits; the page answers with its existing dialogs (same requests, reasons and audit fields).
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: async () => ({ ok: true, status: 200, json: async () => ({ sessions: [] }) }), getAccessToken: async () => 'tok' }));
import ContractCard from '../tuition/ContractCard.vue';

const card = (rowActions) => mount(ContractCard, { props: { course: { id: 9, subject: '數學' }, rowActions } });
const page = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('ContractCard row actions (F-5a1)', () => {
  it('offers no money action without rowActions', async () => {
    const w = card(null);
    await flushPromises();
    for (const id of ['waive', 'void', 'settle']) expect(w.find(`[data-testid="contract-${id}"]`).exists()).toBe(false);
  });

  it('shows only the allowed actions and emits the kind', async () => {
    const w = card({ waive: true, void: false, settle: true });
    await flushPromises();
    expect(w.find('[data-testid="contract-void"]').exists()).toBe(false);
    await w.get('[data-testid="contract-waive"]').trigger('click');
    await w.get('[data-testid="contract-settle"]').trigger('click');
    expect(w.emitted('row-action')).toEqual([['waive'], ['settle']]);
  });
});

describe('page wiring (F-5a1)', () => {
  it('derives the drawer actions from the old table rules and reuses the existing dialogs', () => {
    expect(page).toContain("waive: canWaive.value && r.payment_status === 'pending_reconciliation' && Boolean(r.closed_reason)");
    expect(page).toContain('void: canVoid.value && paidGroup && hasVoidableReceipt(r)');
    expect(page).toContain("settle: r.payment_status === 'renew_needed' || r.payment_status === 'monthly_due_soon'");
    expect(page).toContain("if (kind === 'waive') openWaiveDialog(row);");
    expect(page).toContain("else if (kind === 'void') openVoidDialog(row);");
    expect(page).toContain("else if (kind === 'settle') openSettleDialog(row);");
    expect(page).toContain('@row-action="onLedgerRowAction"');
  });
});
