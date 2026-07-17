export class RescheduleApiError extends Error {
  constructor(message, { status = 0, code = '', conflicts = [] } = {}) {
    super(message);
    this.name = 'RescheduleApiError';
    this.status = status;
    this.code = code;
    this.conflicts = conflicts;
  }
}

export async function commitReschedule({ token, payload, fetchImpl = fetch }) {
  if (!token) throw new RescheduleApiError('請重新登入', { status: 401, code: 'unauthenticated' });

  let response;
  try {
    response = await fetchImpl('/api/v1/learning-records/reschedule-session', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ ...payload, ensure_schedule_exception: true }),
    });
  } catch (error) {
    throw new RescheduleApiError(error?.message || '網路錯誤，請稍後再試', { code: 'network_error' });
  }

  const body = await response.json().catch(() => ({}));
  if (!response.ok || body?.committed !== true) {
    throw new RescheduleApiError(body?.message || '調課未完成，資料沒有變更', {
      status: response.status,
      code: body?.code || 'reschedule_failed',
      conflicts: Array.isArray(body?.conflicts) ? body.conflicts : [],
    });
  }

  return body;
}
