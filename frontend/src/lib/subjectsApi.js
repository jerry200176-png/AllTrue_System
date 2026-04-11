import { supabase } from '../supabase';

const CANONICAL_REVERSE = {
  '國文': 'Chinese',
  '英文': 'English',
  '數學': 'Math',
  '理化': 'Science',
  '物理': 'Physics',
  '化學': 'Chemistry',
  '生物': 'Biology',
  '社會': 'Social',
};

async function getToken() {
  try {
    const { data } = await supabase.auth.getSession();
    if (data?.session?.access_token) return data.session.access_token;
  } catch { /* noop */ }
  try {
    const parsed = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
    return parsed?.access_token || parsed?.token || null;
  } catch { return null; }
}

/** @param {{ branchId?: string|number }} opts */
export async function fetchSubjectOptions(opts = {}) {
  const token = await getToken();
  const headers = token ? { Authorization: `Bearer ${token}` } : {};
  const params = new URLSearchParams();
  if (opts.branchId) params.set('branch_id', String(opts.branchId));
  const qs = params.toString();
  const url = `/api/v1/subjects${qs ? `?${qs}` : ''}`;
  const res = await fetch(url, { headers });
  if (!res.ok) throw new Error('無法載入科目列表');
  const data = await res.json();
  return data.map((s) => ({
    id: s.id,
    value: CANONICAL_REVERSE[s.name] || s.name,
    label: s.name,
    campus_id: s.campus_id ?? null,
  }));
}

export async function createSubject(name, branchId) {
  const token = await getToken();
  const body = { name };
  if (branchId) body.branch_id = branchId;
  const res = await fetch('/api/v1/subjects', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || '新增失敗');
  return data;
}

export async function updateSubject(id, name) {
  const token = await getToken();
  const res = await fetch(`/api/v1/subjects/${id}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ name }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || '更新失敗');
  return data;
}

export async function deleteSubject(id) {
  const token = await getToken();
  const res = await fetch(`/api/v1/subjects/${id}`, {
    method: 'DELETE',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });
  if (res.status === 204) return;
  const data = await res.json();
  throw new Error(data.message || '刪除失敗');
}
