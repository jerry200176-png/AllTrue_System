import { describe, expect, it } from 'vitest';
import { assertDirectorDashboardTelemetry } from '../../../e2e/fixtures/directorDashboardTelemetry.js';
import { assertTutoringReceivableControls, invoiceReceivableControls } from '../../../e2e/fixtures/tutoringReceivableControls.js';

const stamp = { telem_day: '2026-10-01', telem_session: 't_test' };

describe('production acceptance controls', () => {
  it('counts invoice debt across stopped and package courses without inventing unbilled AR', () => {
    const row = (id, status, source, outstanding, extra = {}) => ({
      ID: id, CampusID: 15, ClassType: 'one_on_one', Stop: 0, Charge: 99999, Pay: 0,
      billing_verdict: { status, source, outstanding, ar_candidate: true }, ...extra,
    });
    const courses = [
      row(1, 'unpaid', 'invoice', 3000, { Stop: 1 }),
      row(2, 'partial', 'invoice', 1200, { PackageID: 7 }),
      row(3, 'unbilled', 'none', 99999),
      row(4, 'unpaid', 'invoice', 500, { closed_reason: 'waived' }),
      row(5, 'free', 'none', 0, { ClassType: ' TUTORING ' }),
      row(6, 'paid', 'invoice', 0),
      row(7, 'review_required', 'invoice', 600),
      row(8, 'paid', 'legacy_flag', 0),
      row(9, 'partial', 'invoice', 6000, { PackageID: 8, billing_verdict: { status: 'partial', source: 'invoice', outstanding: 6000, ar_candidate: false } }),
    ];
    expect(invoiceReceivableControls(courses, 15)).toEqual({ regularIds: ['1', '2'], tutoringIds: ['5'], regularOutstanding: 4200 });
    const control = invoiceReceivableControls(courses, 15);
    expect(() => assertTutoringReceivableControls({ branchId: 15, ...control,
      alertIds: new Set(['1', '2']), agingTotal: 4200, tutoringOutstanding: 500 })).not.toThrow();
    expect(() => assertTutoringReceivableControls({ branchId: 15, ...control,
      alertIds: new Set(['1', '2']), agingTotal: 4700, tutoringOutstanding: 500 })).toThrow();
  });

  it('fails closed on missing, duplicated, cross-campus or malformed verdict evidence', () => {
    const valid = { ID: 1, CampusID: 15, billing_verdict: { status: 'unpaid', source: 'invoice', outstanding: 100, ar_candidate: true } };
    for (const rows of [[{ ...valid, billing_verdict: undefined }], [valid, valid],
      [{ ...valid, CampusID: 16 }], [{ ...valid, ID: '' }],
      ...[null, -1, NaN, Infinity, '100', 0.5].map((outstanding) => [{ ...valid, billing_verdict: { ...valid.billing_verdict, outstanding } }]),
      [{ ...valid, billing_verdict: { ...valid.billing_verdict, status: 'unknown' } }],
      [{ ...valid, billing_verdict: { ...valid.billing_verdict, ar_candidate: undefined } }]]) {
      expect(() => invoiceReceivableControls(rows, 15)).toThrow();
    }
  });

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
    expect(coverage).toEqual({ branch_id: 15, observed_tutoring_count: 0, regular_unpaid_count: 1, live_tutoring_exclusion_checked: false });
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
