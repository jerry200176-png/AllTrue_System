// Super-admin management of read-only AI triage tokens (backend: TriageTokenController).
const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

async function request(token, method, path, body) {
  const res = await fetch(`${API_BASE}/admin/triage-tokens${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  let json = null;
  try {
    json = await res.json();
  } catch {
    json = null;
  }
  if (!res.ok) {
    const first = json?.errors ? Object.values(json.errors).flat().find(Boolean) : '';
    throw new Error(first || json?.message || `請求失敗 (${res.status})`);
  }
  // A 2xx with an unreadable body must not look like success (#4045 item 6).
  if (!json || typeof json !== 'object') throw new Error('伺服器回應格式錯誤，請重新整理後再試。');
  return json;
}

export async function listTriageTokens(token) {
  const json = await request(token, 'GET', '');
  if (!Array.isArray(json.data)) throw new Error('伺服器回應格式錯誤，請重新整理後再試。');
  return json;
}

export async function createTriageToken(token, name) {
  const json = await request(token, 'POST', '', { name });
  if (typeof json.token !== 'string' || json.token === '') {
    throw new Error('金鑰可能已建立，但沒有收到內容。請重新整理，撤銷名稱相同的金鑰後再建立一次。');
  }
  return json;
}

export function revokeTriageToken(token, id) {
  return request(token, 'DELETE', `/${encodeURIComponent(id)}`);
}
