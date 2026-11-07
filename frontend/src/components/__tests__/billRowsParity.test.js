import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, it } from 'vitest';
import { billDueText, billRows } from '../../lib/billRows.js';

// The PHP twin (App\Services\Billing\BillRowText) runs the same file in BillExportTest.
const fx = JSON.parse(readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/tests/fixtures/bill_rows_parity.json'), 'utf8'));

it('billRows gives the cells the export fixtures expect', () => {
  const out = billRows(fx.cases.map((c) => c.row), fx.today);
  for (const c of fx.cases) {
    const b = out.find((x) => x.class_id === c.row.id);
    expect({ kind: b.kind, status: b.label, period: b.period, due: billDueText(b), owed: b.owed }).toEqual(c.expect);
  }
});
