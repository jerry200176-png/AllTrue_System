// Shared fetch for the super-admin key endpoints under /api/v1/admin/<resource>
// (triage tokens: TriageTokenController; POP in-app agent keys: PopInappApiClientController).
const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

export const BAD_RESPONSE_MESSAGE = '伺服器回應格式錯誤，請重新整理後再試。';

export async function adminKeyRequest(resource, token, method, path, body) {
  const res = await fetch(`${API_BASE}/admin/${resource}${path}`, {
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
  if (!json || typeof json !== 'object') throw new Error(BAD_RESPONSE_MESSAGE);
  return json;
}
