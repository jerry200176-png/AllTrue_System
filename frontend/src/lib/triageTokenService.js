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
  let json = {};
  try {
    json = await res.json();
  } catch {
    json = {};
  }
  if (!res.ok) {
    const first = json?.errors ? Object.values(json.errors).flat().find(Boolean) : '';
    throw new Error(first || json?.message || `請求失敗 (${res.status})`);
  }
  return json;
}

export function listTriageTokens(token) {
  return request(token, 'GET', '');
}

export function createTriageToken(token, name) {
  return request(token, 'POST', '', { name });
}

export function revokeTriageToken(token, id) {
  return request(token, 'DELETE', `/${encodeURIComponent(id)}`);
}
