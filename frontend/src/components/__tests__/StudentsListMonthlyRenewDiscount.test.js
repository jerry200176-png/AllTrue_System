import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('StudentsList monthly renewal discount payload', () => {
  it('omits discount when NONE so admin renewals are not rejected by the finance gate', () => {
    const start = source.indexOf('const submitRenewMonthly = async');
    const submit = source.slice(start, source.indexOf('// --- CSV Import ---', start));
    expect(submit).not.toContain('discount: renewMonthlyForm.value.discount }),');
    expect(submit).toContain("renewMonthlyForm.value.discount.type !== 'NONE'");
  });
});
