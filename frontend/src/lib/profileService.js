const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

function buildHeaders(token, extra = {}) {
  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...extra,
  };
}

async function parseResponse(res) {
  const text = await res.text();
  let json = {};
  if (text && text.trim()) {
    try {
      json = JSON.parse(text);
    } catch {
      throw new Error('伺服器回應格式錯誤');
    }
  }
  if (!res.ok) {
    const errs = json?.errors;
    let detail = json?.message || `請求失敗 (${res.status})`;
    if (errs && typeof errs === 'object') {
      const first = Object.values(errs).flat().find(Boolean);
      if (first) detail = first;
    }
    throw new Error(detail);
  }
  return json;
}

export async function getMe(token) {
  const res = await fetch(`${API_BASE}/me`, {
    method: 'GET',
    headers: buildHeaders(token),
  });
  return parseResponse(res);
}

export async function updateMe(token, payload) {
  const res = await fetch(`${API_BASE}/me`, {
    method: 'PUT',
    headers: buildHeaders(token, { 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload),
  });
  return parseResponse(res);
}

export async function uploadAvatar(token, file) {
  const form = new FormData();
  form.append('avatar', file);

  const res = await fetch(`${API_BASE}/me/avatar`, {
    method: 'POST',
    headers: buildHeaders(token),
    body: form,
  });
  return parseResponse(res);
}

export async function getNotificationPrefs(token) {
  const res = await fetch(`${API_BASE}/me/notification-preferences`, {
    method: 'GET',
    headers: buildHeaders(token),
  });
  return parseResponse(res);
}

export async function updateNotificationPrefs(token, payload) {
  const res = await fetch(`${API_BASE}/me/notification-preferences`, {
    method: 'PUT',
    headers: buildHeaders(token, { 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload),
  });
  return parseResponse(res);
}

export async function getSecuritySummary(token) {
  const res = await fetch(`${API_BASE}/me/security`, {
    method: 'GET',
    headers: buildHeaders(token),
  });
  return parseResponse(res);
}

export async function logoutOtherSessions(token) {
  const res = await fetch(`${API_BASE}/me/security/logout-others`, {
    method: 'POST',
    headers: buildHeaders(token),
  });
  return parseResponse(res);
}
