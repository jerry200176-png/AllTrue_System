import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/DirectorDashboard.vue'), 'utf8');

describe('DirectorDashboard bug SLA summary', () => {
  it('renders the redacted status and aging summary from weekly metrics', () => {
    expect(source).toContain('bugSlaSummary');
    expect(source).toContain('Bug 回報 SLA');
    expect(source).toContain('open_backlog.by_status.new');
    expect(source).toContain('open_backlog.by_status.triaged');
    expect(source).toContain('open_backlog.by_status.in_progress');
    expect(source).toContain('triage_sla.open_breach_total');
  });

  it('shows the explicit P1/P2 targets and missing-history warning', () => {
    expect(source).toContain('triage_sla.targets_hours.p1');
    expect(source).toContain('triage_sla.targets_hours.p2');
    expect(source).toContain('missing_triaged_at');
    expect(source).toContain('年齡未用推算值取代');
  });

  it('keeps the card read-only with no status mutation or resolve action', () => {
    const card = source.slice(source.indexOf('director-bug-sla-panel'), source.indexOf('director-bug-sla-panel') + 2600);
    expect(card).not.toContain('updateBugStatus');
    expect(card).not.toContain('reporterVerifyBug');
  });
});
