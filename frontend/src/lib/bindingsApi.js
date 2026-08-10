const API = '/api/v1';

function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return null;
    const s = JSON.parse(raw);
    return s?.access_token ?? null;
  } catch { return null; }
}

function headers() {
  const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
  const t = getToken();
  if (t) h.Authorization = `Bearer ${t}`;
  return h;
}

async function parse(res) {
  const payload = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(payload?.message || `HTTP ${res.status}`);
    err.status = res.status;
    err.payload = payload;
    throw err;
  }
  return payload;
}

/**
 * LINE 綁定（StudentLineBinding）管理與健康度 API。
 *
 * 契約對齊後端 W31 TODO：
 *  - P1-2 `GET /api/v1/bindings`、`GET /api/v1/bindings/stats`、`DELETE /api/v1/bindings/{id}`
 *  - P2-2 `GET /api/v1/bindings/conflicts`、`POST /api/v1/bindings/conflicts/{id}/resolve`
 *  - P3-1 `GET /api/v1/bindings/metrics`
 *
 * 後端端點尚未合併 main 時，fetch 會回 404/500 → 頁面以 error state 呈現（見各頁）。
 */

/* ── P1-2：Binding 管理 ───────────────────────────────────────── */

/**
 * 分頁綁定清單。
 * @param {object} opts { campusId, studentName, dateRange, page, perPage }
 * @returns {Promise<{ data: Array, meta?: object }>}
 */
export async function fetchBindings({ campusId, studentName, dateRange, page = 1, perPage = 20 } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (campusId) params.set('campus_id', String(campusId));
  if (studentName) params.set('student_name', studentName);
  if (dateRange) params.set('date_range', dateRange);
  const res = await fetch(`${API}/bindings?${params}`, { headers: headers() });
  return parse(res);
}

/** 綁定率統計（全校／單一分校）。@returns {Promise<object>} */
export async function fetchBindingStats({ campusId } = {}) {
  const params = new URLSearchParams();
  if (campusId) params.set('campus_id', String(campusId));
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/bindings/stats${qs}`, { headers: headers() });
  return parse(res);
}

/** 解除綁定（限 director / super_admin）。 */
export async function unbindBinding(id) {
  const res = await fetch(`${API}/bindings/${id}`, { method: 'DELETE', headers: headers() });
  return parse(res);
}

/* ── P2-2：衝突審查 ───────────────────────────────────────────── */

/** 衝突清單。@returns {Promise<{ data?: Array }>} */
export async function fetchBindingConflicts({ campusId } = {}) {
  const params = new URLSearchParams();
  if (campusId) params.set('campus_id', String(campusId));
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/bindings/conflicts${qs}`, { headers: headers() });
  return parse(res);
}

/**
 * 解決衝突：保留 keepBindingId 綁定、撤除同一學生的另一綁定。
 * @param {number|string} conflictId
 * @param {number|string} keepBindingId
 */
export async function resolveBindingConflict(conflictId, keepBindingId) {
  const res = await fetch(`${API}/bindings/conflicts/${conflictId}/resolve`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ keep_binding_id: keepBindingId }),
  });
  return parse(res);
}

/* ── P3-1：綁定健康度監控 ─────────────────────────────────────── */

/**
 * 綁定健康度聚合指標（全校 + 各分校 + 趨勢 + 未綁定活躍 + 異常事件 + 每週摘要）。
 * @returns {Promise<object>}
 */
export async function fetchBindingMetrics({ campusId } = {}) {
  const params = new URLSearchParams();
  if (campusId) params.set('campus_id', String(campusId));
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/bindings/metrics${qs}`, { headers: headers() });
  return parse(res);
}
