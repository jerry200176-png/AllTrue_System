/**
 * Adoption / E-OPS-TRUST telemetry helpers.
 * Ops use only: no names/phone/LINE/ledger detail; strip linkable IDs in meta.
 */

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

function localYmd() {
  try {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  } catch {
    return 'unknown-day';
  }
}

/** Browser-tab session id (not auth). Survives refreshes within same tab. */
export function getTrustTelemetrySessionId() {
  try {
    const key = 'alltrue_trust_telem_sid';
    let sid = sessionStorage.getItem(key);
    if (!sid) {
      sid = `t_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
      sessionStorage.setItem(key, sid);
    }
    return sid;
  } catch {
    return `t_fallback_${Date.now()}`;
  }
}

/**
 * Strip accidental PII + linkable identifiers from telemetry meta.
 * student_id / student_class_id are linkable even without names — strip by default.
 */
export function sanitizeAdoptionMeta(meta = {}) {
  if (!meta || typeof meta !== 'object' || Array.isArray(meta)) return {};
  const out = {};
  for (const [k, v] of Object.entries(meta)) {
    const key = String(k).toLowerCase();
    if (/(phone|email|password|token|name|body|note|address|line_id|lineid|student_id|student_class_id|class_id|invoice|receipt|amount|charge|pay)/.test(key)) {
      continue;
    }
    if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' || v === null) {
      out[k] = v;
    } else if (Array.isArray(v) && v.every((x) => ['string', 'number', 'boolean'].includes(typeof x))) {
      out[k] = v.slice(0, 40);
    }
  }
  return out;
}

/** Once per campus + session + calendar day + event (+ optional decision key). */
export function claimTrustTelemetryOnce(event, branchId, decisionKey = '') {
  try {
    const sid = getTrustTelemetrySessionId();
    const key = [
      'trust_once',
      String(event),
      String(Number(branchId) || 0),
      sid,
      localYmd(),
      String(decisionKey || '-'),
    ].join('|');
    if (sessionStorage.getItem(key)) return false;
    sessionStorage.setItem(key, '1');
    return true;
  } catch {
    return true;
  }
}

export function markTrustSeen(branchId, decisionKey) {
  try {
    const sid = getTrustTelemetrySessionId();
    sessionStorage.setItem(
      `trust_seen|${Number(branchId) || 0}|${sid}|${localYmd()}|${decisionKey}`,
      '1'
    );
    sessionStorage.setItem(`trust_any_seen|${Number(branchId) || 0}|${sid}|${localYmd()}`, '1');
  } catch { /* ignore */ }
}

export function hasSeenAnyTrustDecision(branchId) {
  try {
    const sid = getTrustTelemetrySessionId();
    return sessionStorage.getItem(`trust_any_seen|${Number(branchId) || 0}|${sid}|${localYmd()}`) === '1';
  } catch {
    return false;
  }
}

export function markTrustProvidedPathUsed(branchId, decisionKey = '') {
  try {
    const sid = getTrustTelemetrySessionId();
    sessionStorage.setItem(
      `trust_path|${Number(branchId) || 0}|${sid}|${localYmd()}|${decisionKey || 'any'}`,
      '1'
    );
    sessionStorage.setItem(`trust_path_any|${Number(branchId) || 0}|${sid}|${localYmd()}`, '1');
  } catch { /* ignore */ }
}

export function usedTrustProvidedPath(branchId) {
  try {
    const sid = getTrustTelemetrySessionId();
    return sessionStorage.getItem(`trust_path_any|${Number(branchId) || 0}|${sid}|${localYmd()}`) === '1';
  } catch {
    return false;
  }
}

export async function trackAdoptionEvent(event, branchId, meta = {}) {
  try {
    const token = resolveAccessToken();
    const payload = {
      event,
      branch_id: Number(branchId) || undefined,
      meta: sanitizeAdoptionMeta({
        ...meta,
        telem_session: getTrustTelemetrySessionId(),
        telem_day: localYmd(),
      }),
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

/** Deduped trust events: campus + telem_session + day (+ decision key). */
export async function trackTrustEventOnce(event, branchId, meta = {}, decisionKey = '') {
  const key = decisionKey || meta.key || '';
  if (!claimTrustTelemetryOnce(event, branchId, key)) return false;
  await trackAdoptionEvent(event, branchId, meta);
  return true;
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
