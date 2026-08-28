import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('TuitionCollectionPage top-level tabs', () => {
  it('connects each accounting tab to its controlled panel', () => {
    expect(source).toContain(':id="`tuition-accounting-tab-${tab.key}`"');
    expect(source).toContain(':aria-controls="`tuition-accounting-panel-${tab.key}`"');
    expect(source).toContain(':aria-selected="activeAccountingTab === tab.key"');
  });

  it('renders only the selected accounting view and keeps its relationship explicit', () => {
    expect(source).toContain('v-if="activeAccountingTab === \'receivables\'"');
    expect(source).toContain('v-else-if="activeAccountingTab === \'payments\'"');
    expect(source).toContain('id="tuition-accounting-panel-receivables"');
    expect(source).toContain('id="tuition-accounting-panel-payments"');
    expect(source).toContain('id="tuition-accounting-panel-settled"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-receivables"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-payments"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-settled"');
    expect(source).toContain('tabindex="0"');
  });
});
