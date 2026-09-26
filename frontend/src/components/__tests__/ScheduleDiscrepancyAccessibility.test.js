import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/ScheduleDiscrepancyPage.vue'), 'utf8');

describe('ScheduleDiscrepancyPage accessibility states', () => {
  it('announces loading progress politely', () => {
    expect(source).toContain('class="sdp-state sdp-state-loading" role="status" aria-live="polite"');
  });

  it('announces load failures as assertive alerts', () => {
    expect(source).toContain('class="sdp-state sdp-state-error" role="alert" aria-live="assertive"');
  });

  it('identifies an empty report list as a status update', () => {
    expect(source).toContain('class="sdp-state sdp-state-empty" role="status"');
  });

  it('does not alter the existing API or action handlers', () => {
    expect(source).toContain('@click="refresh"');
    expect(source).toContain('fetchDiscrepancies');
    expect(source).toContain('@click="acknowledge(row)"');
    expect(source).toContain('@click="resolve(row)"');
  });
});
