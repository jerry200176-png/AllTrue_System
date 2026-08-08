const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

function headers() {
  const raw = localStorage.getItem('alltrue_session');
  let token = null;
  try { token = JSON.parse(raw || '{}')?.access_token || JSON.parse(raw || '{}')?.token || null; } catch { /* no-op */ }
  return { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) };
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
