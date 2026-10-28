import { fetchClassSessions } from './classSessionsApi.js';
import { normalizeTimeTo30 } from './calendarFormat.js';

const headers = token => ({ Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) });
async function post(path, payload, { token, request = fetch }) {
  const res = await request(`/api/v1/schedules/leave-successor-shift/${path}`, {
    method: 'POST', credentials: 'include', headers: headers(token), body: JSON.stringify(payload),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.message || '無法確認續約順延，請重新預覽');
  return body;
}
const slotValid = slot => slot && /^\d{4}-\d{2}-\d{2}$/.test(slot.date) && /^\d{2}:\d{2}(?::\d{2})?$/.test(slot.start) && /^\d{2}:\d{2}(?::\d{2})?$/.test(slot.end);
function validPlan(plan, sessionId) {
  return plan?.applicable === true && Number(plan.leave_session?.id) === Number(sessionId)
    && typeof plan.apply_enabled === 'boolean'
    && /^[a-f0-9]{64}$/.test(plan.preview_token) && typeof plan.eligible === 'boolean'
    && Array.isArray(plan.blockers) && plan.eligible === (plan.blockers.length === 0)
    && Array.isArray(plan.moves) && plan.moves.length > 1
    && plan.moves.every((move, index) => slotValid(move.to) && (index === 0
      ? move.role === 'old_tail' && move.session_id === null && move.from === null
      : move.role === 'successor' && Number(move.session_id) > 0 && slotValid(move.from)))
    && /^\d{4}-\d{2}-\d{2}$/.test(plan.old_end_date) && /^\d{4}-\d{2}-\d{2}$/.test(plan.successor_end_date);
}

/** Read-only resolution: never materialize a projected occurrence or guess by date alone. */
export async function previewSuccessorLeave({ role, sessionId, courseId, date, start, branchId, token, request, resolve = fetchClassSessions }) {
  if (!['director', 'super_admin'].includes(role)) return null;
  if (!Number(sessionId)) {
    const result = await resolve({ token, branchId, studentClassId: Number(courseId), start: date, end: date });
    const hhmm = String(start || '').slice(0, 5);
    const sameDay = result.items.filter(row => !row.isProjected && row.id && row.studentClassId === Number(courseId) && row.date === date);
    let matches = sameDay.filter(row => row.startTime === hhmm);
    // The calendar modal rounds a 17:15 lesson to 17:30; match the rounded cell, still exactly one.
    if (!matches.length && hhmm) matches = sameDay.filter(row => normalizeTimeTo30(row.startTime) === hhmm);
    if (matches.length !== 1) throw new Error('無法確認唯一堂次，請重新整理課表後再試');
    sessionId = matches[0].id;
  }
  const plan = await post('preview', { class_session_id: Number(sessionId) }, { token, request });
  if (plan.applicable === false) return null;
  if (!validPlan(plan, sessionId)) throw new Error('續約順延預覽資料不完整，請重新取得預覽');
  if (!plan.apply_enabled) {
    return { ...plan, eligible: false, blockers: [...plan.blockers, { type: 'feature_unavailable', message: '續約順延目前尚未開放，這次沒有變更課程。' }] };
  }
  return plan;
}

export function successorImpactItems(plan) {
  return [
    '本堂請假不扣堂數；以下續約堂次將順延，已上課堂次不變',
    ...plan.moves.map(move => move.role === 'old_tail'
      ? `原課程新增補堂：${move.to.date} ${move.to.start}–${move.to.end}`
      : `續約堂次：${move.from.date} ${move.from.start}–${move.from.end} → ${move.to.date} ${move.to.start}–${move.to.end}`),
    `原課程結束日：${plan.old_end_date}；續約結束日：${plan.successor_end_date}`,
    '續約順延仍有效時，無法撤銷這次請假',
    ...plan.blockers.map(blocker => `${blocker.message}${blocker.date ? `（${blocker.date} ${blocker.start || ''}）` : ''}`),
  ];
}

export async function applySuccessorLeave(plan, options = {}) {
  if (!validPlan(plan, plan?.leave_session?.id) || !plan.eligible || !plan.apply_enabled) throw new Error('此預覽不可送出，請先處理阻擋原因並重新預覽');
  const result = await post('apply', { class_session_id: plan.leave_session.id, preview_token: plan.preview_token }, options);
  if (result.applied !== true && result.already_applied !== true) throw new Error('未確認請假完成，請重新整理課表');
  return result;
}
