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

export function updateTeacherEligibilityEvent(id, payload) {
  return request(`/finance/teacher-eligibility/events/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
}

export function withdrawTeacherEligibilityEvent(id) {
  return request(`/finance/teacher-eligibility/events/${id}/withdraw`, { method: 'POST' });
}

export function createTeacherEligibilityAchievement(payload) {
  return request('/finance/teacher-eligibility/achievements', { method: 'POST', body: JSON.stringify(payload) });
}

export function updateTeacherEligibilityAchievement(id, payload) {
  return request(`/finance/teacher-eligibility/achievements/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
}

export function withdrawTeacherEligibilityAchievement(id) {
  return request(`/finance/teacher-eligibility/achievements/${id}/withdraw`, { method: 'POST' });
}

export function createTeacherEligibilityDeduction(payload) {
  return request('/finance/teacher-eligibility/deductions', { method: 'POST', body: JSON.stringify(payload) });
}

export function updateTeacherEligibilityDeduction(id, payload) {
  return request(`/finance/teacher-eligibility/deductions/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
}

export function withdrawTeacherEligibilityDeduction(id) {
  return request(`/finance/teacher-eligibility/deductions/${id}/withdraw`, { method: 'POST' });
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

export function approveTeacherSalaryProfile(id) {
  return request(`/finance/teacher-eligibility/salary-profiles/${id}/approve`, { method: 'POST' });
}

export function createTeacherEligibilityAdminAllowance(payload) {
  return request('/finance/teacher-eligibility/admin-allowances', { method: 'POST', body: JSON.stringify(payload) });
}

export function updateTeacherEligibilityAdminAllowance(id, payload) {
  return request(`/finance/teacher-eligibility/admin-allowances/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
}

export function withdrawTeacherEligibilityAdminAllowance(id) {
  return request(`/finance/teacher-eligibility/admin-allowances/${id}/withdraw`, { method: 'POST' });
}

export function confirmTeacherEligibilityAdminAllowance(id) {
  return request(`/finance/teacher-eligibility/admin-allowances/${id}/confirm`, { method: 'POST' });
}

export function approveTeacherEligibilityAdminAllowance(id) {
  return request(`/finance/teacher-eligibility/admin-allowances/${id}/approve`, { method: 'POST' });
}

export function lockFulltimePayroll({ month, branchId }) {
  return request('/finance/teacher-eligibility/lock', { method: 'POST', body: JSON.stringify({ month, branch_id: Number(branchId) }) });
}

export function reopenFulltimePayroll({ month, branchId, reason }) {
  return request('/finance/teacher-eligibility/reopen', { method: 'POST', body: JSON.stringify({ month, branch_id: Number(branchId), reason }) });
}

export function addFulltimePayrollAdjustment(payload) {
  return request('/finance/teacher-eligibility/adjustments', { method: 'POST', body: JSON.stringify(payload) });
}

export async function fetchTeacherEligibilityDaily({ teacherId, start, end, branchId }) {
  const params = new URLSearchParams({ start, end });
  if (branchId) params.set('branch_id', branchId);
  return request(`/finance/teacher-eligibility/${teacherId}/daily?${params}`);
}

export async function exportFulltimePayrollCsv({ month, branchId }) {
  const params = new URLSearchParams({ month, branch_id: String(branchId) });
  const res = await fetch(`${API_BASE}/finance/teacher-eligibility/export?${params}`, { headers: headers() });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(data?.message || '匯出失敗');
  }
  const blob = await res.blob();
  const disposition = res.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename[^;=\n]*=["']?([^"';\n]*)["']?/);
  const filename = match ? decodeURIComponent(match[1]) : `正職薪資_${month}.csv`;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
