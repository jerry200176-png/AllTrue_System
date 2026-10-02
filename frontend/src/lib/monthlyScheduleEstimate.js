// A planning estimate for one calendar month; confirmed attendance owns billing.
export function monthlyScheduleEstimate({ startDate, endDate, slots = [], weekdays = [], asOf = new Date() }) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(startDate || '') || !/^\d{4}-\d{2}-\d{2}$/.test(endDate || '') || endDate < startDate) return null;
  const slotDays = slots.map((slot) => Number(slot?.day)).filter((day) => day >= 1 && day <= 7);
  const days = slotDays.length ? slotDays : [...new Set(weekdays.map(Number))].filter((day) => day >= 1 && day <= 7);
  if (!days.length) return null;

  const today = `${asOf.getFullYear()}-${String(asOf.getMonth() + 1).padStart(2, '0')}-${String(asOf.getDate()).padStart(2, '0')}`;
  const reference = today < startDate ? startDate : today > endDate ? endDate : today;
  const month = reference.slice(0, 7);
  const monthStart = `${month}-01`;
  const monthEnd = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0).getDate();
  const from = startDate > monthStart ? startDate : monthStart;
  const to = endDate < `${month}-${monthEnd}` ? endDate : `${month}-${monthEnd}`;
  let count = 0;
  for (let day = Number(from.slice(8, 10)); day <= Number(to.slice(8, 10)); day += 1) {
    const weekday = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1, day).getDay() || 7;
    count += days.filter((slotDay) => slotDay === weekday).length;
  }
  return count > 0 ? { month, count } : null;
}
