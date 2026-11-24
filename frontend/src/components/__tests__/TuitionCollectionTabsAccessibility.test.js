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
    expect(source).toContain(':aria-selected="activeTopTab === tab.key"');
    expect(source).toContain("{ key: 'monthly', label: '月結'");
  });

  it('renders only the selected accounting view and keeps its relationship explicit', () => {
    expect(source).not.toContain("activeAccountingTab === 'receivables'"); // F-5: the queue table is gone
    expect(source).toContain('v-if="activeAccountingTab === \'payments\'"');
    expect(source).toContain('id="tuition-accounting-panel-students"');
    expect(source).toContain('id="tuition-accounting-panel-monthly"');
    expect(source).toContain('<TabsList aria-label="帳單與收款檢視"');
    expect(source).toContain('id="tuition-accounting-panel-settled"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-students"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-settled"');
    expect(source).toContain('tabindex="0"');
  });
});
