const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

function headers() {
  const raw = localStorage.getItem('alltrue_session');
  let token = null;
  try { token = JSON.parse(raw || '{}')?.access_token || JSON.parse(raw || '{}')?.token || null; } catch { /* no-op */ }
  return { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) };
}

async function request(path, options = {}) {
  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      ...headers(),
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '薪資要件資料操作失敗');
  return data;
}

export async function fetchTeacherEligibility({ period = 'month', start, end, branchId } = {}) {
  const params = new URLSearchParams({ period });
  if (start) params.set('start', start);
  if (end) params.set('end', end);
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API_BASE}/finance/teacher-eligibility?${params}`, { headers: headers() });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '無法取得教師符合要件報表');
  return data;
}

export async function fetchTeacherEligibilityInputs({ start, end, branchId } = {}) {
  const params = new URLSearchParams();
  if (start) params.set('start', start);
  if (end) params.set('end', end);
  if (branchId) params.set('branch_id', branchId);
  const suffix = params.toString() ? `?${params}` : '';
  return request(`/finance/teacher-eligibility/inputs${suffix}`);
}

export function createTeacherEligibilityEvent(payload) {
  return request('/finance/teacher-eligibility/events', { method: 'POST', body: JSON.stringify(payload) });
}

export function createTeacherEligibilityAchievement(payload) {
  return request('/finance/teacher-eligibility/achievements', { method: 'POST', body: JSON.stringify(payload) });
}

export function createTeacherEligibilityDeduction(payload) {
  return request('/finance/teacher-eligibility/deductions', { method: 'POST', body: JSON.stringify(payload) });
}

export function approveTeacherEligibilityEvent(id) {
  return request(`/finance/teacher-eligibility/events/${id}/approve`, { method: 'POST' });
}

export function verifyTeacherEligibilityAchievement(id) {
  return request(`/finance/teacher-eligibility/achievements/${id}/verify`, { method: 'POST' });
}

export function confirmTeacherEligibilityDeduction(id) {
  return request(`/finance/teacher-eligibility/deductions/${id}/confirm`, { method: 'POST' });
}

export function approveTeacherEligibilityDeduction(id) {
  return request(`/finance/teacher-eligibility/deductions/${id}/approve`, { method: 'POST' });
}

export function saveTeacherSalaryProfile(payload) {
  return request('/finance/teacher-eligibility/salary-profiles', { method: 'POST', body: JSON.stringify(payload) });
}
