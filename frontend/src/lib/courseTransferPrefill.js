// in-app #409: when 課程設定 is blocked for a count-mode course partway through, the
// backend sets the refusal's next step to `course_transfer` (courseEditability.js) and
// adds `transfer_hint` ({ start_date, changes, not_carried }). Turn that, plus the
// values the director just typed, into a 轉課 prefill.
import { editabilityNextStepForError } from './courseEditability.js';

const normTime = (t) => (t ? String(t).slice(0, 5) : '');
const NOT_CARRIED_LABELS = { SessionCount: '購買堂數', standard_lesson_minutes: '標準堂長', deduction_basis: '扣堂方式' };

const FORM_NOT_CARRIED_LABELS = {
  sessions_purchased: '購買堂數', remaining_sessions: '剩餘堂數',
  standard_lesson_minutes: '標準堂長', deduction_basis: '扣堂方式',
  memo: '備註', room_id: '教室', class_type: '班型', rate_per_30min: '單價', rate_unit: '計費單位',
  payment_type: '計費方式', settlement_day: '結算日', monthly_sessions: '月結堂數',
  first_class_date: '開始日期', end_date: '結束日期', scheduling_policy: '排課方式', paid_at: '繳費日期',
};

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

export function transferPrefillFromBlockedEdit(err, form, originalForm = null) {
  const hint = err?.transfer_hint;
  if (editabilityNextStepForError(err) !== 'course_transfer') return null;
  if (!hint?.start_date || !Array.isArray(hint.changes) || !hint.changes.length) return null;
  const f = form || {};
  const prefill = { start_date: String(hint.start_date).slice(0, 10) };
  if (originalForm && editedSlots(originalForm).length > 0 && editedSlots(f).length === 0) {
    return { ...prefill, blocked_reason: '你已移除所有每週時段；轉課無法套用空排課，這些修改尚未儲存。請先確認排課方式。' };
  }
  if (hint.changes.includes('subject') && f.subject) prefill.subject = f.subject;
  if (hint.changes.includes('teacher') && f.teacher_id) prefill.teacher_id = f.teacher_id;
  if (hint.changes.includes('slots')) {
    // Carry every weekly slot: 轉課 replaces the whole weekly schedule with this list.
    const slots = editedSlots(f);
    // validateSplitRequest currently accepts at most six slots; do not advertise an unusable shortcut.
    if (slots.length > 6) {
      return { ...prefill, blocked_reason: '目前轉課最多支援每週 6 個時段；此次 7 個時段的修改尚未儲存，請先聯繫主任處理。' };
    }
    if (slots.length) {
      prefill.slots = slots;
      Object.assign(prefill, { weekday: slots[0].weekday, time: slots[0].time, duration_minutes: slots[0].duration_minutes });
    }
  }
  const notCarried = (Array.isArray(hint.not_carried) ? hint.not_carried : []).map((k) => NOT_CARRIED_LABELS[k] || k);
  // A subject guard may return before locked_fields is populated. The saved client snapshot
  // still tells us exactly which submitted edits this transfer cannot apply.
  if (originalForm) {
    for (const [field, label] of Object.entries(FORM_NOT_CARRIED_LABELS)) {
      if (Object.hasOwn(originalForm, field) && Object.hasOwn(f, field)
        && String(originalForm[field] ?? '') !== String(f[field] ?? '')) notCarried.push(label);
    }
  }

  if (notCarried.length) prefill.not_carried = [...new Set(notCarried)];
  return prefill;
}

export function formatTransferStartDate(date) {
  const [y, m, d] = String(date || '').split('-').map(Number);
  return y && m && d ? `${m}/${d}` : '';
}
