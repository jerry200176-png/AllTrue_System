/**
 * Shared date → session expand for UniversalClassScheduler.
 * Summary counts and submit session_plan MUST use the same expand path
 * so「補登已上（堂）」never drifts from kind=confirmed rows / ClassSession rows.
 */

export function normalizeHalfHourTime(raw) {
  const [hRaw, mRaw] = String(raw || '00:00').split(':');
  const hour = Math.max(0, Math.min(23, Number(hRaw) || 0));
  const minute = Number(mRaw) || 0;
  const rounded = minute < 15 ? 0 : (minute < 45 ? 30 : 0);
  const carryHour = minute >= 45 ? 1 : 0;
  const finalHour = (hour + carryHour) % 24;
  return `${String(finalHour).padStart(2, '0')}:${String(rounded).padStart(2, '0')}`;
}

export function weekdayOneToSevenFromYmd(ymd) {
  const d = new Date(`${String(ymd).slice(0, 10)}T12:00:00`);
  if (Number.isNaN(d.getTime())) return 0;
  const jsDay = d.getDay();
  return jsDay === 0 ? 7 : jsDay;
}

/**
 * Expand one calendar date into the same slot rows submit builds for session_plan.
 * - If day_time_slots has entries for that weekday → one row per slot (同日多堂)
 * - Else → single fallback row with defaultStartTime（手動日不限固定星期）
 *
 * @param {string} ymd
 * @param {object} ctx
 * @param {Array<{day?:number,start_time?:string,subject?:string,teacher_id?:number|string}>} ctx.dayTimeSlots
 * @param {string} [ctx.defaultStartTime]
 * @param {string} [ctx.defaultSubject]
 * @returns {Array<{session_date:string,start_time:string,subject:string,teacher_id?:number}>}
 */
export function expandYmdToSlotEntries(ymd, ctx = {}) {
  const date = String(ymd || '').slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return [];

  const dayTimeSlots = Array.isArray(ctx.dayTimeSlots) ? ctx.dayTimeSlots : [];
  const defaultSubject = String(ctx.defaultSubject || '');
  const defaultStartTime = normalizeHalfHourTime(ctx.defaultStartTime || '16:00');
  const dow = weekdayOneToSevenFromYmd(date);

  const indices = [];
  for (let i = 0; i < dayTimeSlots.length; i += 1) {
    if (Number(dayTimeSlots[i]?.day || 0) === dow) indices.push(i);
  }

  if (indices.length > 0) {
    return indices.map((idx) => {
      const s = dayTimeSlots[idx] || {};
      const entry = {
        session_date: date,
        start_time: normalizeHalfHourTime(s.start_time || defaultStartTime),
        subject: String(s.subject || defaultSubject),
      };
      if (s.teacher_id) entry.teacher_id = Number(s.teacher_id);
      return entry;
    });
  }

  return [{
    session_date: date,
    start_time: defaultStartTime,
    subject: defaultSubject,
  }];
}

export function sessionCountForYmd(ymd, ctx = {}) {
  return expandYmdToSlotEntries(ymd, ctx).length;
}

export function countSessionsForDates(dates, ctx = {}) {
  const list = Array.isArray(dates) ? dates : [];
  return list.reduce((sum, ymd) => sum + sessionCountForYmd(ymd, ctx), 0);
}

/**
 * Build session_plan rows for manually picked dates（confirmed or future）.
 * @param {string[]} dates
 * @param {(ymd:string)=>boolean} isConfirmedFn
 * @param {object} ctx expand context
 * @returns {Array<{session_date:string,start_time:string,kind:'confirmed'|'future',subject:string,teacher_id?:number}>}
 */
export function expandManualDatesToSessionPlan(dates, isConfirmedFn, ctx = {}) {
  const out = [];
  for (const ymd of (Array.isArray(dates) ? dates : [])) {
    const kind = typeof isConfirmedFn === 'function' && isConfirmedFn(ymd) ? 'confirmed' : 'future';
    for (const slot of expandYmdToSlotEntries(ymd, ctx)) {
      const entry = {
        session_date: slot.session_date,
        start_time: slot.start_time,
        kind,
        subject: slot.subject,
      };
      if (slot.teacher_id) entry.teacher_id = slot.teacher_id;
      out.push(entry);
    }
  }
  return out;
}

/** C-lite display:「5 堂（3 天）」 */
export function formatSessionsWithDays(sessionCount, dayCount) {
  const sessions = Math.max(0, Number(sessionCount) || 0);
  const days = Math.max(0, Number(dayCount) || 0);
  return `${sessions} 堂（${days} 天）`;
}
