const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed?.access_token || parsed?.token || null;
  } catch { return null; }
}

function headers() {
  const h = { Accept: 'application/json' };
  const t = getToken();
  if (t) h.Authorization = `Bearer ${t}`;
  return h;
}

export async function fetchPayrollSummary({ month, branchId, search, sort }) {
  const params = new URLSearchParams({ month });
  if (branchId) params.set('branch_id', branchId);
  if (search) params.set('search', search);
  if (sort) params.set('sort', sort);
  const res = await fetch(`${API_BASE}/finance/parttime-payroll?${params}`, { headers: headers() });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Failed');
  return res.json();
}

export async function fetchTeacherSessions({ teacherId, month, branchId, page = 1, perPage = 50 }) {
  const params = new URLSearchParams({ month, page, per_page: perPage });
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/${teacherId}/sessions?${params}`, { headers: headers() });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Failed');
  return res.json();
}

export async function exportPayrollCsv({ month, branchId }) {
  const params = new URLSearchParams({ month });
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/export?${params}`, { headers: headers() });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(body || 'Export failed');
  }
  const blob = await res.blob();
  const disposition = res.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename[^;=\n]*=["']?([^"';\n]*)["']?/);
  const filename = match ? decodeURIComponent(match[1]) : `payroll_${month}.csv`;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

export async function lockPayroll({ month, branchId }) {
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/lock`, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ month, branch_id: branchId }),
  });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Lock failed');
  return res.json();
}

export async function reopenPayroll({ month, branchId, reason }) {
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/reopen`, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ month, branch_id: branchId, reason }),
  });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Reopen failed');
  return res.json();
}

export async function fetchPayrollRules({ branchId }) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/rules?${params}`, { headers: headers() });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Failed');
  return res.json();
}

export async function updatePayrollRules({ branchId, baseRates, headcountBonus }) {
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/rules`, {
    method: 'PUT',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ branch_id: branchId, base_rates: baseRates, headcount_bonus: headcountBonus }),
  });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Update failed');
  return res.json();
}

export async function fetchTeacherPayrollRules({ branchId, teacherId }) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', branchId);
  if (teacherId) params.set('teacher_id', teacherId);
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/teacher-rules?${params}`, { headers: headers() });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Failed');
  return res.json();
}

export async function updateTeacherPayrollRules({ branchId, teacherId, baseRates, headcountBonus, effectiveFrom }) {
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/teacher-rules`, {
    method: 'PUT',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      branch_id: branchId,
      teacher_id: teacherId,
      base_rates: baseRates,
      headcount_bonus: headcountBonus,
      effective_from: effectiveFrom,
    }),
  });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Update failed');
  return res.json();
}

export async function deleteTeacherPayrollRules({ branchId, teacherId, effectiveFrom }) {
  const res = await fetch(`${API_BASE}/finance/parttime-payroll/teacher-rules`, {
    method: 'DELETE',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ branch_id: branchId, teacher_id: teacherId, effective_from: effectiveFrom }),
  });
  if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Delete failed');
  return res.json();
}
