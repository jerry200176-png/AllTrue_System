// #3894 — cross-stack contract: backend/tests/Fixtures/slip_receipt_states_3894.json = the API fields the buttons read (PHPUnit
// SlipReceiptStateContractTest proves the REAL backend returns them) + the outcome of the REAL billingDocState rule (and the bill ⋯ menu). Regenerate: UPDATE_SLIP_FIXTURE=1.
import { describe, it, expect } from 'vitest';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { slipAvailability, receiptAvailability } from '../../lib/billingDocState.js';
import { billMenuGroups } from '../../lib/billRows.js';
import { isLegacyPaidNoInvoice } from '../../lib/legacyPaid.js';
import { isDirectorRole } from '../../lib/roleCapabilities.js';

const FILE = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/tests/Fixtures/slip_receipt_states_3894.json');
const fixture = JSON.parse(readFileSync(FILE, 'utf8'));

function outcome(state) {
  const row = { id: 1, ...state.row };
  const canView = isDirectorRole(state.role);
  const slip = slipAvailability(row, canView);
  const receipt = receiptAvailability(row, canView);
  // The live bill ⋯ menu must agree with the rule on 傳繳費單.
  const item = billMenuGroups({ class_id: 1, src: row, kind: 'owing' }, { canDocs: canView })[0].items.find((i) => i.id === 'slip');
  expect(!item.disabled, 'bill menu slip item').toBe(slip.ok);
  return {
    slip: { enabled: slip.ok, reason: slip.ok ? null : slip.reason },
    receipt: { enabled: receipt.ok, reason: receipt.ok ? null : receipt.reason },
    ledger_link: isLegacyPaidNoInvoice(row) && canView && row.id != null, // the "看繳費明細" link of the retired button pair
  };
}

describe('slip/receipt state contract (fixture shared with PHPUnit)', () => {
  it('component outcome per state matches the fixture', () => {
    const computed = Object.fromEntries(Object.entries(fixture.states).map(([k, s]) => [k, outcome(s)]));
    if (process.env.UPDATE_SLIP_FIXTURE === '1') {
      for (const [k, v] of Object.entries(computed)) fixture.states[k].expect = v;
      writeFileSync(FILE, `${JSON.stringify(fixture)}\n`); // one line keeps the PR under the 700-line limit
    }
    const expected = Object.fromEntries(Object.entries(fixture.states).map(([k, s]) => [k, s.expect]));
    expect(computed).toEqual(expected);
  });
  it('spot-checks the meaning (not just the file)', () => {
    const o = (k) => outcome(fixture.states[k]);
    expect(o('paid_with_invoice').receipt.enabled).toBe(true);
    // P1: 沒有帳單不代表沒有已確認收據（receipt_without_invoice）；收據一律交給既有查詢決定，不在列上猜
    for (const k of ['legacy_paid_no_invoice', 'paid_confirmed_report_no_invoice']) expect(o(k)).toMatchObject({ ledger_link: true, slip: { enabled: false }, receipt: { enabled: true } });
    expect(o('no_permission').slip).toEqual({ enabled: false, reason: '沒有權限' });
    // #3942: a confirmed partial payment opens its receipt; a partial bill with no confirmed report still does not
    expect([o('partial_confirmed').receipt.enabled, o('partial').receipt.enabled]).toEqual([true, false]);
  });
});
