import { supabase } from '../supabase';
import { normalizeUniversalScheduleErrorMessage } from './universalSchedulerErrorMessage.js';

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
    if (res.status === 409 && body?.code === 'duplicate_active_course') {
      const err = new Error(body.message || '該學生已有相同科目的進行中課程');
      err.isDuplicateCourse = true;
      err.conflicts = body.conflicts || [];
      err.originalPayload = normalizedPayload;
      throw err;
    }
    const message = normalizeUniversalScheduleErrorMessage(body, rawText, res.status);
    console.error('createUniversalClassSchedule failed', { status: res.status, body, rawText, payload: normalizedPayload });
    throw new Error(message);
  }
  return body;
}
