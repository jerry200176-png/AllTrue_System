import { describe, expect, it } from 'vitest';
import { createBranchApiProbe } from '../../../e2e/fixtures/branchApiProbe.js';

const response = (path, status, branchId) => ({
  url: () => `https://app.example.test${path}?branch_id=${branchId}`,
  status: () => status,
});

describe('production branch API smoke probe', () => {
  it('retains an initial 403 after a later 200 for the selected campus', () => {
    const probe = createBranchApiProbe('/api/v1/alerts/tuition');
    probe.observe(response('/api/v1/alerts/tuition', 403, 17));
    probe.observe(response('/api/v1/alerts/tuition', 200, 9));
    expect(probe.hasSuccessFor(9)).toBe(true);
    expect(probe.unauthorizedStatuses()).toEqual([403]);
  });

  it('does not count an authorized campus other than the selected campus as ready', () => {
    const probe = createBranchApiProbe('/api/v1/rooms');
    probe.observe(response('/api/v1/rooms', 200, 17));
    probe.observe(response('/api/v1/me', 403, 9));
    expect(probe.hasSuccessFor(9)).toBe(false);
    expect(probe.unauthorizedStatuses()).toEqual([]);
    probe.observe(response('/api/v1/rooms', 401, 9));
    expect(probe.unauthorizedStatuses()).toEqual([401]);
  });

  it('tracks in-flight requests until they finish', () => {
    const probe = createBranchApiProbe('/api/v1/rooms');
    const handlers = {};
    probe.attach({ on: (name, fn) => { handlers[name] = fn; } });
    const request = { url: () => 'https://app.example.test/api/v1/rooms?branch_id=9' };
    handlers.request(request);
    expect(probe.pendingCount()).toBe(1);
    handlers.requestfinished(request);
    expect(probe.pendingCount()).toBe(0);
  });
});
