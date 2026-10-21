// in-app #409: when 課程設定 is blocked for a count-mode course partway through, the
// backend adds `use_transfer` + `transfer_hint` ({ start_date, changes, not_carried })
// to the 422. Turn that, plus the values the director just typed, into a 轉課 prefill.
const normTime = (t) => (t ? String(t).slice(0, 5) : '');
const NOT_CARRIED_LABELS = { SessionCount: '購買堂數', standard_lesson_minutes: '標準堂長', deduction_basis: '扣堂方式' };

function editedSlots(f) {
  const fromSlots = (f.day_time_slots || [])
    .filter((s) => Number(s?.day) >= 1 && Number(s?.day) <= 7)
    .map((s) => ({ day: Number(s.day), start: s.start_time, hours: Number(s.duration_hours || f.duration_hours || 0) }));
  const list = fromSlots.length ? fromSlots : (f.days_of_week || [])
    .map(Number).filter((d) => d >= 1 && d <= 7)
    .map((d) => ({ day: d, start: f.start_time, hours: Number(f.duration_hours || 0) }));
  return list.map(({ day, start, hours }) => ({
    weekday: day,
    time: normTime(start || f.start_time) || '16:00',
    duration_minutes: hours > 0 ? Math.round(hours * 60) : 120,
  }));
}

export function transferPrefillFromBlockedEdit(err, form) {
  const hint = err?.transfer_hint;
  if (!Array.isArray(err?.suggested_actions) || !err.suggested_actions.includes('use_transfer')) return null;
  if (!hint?.start_date || !Array.isArray(hint.changes) || !hint.changes.length) return null;
  const f = form || {};
  const prefill = { start_date: String(hint.start_date).slice(0, 10) };
  if (hint.changes.includes('subject') && f.subject) prefill.subject = f.subject;
  if (hint.changes.includes('teacher') && f.teacher_id) prefill.teacher_id = f.teacher_id;
  if (hint.changes.includes('slots')) {
    // Carry every weekly slot: 轉課 replaces the whole weekly schedule with this list.
    const slots = editedSlots(f);
    if (slots.length) {
      prefill.slots = slots;
      Object.assign(prefill, { weekday: slots[0].weekday, time: slots[0].time, duration_minutes: slots[0].duration_minutes });
    }
  }
  const notCarried = (Array.isArray(hint.not_carried) ? hint.not_carried : []).map((k) => NOT_CARRIED_LABELS[k] || k);
  if (notCarried.length) prefill.not_carried = notCarried;
  return prefill;
}

export function formatTransferStartDate(date) {
  const [y, m, d] = String(date || '').split('-').map(Number);
  return y && m && d ? `${m}/${d}` : '';
}
