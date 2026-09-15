import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/BindingHealthDashboard.vue'), 'utf8');

describe('BindingHealthDashboard responsive accessibility', () => {
  it('uses shared primitives for page, recovery, and empty states', () => {
    expect(source).toContain('<AtSkeleton v-if="loading"');
    expect(source).toContain('<AtInlineAlert tone="danger"');
    expect(source).toContain('<AtEmpty');
    expect(source).toContain('const hasMetricsData = computed');
    expect(source).toContain('v-else-if="!hasMetricsData"');
  });

  it('uses accessible shared toggle buttons for trend granularity', () => {
    expect(source).toContain('<AtButton');
    expect(source).toContain(':aria-pressed="granularity === g ? \'true\' : \'false\'"');
    expect(source).toContain(":variant=\"granularity === g ? 'secondary' : 'ghost'\"");
    expect(source).toContain('.bhd-gran :deep(.at-btn) { min-width: 44px; min-height: 44px; }');
    expect(source).not.toContain('<button');
  });

  it('keeps the metrics screen read-only', () => {
    expect(source).toContain('fetchBindingMetrics({ campusId: props.branchId || undefined })');
    expect(source).not.toContain('method="POST"');
    expect(source).not.toContain('method="PUT"');
    expect(source).not.toContain('method="DELETE"');
  });
});
