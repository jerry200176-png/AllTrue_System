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
    const err = new Error(payload?.message || payload?.error || `HTTP ${res.status}`);
    err.status = res.status;
    err.payload = payload;
    throw err;
  }
  return payload;
}

/**
 * 取得 P2 審核待處理的重複群組列表
 */
export async function fetchP2ReviewGroups({ branchId } = {}) {
  const qs = new URLSearchParams();
  if (branchId) qs.set('branch_id', String(branchId));
  const url = `${API}/admin/duplicate-sessions/p2-review${qs.toString() ? '?' + qs : ''}`;
  const res = await fetch(url, { headers: headers() });
  return parse(res);
}

/**
 * 主任對重複群組做出保留決策
 */
export async function submitP2ReviewDecision(groupId, payload) {
  const res = await fetch(`${API}/admin/duplicate-sessions/p2-review/${groupId}`, {
    method: 'PATCH',
    headers: headers(),
    body: JSON.stringify(payload),
  });
  return parse(res);
}
