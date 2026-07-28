/**
 * Plain node assertions mirroring sessionOccurrenceFilter (no vitest required).
 * Canonical tests: src/lib/sessionOccurrenceFilter.test.js
 * Run: node src/composables/course-management/useCourseSessionsDisplay.occurrence.test.js
 */
const INTERNAL = 'cancelled-duplicate-reschedule-placeholder';

function isInternal(row) {
  return String(row?.status || '').toLowerCase() === 'cancelled'
    && String(row?.note || '').includes(INTERNAL);
}

function primaryUnits(rows) {
  return rows.filter((row) => {
    const status = String(row?.status || '').toLowerCase();
    if (status === 'cancelled') return false;
    if (isInternal(row)) return false;
    return true;
  });
}

function occupiesQuota(row) {
  if (row?.isProjected) return false;
  const status = String(row?.status || '').toLowerCase();
  return !['cancelled', 'leave', 'leave_adjusted', 'excused'].includes(status);
}

const primary = primaryUnits([
  { id: 1, status: 'attended', note: '' },
  { id: 2, status: 'cancelled', note: 'manual cancel' },
  { id: 3, status: 'cancelled', note: `x; ${INTERNAL}` },
  { id: 4, status: 'scheduled', note: '' },
]);
if (primary.map((r) => r.id).join(',') !== '1,4') {
  console.error('FAIL primary chips filter', primary.map((r) => r.id));
  process.exit(1);
}

if (occupiesQuota({ status: 'scheduled', isContractException: true }) !== true) {
  console.error('FAIL a rescheduled occurrence must still occupy purchased quota');
  process.exit(1);
}
if (occupiesQuota({ status: 'scheduled', isContractException: false }) !== true) {
  console.error('FAIL normal scheduled should occupy quota');
  process.exit(1);
}

console.log('ok: useCourseSessionsDisplay.occurrence filters');
