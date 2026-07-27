/** Typed scheduling write commands (ADR-005). */

function token() {
  try {
    return JSON.parse(localStorage.getItem('alltrue_session') || '{}')?.access_token || '';
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
    headers: { Accept: 'application/json', Authorization: `Bearer ${t}` },
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

/** RestoreContractTeacher — session id only; optional reason. No teacher identity. */
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

export const schedulingCommands = { restoreContractTeacher };
export default schedulingCommands;
