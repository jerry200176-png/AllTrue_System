/**
 * schedulingCommands — typed named domain commands for scheduling writes (ADR-005).
 *
 * Surfaces (SmartCalendar / CourseManagement) must call these helpers instead of
 * assembling restore payloads with teacher ids.
 */

function token() {
  try {
    const s = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    return s?.access_token || '';
  } catch (e) {
    return '';
  }
}

async function call(method, path, payload) {
  const t = token();
  if (!t) throw new Error('尚未登入，請重新登入後再試');
  const opts = {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${t}`,
    },
  };
  if (payload !== undefined && payload !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(payload);
  }
  const res = await fetch(path, opts);
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(json?.message || res.statusText || '操作失敗');
    err.status = res.status;
    err.payload = json;
    err.code = json?.code || null;
    throw err;
  }
  return json;
}

/**
 * RestoreContractTeacher — session_id only; optional reason.
 * Must not send teacher_id / contract_teacher_id / effective_teacher_id.
 *
 * @param {number|string} sessionId
 * @param {{ reason?: string|null }} [options]
 */
export function restoreContractTeacher(sessionId, options = {}) {
  const body = {};
  if (options.reason != null && String(options.reason).trim() !== '') {
    body.reason = String(options.reason).trim();
  }
  return call(
    'POST',
    `/api/v1/class-sessions/${encodeURIComponent(sessionId)}/restore-contract-teacher`,
    body,
  );
}

export const schedulingCommands = {
  restoreContractTeacher,
};

export default schedulingCommands;
