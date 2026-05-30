import assert from 'node:assert/strict';
import { computePackageNextTotal } from './packageSessions.js';

// add mode: increments the pooled total
{
  const r = computePackageNextTotal({ mode: 'add', currentTotal: 8, value: 4, usedSessions: 7 });
  assert.equal(r.ok, true);
  assert.equal(r.nextTotal, 12);
}

// set mode (#553): sets the absolute pooled total — the operation that was
// previously impossible from the UI (shared-session field was disabled).
{
  const r = computePackageNextTotal({ mode: 'set', currentTotal: 8, value: 10, usedSessions: 7 });
  assert.equal(r.ok, true, 'director can set an absolute shared-package total');
  assert.equal(r.nextTotal, 10);
}

// set mode allows lowering down to (but not below) used sessions
{
  const r = computePackageNextTotal({ mode: 'set', currentTotal: 12, value: 7, usedSessions: 7 });
  assert.equal(r.ok, true);
  assert.equal(r.nextTotal, 7);
}

// set mode below used is rejected (mirrors backend 422 guard)
{
  const r = computePackageNextTotal({ mode: 'set', currentTotal: 12, value: 6, usedSessions: 7 });
  assert.equal(r.ok, false);
  assert.match(r.error, /已使用/);
}

// set mode rejects non-positive / non-integer
{
  assert.equal(computePackageNextTotal({ mode: 'set', currentTotal: 8, value: 0 }).ok, false);
  assert.equal(computePackageNextTotal({ mode: 'set', currentTotal: 8, value: 1.5 }).ok, false);
}

// add mode rejects non-positive
{
  assert.equal(computePackageNextTotal({ mode: 'add', currentTotal: 8, value: 0 }).ok, false);
}

// add mode requires a known current total
{
  const r = computePackageNextTotal({ mode: 'add', currentTotal: 0, value: 4 });
  assert.equal(r.ok, false);
  assert.match(r.error, /方案總堂數/);
}

console.log('packageSessions.test.js OK');
