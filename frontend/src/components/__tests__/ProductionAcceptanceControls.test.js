import { describe, expect, it } from 'vitest';
import { assertDirectorDashboardTelemetry } from '../../../e2e/fixtures/directorDashboardTelemetry.js';
import { assertTutoringReceivableControls } from '../../../e2e/fixtures/tutoringReceivableControls.js';

const stamp = { telem_day: '2026-10-01', telem_session: 't_test' };

describe('production acceptance controls', () => {
  it('accepts the known director trust event without loosening its schema', () => {
    const payload = {
      branch_id: 15,
      event: 'director_trust_score_shown',
      meta: { ...stamp, critical_count: 0, warning_count: 1, decision_count: 1, decision_keys: ['calendar'], score: 80, status: 'yellow' },
    };
    expect(() => assertDirectorDashboardTelemetry(payload)).not.toThrow();
    expect(() => assertDirectorDashboardTelemetry({ ...payload, meta: { ...payload.meta, student_name: 'leak' } })).toThrow();
    expect(() => assertDirectorDashboardTelemetry({ ...payload, event: 'unknown_event' })).toThrow();
  });

  it('records an absent tutoring sample while still checking a real unpaid control', () => {
    const coverage = assertTutoringReceivableControls({
      branchId: 15, tutoringIds: [], regularIds: ['regular-1'], alertIds: new Set(['regular-1']),
      agingTotal: 3000, regularOutstanding: 3000, tutoringOutstanding: 0,
    });
    expect(coverage).toEqual({ branch_id: 15, active_tutoring_count: 0, regular_unpaid_count: 1, live_tutoring_exclusion_checked: false });
    expect(() => assertTutoringReceivableControls({
      branchId: 15, tutoringIds: [], regularIds: [], alertIds: new Set(),
      agingTotal: 0, regularOutstanding: 0, tutoringOutstanding: 0,
    })).toThrow();
  });

  it('rejects tutoring in alerts or AR when a live sample exists', () => {
    const control = {
      branchId: 15, tutoringIds: ['tutoring-1'], regularIds: ['regular-1'], alertIds: new Set(['regular-1']),
      agingTotal: 3000, regularOutstanding: 3000, tutoringOutstanding: 1000,
    };
    expect(assertTutoringReceivableControls(control).live_tutoring_exclusion_checked).toBe(true);
    expect(() => assertTutoringReceivableControls({ ...control, alertIds: new Set(['regular-1', 'tutoring-1']) })).toThrow();
    expect(() => assertTutoringReceivableControls({ ...control, agingTotal: 4000 })).toThrow();
  });
});
