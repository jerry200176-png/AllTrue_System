import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('StudentsList course payment summary', () => {
  it('shows the latest payment details beside the course memo', () => {
    expect(source).toContain('coursePaymentSummary(course)');
    expect(source).toContain('formatPaymentSummary(course.latest_payment_summary)');
    expect(source).toContain('最近繳費：');
    expect(source).toContain('summary.note');
    expect(source).toContain('summary.account_last5');
  });
});
