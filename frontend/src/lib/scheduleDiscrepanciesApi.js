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

export const DISCREPANCY_TYPES = [
  { value: 'student_no_show', label: '學生未到場', hint: '系統有排課，但學生沒出現' },
  { value: 'wrong_student_list', label: '學生名單有誤', hint: '來的學生不是課表上的那位' },
  { value: 'wrong_time', label: '上課時間有誤', hint: '實際時段與系統顯示不同' },
  { value: 'class_cancelled', label: '課程已取消', hint: '主任已取消但系統尚未更新' },
  { value: 'other', label: '其他', hint: '其他課表與實際不符的情形' },
];

export const STATUS_LABELS = {
  pending: '待處理',
  acknowledged: '處理中',
  resolved: '已解決',
  withdrawn: '已撤銷',
};

/**
 * Create a schedule-discrepancy report.
 * @param {object} payload branch_id (required), class_session_id (nullable),
 *   discrepancy_type (required), session_date, subject, student_name, time_range, notes
 * @returns {{ duplicate: boolean, discrepancy?: object, existing?: object }}
 */
export async function createDiscrepancy(payload) {
  const res = await fetch(`${API}/schedule-discrepancies`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify(payload),
  });
  return parse(res);
}

export async function fetchActiveForSession(classSessionId) {
  const params = new URLSearchParams({ class_session_id: String(classSessionId) });
  const res = await fetch(`${API}/schedule-discrepancies/active-for-session?${params}`, { headers: headers() });
  return parse(res);
}

export async function fetchMyDiscrepancies({ branchId, perPage = 50 } = {}) {
  const params = new URLSearchParams({ per_page: String(perPage) });
  if (branchId) params.set('branch_id', String(branchId));
  const res = await fetch(`${API}/schedule-discrepancies/my?${params}`, { headers: headers() });
  return parse(res);
}

export async function withdrawDiscrepancy(id) {
  const res = await fetch(`${API}/schedule-discrepancies/${id}/withdraw`, {
    method: 'POST',
    headers: headers(),
  });
  return parse(res);
}

/* ── Director-facing ───────────────────────────────────────────── */

export async function fetchDiscrepancies({ branchId, status, includeArchived = false, perPage = 20, page = 1 } = {}) {
  const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
  if (branchId) params.set('branch_id', String(branchId));
  if (status) params.set('status', status);
  if (includeArchived) params.set('include_archived', '1');
  const res = await fetch(`${API}/schedule-discrepancies?${params}`, { headers: headers() });
  return parse(res);
}

export async function fetchDiscrepancySummary(branchId) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', String(branchId));
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/schedule-discrepancies/summary${qs}`, { headers: headers() });
  return parse(res);
}

export async function updateDiscrepancyStatus(id, status, resolutionNote = null) {
  const res = await fetch(`${API}/schedule-discrepancies/${id}`, {
    method: 'PUT',
    headers: headers(),
    body: JSON.stringify({ status, resolution_note: resolutionNote }),
  });
  return parse(res);
}
