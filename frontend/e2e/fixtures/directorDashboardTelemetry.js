import { expect } from '@playwright/test';

const schemas = {
  dashboard_opened: ['page', 'role', 'telem_day', 'telem_session'],
  director_trust_decision_impression: ['has_drilldown', 'key', 'people_total', 'severity', 'target', 'telem_day', 'telem_session', 'viewport'],
  director_trust_decision_click: ['from', 'has_drilldown', 'key', 'people_shown', 'severity', 'target', 'telem_day', 'telem_session'],
  director_trust_score_shown: ['critical_count', 'decision_count', 'decision_keys', 'score', 'status', 'telem_day', 'telem_session', 'warning_count'],
};

export function isDirectorDashboardEvent(event) {
  return Object.hasOwn(schemas, event);
}

export function assertDirectorDashboardTelemetry(payload) {
  const event = payload?.event;
  expect(isDirectorDashboardEvent(event), `unexpected adoption event: ${event}`).toBe(true);
  expect(Object.keys(payload).sort()).toEqual(['branch_id', 'event', 'meta']);
  expect(Number.isInteger(payload.branch_id)).toBe(true);
  expect(payload.branch_id).toBeGreaterThan(0);
  const meta = payload.meta;
  expect(meta && typeof meta === 'object' && !Array.isArray(meta)).toBe(true);
  expect(Object.keys(meta).sort()).toEqual([...schemas[event]].sort());
  expect(meta.telem_session).toMatch(/^t_[a-z0-9_]+$/);
  expect(meta.telem_day).toMatch(/^\d{4}-\d{2}-\d{2}$/);

  if (event === 'dashboard_opened') {
    expect(meta.page).toBe('director-dashboard');
    expect(meta.role).toBe('director');
  }
  if (event === 'director_trust_decision_impression' || event === 'director_trust_decision_click') {
    expect(meta.key).toMatch(/^[a-z0-9_-]{1,80}$/i);
    expect(['critical', 'warning', '']).toContain(meta.severity);
    expect(['calendar', 'duplicate-review', 'course-mgmt', 'tuition', '']).toContain(meta.target);
    expect(typeof meta.has_drilldown).toBe('boolean');
    const countKey = event === 'director_trust_decision_impression' ? 'people_total' : 'people_shown';
    expect(Number.isInteger(meta[countKey])).toBe(true);
    expect(meta[countKey]).toBeGreaterThanOrEqual(0);
    expect(meta[countKey]).toBeLessThanOrEqual(100000);
    if (event === 'director_trust_decision_impression') expect(meta.viewport).toBe(1);
    else expect(meta.from).toBe('decision_cta');
  }
  if (event === 'director_trust_score_shown') {
    for (const key of ['critical_count', 'warning_count', 'decision_count']) {
      expect(Number.isInteger(meta[key])).toBe(true);
      expect(meta[key]).toBeGreaterThanOrEqual(0);
      expect(meta[key]).toBeLessThanOrEqual(100000);
    }
    expect(meta.score).toBeGreaterThanOrEqual(0);
    expect(meta.score).toBeLessThanOrEqual(100);
    expect(['red', 'yellow', 'green']).toContain(meta.status);
    expect(Array.isArray(meta.decision_keys)).toBe(true);
    expect(meta.decision_keys).toHaveLength(meta.decision_count);
    for (const key of meta.decision_keys) expect(key).toMatch(/^[a-z0-9_-]{1,80}$/i);
  }
}
