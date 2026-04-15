const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

function authHeaders() {
  const raw = localStorage.getItem('alltrue_session');
  if (!raw) return { 'Content-Type': 'application/json' };
  try {
    const parsed = JSON.parse(raw);
    const token = parsed?.access_token || parsed?.token || raw;
    return {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    };
  } catch {
    return {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${raw}`,
    };
  }
}

async function handleResponse(res) {
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || `HTTP ${res.status}`);
  return data;
}

export async function listPackages({ branchId, studentId, activeOnly } = {}) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', String(branchId));
  if (studentId) params.set('student_id', String(studentId));
  if (activeOnly) params.set('active_only', '1');
  const res = await fetch(`${API_BASE}/course-packages?${params}`, {
    headers: authHeaders(),
  });
  return handleResponse(res);
}

export async function getPackage(id) {
  const res = await fetch(`${API_BASE}/course-packages/${id}`, {
    headers: authHeaders(),
  });
  return handleResponse(res);
}

export async function createMultiSubjectPackage(payload) {
  const res = await fetch(`${API_BASE}/course-packages/create-multi-subject`, {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  });
  return handleResponse(res);
}

export async function updatePackage(id, payload) {
  const res = await fetch(`${API_BASE}/course-packages/${id}`, {
    method: 'PUT',
    headers: authHeaders(),
    body: JSON.stringify(payload),
  });
  return handleResponse(res);
}

export async function recomputePackage(id) {
  const res = await fetch(`${API_BASE}/course-packages/${id}/recompute`, {
    method: 'POST',
    headers: authHeaders(),
  });
  return handleResponse(res);
}

export async function bindCoursesToPackage(id, studentClassIds, dryRun = false) {
  const res = await fetch(`${API_BASE}/course-packages/${id}/bind-courses`, {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify({ student_class_ids: studentClassIds, dry_run: dryRun }),
  });
  return handleResponse(res);
}
