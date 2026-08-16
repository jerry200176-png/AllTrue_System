import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ledger = readFileSync(resolve(__dirname, '../AccountingLedgerModal.vue'), 'utf8');
const tuition = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('billing scan density — Filament/Carbon-style structure', () => {
  it('ledger uses compact strip, slim columns, and expandable payment timeline', () => {
    expect(ledger).toContain('ledger-strip');
    expect(ledger).toContain('ledger-timeline');
    expect(ledger).toContain('aria-expanded');
    expect(ledger).toContain('收款時間線');
    expect(ledger).not.toContain('>已收款紀錄<');
    expect(ledger).toContain('EXCEPTION_PREVIEW');
    expect(ledger).toContain('chip--success');
    expect(ledger).toContain('chip--danger');
  });

  it('tuition receivables use compact strip and sticky selection bar', () => {
    expect(tuition).toContain('tc-summary--strip');
    expect(tuition).toContain('tc-batch-bar--sticky');
    expect(tuition).not.toContain('勾選最左欄後才會出現');
  });
});
