// in-app #409: when 課程設定 is blocked for a count-mode course partway through, the
// backend adds `use_transfer` + `transfer_hint` ({ start_date, changes }) to the 422.
// Turn that, plus the values the director just typed, into a 轉課 prefill.
const normTime = (t) => (t ? String(t).slice(0, 5) : '');

export function transferPrefillFromBlockedEdit(err, form) {
  const hint = err?.transfer_hint;
  if (!Array.isArray(err?.suggested_actions) || !err.suggested_actions.includes('use_transfer')) return null;
  if (!hint?.start_date || !Array.isArray(hint.changes) || !hint.changes.length) return null;
  const f = form || {};
  const prefill = { start_date: String(hint.start_date).slice(0, 10) };
  if (hint.changes.includes('subject') && f.subject) prefill.subject = f.subject;
  if (hint.changes.includes('teacher') && f.teacher_id) prefill.teacher_id = f.teacher_id;
  if (hint.changes.includes('slots')) {
    const slot = (f.day_time_slots || []).find((s) => Number(s?.day) >= 1 && Number(s?.day) <= 7);
    const day = slot ? Number(slot.day) : Number((f.days_of_week || [])[0] || 0);
    if (day >= 1 && day <= 7) {
      const hours = Number(slot?.duration_hours || f.duration_hours || 0);
      prefill.weekday = day;
      prefill.time = normTime(slot?.start_time || f.start_time) || '16:00';
      if (hours > 0) prefill.duration_minutes = Math.round(hours * 60);
    }
  }
  return prefill;
}

export function formatTransferStartDate(date) {
  const [y, m, d] = String(date || '').split('-').map(Number);
  return y && m && d ? `${m}/${d}` : '';
}
