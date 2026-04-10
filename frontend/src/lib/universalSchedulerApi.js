import { supabase } from '../supabase';

async function getAccessToken() {
  try {
    const { data } = await supabase.auth.getSession();
    if (data?.session?.access_token) return data.session.access_token;
  } catch {
    // fallback to local storage token parsing
  }

  try {
    const raw = localStorage.getItem('alltrue_session');
    const parsed = raw ? JSON.parse(raw) : null;
    return parsed?.access_token || parsed?.token || null;
  } catch {
    return null;
  }
}

export async function createUniversalClassSchedule(payload) {
  const normalizedPayload = {
    ...payload,
    confirmed_dates: Array.isArray(payload?.confirmed_dates) ? payload.confirmed_dates : [],
    future_dates: Array.isArray(payload?.future_dates) ? payload.future_dates : [],
  };

  const token = await getAccessToken();
  if (!token) throw new Error('登入已過期，請重新登入');

  const res = await fetch('/api/v1/class-sessions/batch', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(normalizedPayload),
  });

  const rawText = await res.text();
  let body = {};
  try {
    body = rawText ? JSON.parse(rawText) : {};
  } catch {
    body = {};
  }
  if (!res.ok) {
    const validationPairs = body?.errors && typeof body.errors === 'object'
      ? Object.entries(body.errors)
        .map(([field, messages]) => {
          const text = Array.isArray(messages) ? messages.join('、') : String(messages || '');
          return `${field}: ${String(text).trim()}`;
        })
        .filter((line) => line && !line.endsWith(':'))
      : [];
    const validation = validationPairs.join(' | ');
    const textFallback = String(rawText || '').trim();
    const compactText = textFallback.replace(/\s+/g, ' ').slice(0, 220);
    const message = validation
      || body?.message
      || body?.error
      || (compactText ? `排課請求失敗 (HTTP ${res.status}) - ${compactText}` : `排課請求失敗 (HTTP ${res.status})`);
    console.error('createUniversalClassSchedule failed', { status: res.status, body, rawText, payload: normalizedPayload });
    throw new Error(message);
  }
  return body;
}

export async function createEnrollment(payload) {
  const normalizedPayload = {
    ...payload,
    confirmed_dates: Array.isArray(payload?.confirmed_dates) ? payload.confirmed_dates : [],
    future_dates: Array.isArray(payload?.future_dates) ? payload.future_dates : [],
  };

  const token = await getAccessToken();
  if (!token) throw new Error('登入已過期，請重新登入');

  const res = await fetch('/api/v1/enrollments', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(normalizedPayload),
  });

  const rawText = await res.text();
  let body = {};
  try {
    body = rawText ? JSON.parse(rawText) : {};
  } catch {
    body = {};
  }
  if (!res.ok) {
    const validationPairs = body?.errors && typeof body.errors === 'object'
      ? Object.entries(body.errors)
        .map(([field, messages]) => {
          const text = Array.isArray(messages) ? messages.join('、') : String(messages || '');
          return `${field}: ${String(text).trim()}`;
        })
        .filter((line) => line && !line.endsWith(':'))
      : [];
    const validation = validationPairs.join(' | ');
    const textFallback = String(rawText || '').trim();
    const compactText = textFallback.replace(/\s+/g, ' ').slice(0, 220);
    const message = validation
      || body?.message
      || body?.error
      || (compactText ? `入班請求失敗 (HTTP ${res.status}) - ${compactText}` : `入班請求失敗 (HTTP ${res.status})`);
    console.error('createEnrollment failed', { status: res.status, body, rawText, payload: normalizedPayload });
    throw new Error(message);
  }
  return body;
}

