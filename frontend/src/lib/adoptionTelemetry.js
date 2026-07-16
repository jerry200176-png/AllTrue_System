function resolveAccessToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return '';
    const s = JSON.parse(raw);
    return String(s?.access_token || '');
  } catch {
    return '';
  }
}

/** Strip accidental PII from telemetry meta (phone / email / free-text names). */
export function sanitizeAdoptionMeta(meta = {}) {
  if (!meta || typeof meta !== 'object' || Array.isArray(meta)) return {};
  const out = {};
  for (const [k, v] of Object.entries(meta)) {
    const key = String(k).toLowerCase();
    if (/(phone|email|password|token|name|body|note|address)/.test(key)) continue;
    if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' || v === null) {
      out[k] = v;
    } else if (Array.isArray(v) && v.every((x) => ['string', 'number', 'boolean'].includes(typeof x))) {
      out[k] = v.slice(0, 40);
    }
  }
  return out;
}

export async function trackAdoptionEvent(event, branchId, meta = {}) {
  try {
    const token = resolveAccessToken();
    const payload = {
      event,
      branch_id: Number(branchId) || undefined,
      meta: sanitizeAdoptionMeta(meta),
    };
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
    if (token) headers.Authorization = `Bearer ${token}`;
    await fetch('/api/v1/adoption/events', {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify(payload),
    });
  } catch {
    // Keep UX non-blocking if telemetry endpoint is unavailable.
  }
}

export async function trackParentPortalEvent(token, event, meta = {}) {
  if (!token) return;
  try {
    await fetch('/api/v1/parent/events', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        event,
        meta: sanitizeAdoptionMeta(meta),
      }),
    });
  } catch {
    // Keep UX non-blocking if telemetry endpoint is unavailable.
  }
}
