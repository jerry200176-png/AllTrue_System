import { pickerSlotConflict, timesOverlap } from './slotOccupancy.js';

function toMinutes(value) {
  const match = String(value || '').slice(0, 5).match(/^(\d{2}):(\d{2})$/);
  if (!match) return null;
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return null;
  return hours * 60 + minutes;
}

function toTime(value) {
  const minutes = Math.max(0, Math.min(23 * 60 + 30, Number(value) || 0));
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

// Return the first date on/after startYmd matching ISO weekday (1=Mon).
export function nextOccurrenceDate(startYmd, weekday) {
  const match = String(startYmd || '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const day = Number(weekday);
  if (!match || !Number.isInteger(day) || day < 1 || day > 7) return '';
  const date = new Date(`${match[1]}-${match[2]}-${match[3]}T12:00:00`);
  if (Number.isNaN(date.getTime())) return '';
  const current = date.getDay() || 7;
  date.setDate(date.getDate() + ((day - current + 7) % 7));
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

// Return a bounded recurring horizon without changing the scheduling source of truth.
export function nextOccurrenceDates(startYmd, weekday, count = 4) {
  const total = Number(count);
  let next = nextOccurrenceDate(startYmd, weekday);
  if (!next || !Number.isInteger(total) || total < 1) return [];
  const dates = [];
  for (let index = 0; index < total; index += 1) {
    dates.push(next);
    const date = new Date(`${next}T12:00:00`);
    date.setDate(date.getDate() + 7);
    next = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }
  return dates;
}

// Build half-hour choices; occupancy/capacity stay delegated to the existing picker rule.
export function buildScheduleCandidates({
  date,
  weekday,
  windowStart,
  windowEnd,
  durationMinutes,
  busySlots = [],
  classType = '',
  branchId = 0,
  branchNameMap = {},
} = {}) {
  const start = toMinutes(windowStart);
  const end = toMinutes(windowEnd);
  const duration = Number(durationMinutes);
  if (start === null || end === null || end <= start || !Number.isFinite(duration) || duration < 30) return [];

  const candidates = [];
  for (let startMinutes = start; startMinutes + duration <= end; startMinutes += 30) {
    const startTime = toTime(startMinutes);
    const endTime = toTime(startMinutes + duration);
    const overlapping = (Array.isArray(busySlots) ? busySlots : []).filter((slot) => (
      timesOverlap(startTime, endTime, slot?.start_time, slot?.end_time)
    ));
    const decision = pickerSlotConflict({
      overlappingSlots: overlapping,
      coveredClassType: classType,
      sessionCampusId: branchId,
      branchNameMap,
    });
    candidates.push({
      date,
      weekday: Number(weekday),
      start_time: startTime,
      end_time: endTime,
      status: decision.conflict ? 'conflict' : decision.capacityWarn ? 'capacity' : 'available',
      conflictTooltip: decision.conflictTooltip || '',
      capacityWarn: Boolean(decision.capacityWarn),
    });
  }
  return candidates;
}
export function rankScheduleCandidates(candidates = []) {
  const priority = { available: 0, capacity: 1, conflict: 2 };
  return [...(Array.isArray(candidates) ? candidates : [])].sort((a, b) => (
    (priority[a?.status] ?? 9) - (priority[b?.status] ?? 9)
    || String(a?.date || '').localeCompare(String(b?.date || ''))
    || String(a?.start_time || '').localeCompare(String(b?.start_time || ''))
  ));
}

// Merge the same recurring time across a bounded horizon; a partial conflict stays blocked.
export function mergeRecurringScheduleCandidates(candidatesByDate = []) {
  const groups = Array.isArray(candidatesByDate) ? candidatesByDate : [];
  const first = Array.isArray(groups[0]) ? groups[0] : [];
  return first.map((firstOccurrenceCandidate) => {
    const occurrences = groups.map((candidates) => (
      (Array.isArray(candidates) ? candidates : []).find(
        (candidate) => candidate?.start_time === firstOccurrenceCandidate.start_time,
      )
    ));
    const blocked = occurrences.find((candidate) => !candidate || candidate.status === 'conflict');
    return {
      ...firstOccurrenceCandidate,
      status: blocked
        ? 'conflict'
        : occurrences.some((candidate) => candidate.status === 'capacity') ? 'capacity' : 'available',
      conflictTooltip: blocked?.conflictTooltip || '',
      capacityWarn: occurrences.some((candidate) => candidate?.capacityWarn),
      occurrenceCount: occurrences.filter((candidate) => candidate && candidate.status !== 'conflict').length,
      occurrenceTotal: groups.length,
    };
  });
}
