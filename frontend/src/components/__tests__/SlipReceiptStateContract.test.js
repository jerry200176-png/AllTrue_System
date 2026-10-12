// #3894 — cross-stack contract: backend/tests/Fixtures/slip_receipt_states_3894.json = the API fields the buttons read (PHPUnit
// SlipReceiptStateContractTest proves the REAL backend returns them) + the outcome of the REAL component. Regenerate: UPDATE_SLIP_FIXTURE=1.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import TuitionDocActions from '../TuitionDocActions.vue';
import { isDirectorRole } from '../../lib/roleCapabilities.js';
import { isLegacyPaidNoInvoice } from '../../lib/legacyPaid.js';

const FILE = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/tests/Fixtures/slip_receipt_states_3894.json');
const fixture = JSON.parse(readFileSync(FILE, 'utf8'));

function outcome(state) {
  const row = { id: 1, ...state.row };
  const w = mount(TuitionDocActions, { props: { row, canView: isDirectorRole(state.role) } });
  const reason = (k) => (w.find(`[data-testid="tc-doc-${k}-reason"]`).exists() ? w.get(`[data-testid="tc-doc-${k}-reason"]`).text() : null);
  return {
    slip: { enabled: !w.get('[data-testid="tc-doc-slip"]').element.disabled, reason: reason('slip') },
    receipt: { enabled: !w.get('[data-testid="tc-doc-receipt"]').element.disabled, reason: reason('receipt') },
    ledger_link: w.find('[data-testid="tc-doc-ledger"]').exists(),
    // 撤銷 is only offered on paid-status rows (page template); legacy rows have nothing to void.
    void_enabled: ['paid', 'renew_needed', 'monthly_due_soon'].includes(row.payment_status) && isDirectorRole(state.role) ? !isLegacyPaidNoInvoice(row) : null,
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
    expect(o('paid_with_invoice')).toMatchObject({ receipt: { enabled: true }, void_enabled: true });
    expect(o('legacy_paid_no_invoice')).toMatchObject({ ledger_link: true, void_enabled: false, slip: { enabled: false }, receipt: { enabled: false } });
    expect(o('no_permission').slip).toEqual({ enabled: false, reason: '沒有權限' });
  });
});
