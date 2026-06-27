const API = '/api/v1';

export function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return null;
    const s = JSON.parse(raw);
    return s?.access_token ?? null;
  } catch { return null; }
}

function headers() {
  const h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
  const t = getToken();
  if (t) h['Authorization'] = `Bearer ${t}`;
  return h;
}

async function json(res) {
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `HTTP ${res.status}`);
  }
  return res.json();
}

const MAX_BUG_ATTACHMENTS = 5;

/**
 * @param {object} data — branch_id, title, description, severity?, page_key?, url?, client_info?
 * @param {File[]} [data.files] — 截圖（jpeg/png/gif/webp），最多 5 張、每張 ≤5MB
 */
export async function submitBugReport(data) {
  const { files = [], ...fields } = data;
  const t = getToken();
  const h = { Accept: 'application/json' };
  if (t) h.Authorization = `Bearer ${t}`;

  let body;
  const safeFiles = Array.isArray(files)
    ? files.filter((f) => f instanceof File).slice(0, MAX_BUG_ATTACHMENTS)
    : [];

  if (safeFiles.length > 0) {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      fd.append(k, typeof v === 'string' ? v : String(v));
    });
    safeFiles.forEach((f) => fd.append('attachments[]', f));
    body = fd;
  } else {
    h['Content-Type'] = 'application/json';
    body = JSON.stringify(fields);
  }

  const res = await fetch(`${API}/bugs`, { method: 'POST', headers: h, body });
  return json(res);
}

export { MAX_BUG_ATTACHMENTS };

export async function fetchBugReports(branchId, filters = {}, perPage = 20, page = 1) {
  const params = new URLSearchParams({ branch_id: branchId, per_page: perPage, page });
  if (filters.status) params.set('status', filters.status);
  if (filters.severity) params.set('severity', filters.severity);
  if (filters.reporter) params.set('reporter', filters.reporter);
  if (filters.keyword) params.set('keyword', filters.keyword);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.sort) params.set('sort', filters.sort);
  const res = await fetch(`${API}/bugs?${params}`, { headers: headers() });
  return json(res);
}

export async function fetchBugDetail(bugId) {
  const res = await fetch(`${API}/bugs/${bugId}`, { headers: headers() });
  return json(res);
}

export async function addBugComment(bugId, body, isInternalNote = false) {
  const res = await fetch(`${API}/bugs/${bugId}/comments`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ body, is_internal_note: isInternalNote }),
  });
  return json(res);
}

export async function updateBugStatus(bugId, status, note = null) {
  const res = await fetch(`${API}/bugs/${bugId}/status`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ status, note }),
  });
  return json(res);
}

export async function updateBugCommentVisibility(bugId, commentId, isInternalNote) {
  const res = await fetch(`${API}/bugs/${bugId}/comments/${commentId}/visibility`, {
    method: 'PATCH',
    headers: headers(),
    body: JSON.stringify({ is_internal_note: isInternalNote }),
  });
  return json(res);
}

export async function reporterVerifyBug(bugId, verdict, note = '', branchId = null) {
  const params = new URLSearchParams();
  if (branchId != null && branchId !== '') {
    params.set('branch_id', String(branchId));
  }
  const qs = params.toString();
  const res = await fetch(`${API}/bugs/${bugId}/reporter-verify${qs ? `?${qs}` : ''}`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ verdict, note: note || undefined }),
  });
  return json(res);
}

