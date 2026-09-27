import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition collection remaining-session sort (in-app #309)', () => {
  it('exposes remaining_sessions as a sortable column and mobile option', () => {
    expect(source).toContain("toggleSort('remaining_sessions')");
    expect(source).toContain("{ key: 'remaining_sessions', label: '剩餘堂數' }");
    expect(source).toContain('剩餘堂數');
  });

  it('treats remaining_sessions as a numeric sort key', () => {
    expect(source).toContain("const numericKeys = ['charge', 'outstanding', 'remaining_sessions']");
  });
});
