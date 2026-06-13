import assert from 'node:assert/strict';
import { computePackageNextTotal, packageMemberSessionSummary } from './packageSessions.js';

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

// #806: shared-package member must read off the SHARED pool, never additive.
// 周宏謙 pkg#115: total 12 / used 4 / remaining 8; two members each SessionCount=12.
{
  const math = { PackageID: 115, SessionCount: 12, package_total_sessions: 12, package_remaining_sessions: 8 };
  const r = packageMemberSessionSummary(math, { completed: 1 });
  assert.equal(r.isPackage, true);
  assert.equal(r.total, 12, 'pool total');
  assert.equal(r.used, 4, 'used = total - remaining (pool), not the member-local 0');
  assert.equal(r.remaining, 8, 'pool remaining');
  assert.match(r.text, /方案共用 12 堂（已用 4 \/ 剩 8）/);
  assert.doesNotMatch(r.text, /購買 12/, 'must not present per-subject 購買 (additive illusion)');
}

// both subjects render the SAME pool numbers — adding them up is meaningless.
{
  const physics = { PackageID: 115, SessionCount: 12, package_total_sessions: 12, package_remaining_sessions: 8 };
  const r = packageMemberSessionSummary(physics, { completed: 3 });
  assert.match(r.text, /本科已上 3 堂｜方案共用 12 堂（已用 4 \/ 剩 8）/);
}

// non-package session course keeps the per-course 購買 framing (unchanged).
{
  const solo = { SessionCount: 8, sessions_purchased: 8 };
  const r = packageMemberSessionSummary(solo, { completed: 2 });
  assert.equal(r.isPackage, false);
  assert.equal(r.text, '已上 2 / 購買 8 堂');
}

// cancelled suffix preserved for both modes.
{
  const pkg = packageMemberSessionSummary(
    { PackageID: 9, package_total_sessions: 10, package_remaining_sessions: 6 },
    { completed: 2, cancelled: 1 },
  );
  assert.match(pkg.text, /，1 堂已取消$/);
  const solo = packageMemberSessionSummary({ SessionCount: 8 }, { completed: 2, cancelled: 2 });
  assert.equal(solo.text, '已上 2 / 購買 8 堂，2 堂已取消');
}

console.log('packageSessions.test.js OK');
