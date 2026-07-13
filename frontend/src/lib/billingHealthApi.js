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
 * 取得帳務健康檢查摘要。
 * @returns {{ data: { charge_consistency, payment_divergence, mode_transition_anomalies } }}
 */
export async function fetchBillingHealth() {
  const res = await fetch(`${API}/admin/billing/health`, { headers: headers() });
  return parse(res);
}

/**
 * 重新計算指定學生的堂數。
 * @param {number} studentId
 * @returns {{ data: { student_id, courses_recomputed, results } }}
 */
export async function recomputeStudentSessions(studentId) {
  const res = await fetch(`${API}/admin/billing/recompute-student`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ student_id: studentId }),
  });
  return parse(res);
}

/**
 * 取得指定學生的帳務稽查時間線。
 * @param {number} studentId
 * @returns {{ data: { sessions: [...] } }}
 */
export async function auditStudentBilling(studentId) {
  const params = new URLSearchParams({ student_id: String(studentId) });
  const res = await fetch(`${API}/admin/billing/audit-student?${params}`, { headers: headers() });
  return parse(res);
}
