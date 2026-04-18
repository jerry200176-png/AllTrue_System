// substituteApi.js — 前端呼叫 PRD 9c058f19 新增的代課相關端點。
//
// 每個 fetch 都帶 Authorization: Bearer <access_token>，錯誤時 throw Error 讓上層攔截。

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
    throw err;
  }
  return json;
}

export function fetchTeacherAvailability(teacherId, date) {
  const qs = new URLSearchParams({ date: String(date || '') }).toString();
  return call('GET', `/api/v1/teachers/${encodeURIComponent(teacherId)}/availability?${qs}`);
}

export function undoSubstitute(classSessionId) {
  return call('POST', `/api/v1/class-sessions/${encodeURIComponent(classSessionId)}/substitute/undo`);
}

export function previewTeacherLeaves(payload) {
  return call('POST', '/api/v1/teacher-leaves/preview', payload);
}

export function batchSubstitute(payload) {
  return call('POST', '/api/v1/teacher-leaves/batch-substitute', payload);
}

export function recentSubstitutes({ branch_id } = {}) {
  const qs = new URLSearchParams();
  if (branch_id) qs.set('branch_id', String(branch_id));
  const suffix = qs.toString();
  return call('GET', `/api/v1/substitutes/recent${suffix ? `?${suffix}` : ''}`);
}

// Undo 時間窗設定（Gmail Undo Send 模式）
export function getUndoSetting() {
  return call('GET', '/api/v1/system/settings/substitute-undo');
}

// 僅 super_admin 可成功；value 必須為 5 / 10 / 20 / 30
export function setUndoSetting(undoWindowSeconds) {
  return call('PUT', '/api/v1/system/settings/substitute-undo', {
    undo_window_seconds: Number(undoWindowSeconds),
  });
}
