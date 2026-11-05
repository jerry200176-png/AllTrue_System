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
    // v3: 帳單 / 月結 / 收款紀錄 / 代收代付 are the four tabs; the selected one is the active one.
    expect(source).toContain(':aria-selected="activeAccountingTab === tab.key"');
    expect(source).toContain("{ key: 'monthly', label: '月結'");
  });

  it('renders only the selected accounting view and keeps its relationship explicit', () => {
    expect(source).not.toContain("activeAccountingTab === 'receivables'"); // F-5: the queue table is gone
    expect(source).toContain('v-if="activeAccountingTab === \'payments\'"');
    expect(source).toContain('id="tuition-accounting-panel-students"');
    expect(source).toContain('id="tuition-accounting-panel-monthly"');
    expect(source).toContain('id="tuition-accounting-panel-payments"');
    expect(source).not.toContain('tuition-accounting-panel-settled'); // 已結清 is the 已收 filter of 帳單
    expect(source).not.toContain('tc-reminder');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-students"');
    expect(source).toContain('aria-labelledby="tuition-accounting-tab-payments"');
    expect(source).toContain('tabindex="0"');
  });
});
