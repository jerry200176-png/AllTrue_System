import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  getParentNotificationPreferences,
  setParentNotificationPreferences,
} from '../../api.js';

const jsonResponse = (body, { ok = true, status = 200 } = {}) => ({
  ok,
  status,
  json: () => Promise.resolve(body),
});

describe('parent notification preferences API', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reads parent notification preferences with parent bearer token', async () => {
    fetch.mockResolvedValueOnce(jsonResponse({ learning_feedback_push: true }));

    const data = await getParentNotificationPreferences('parent-token');

    expect(data.learning_feedback_push).toBe(true);
    expect(fetch).toHaveBeenCalledWith('/api/v1/parent/notification-preferences', {
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer parent-token',
      },
    });
  });

  it('writes learning feedback preference using backend snake_case contract', async () => {
    fetch.mockResolvedValueOnce(jsonResponse({ learning_feedback_push: false }));

    await setParentNotificationPreferences('parent-token', { learningFeedbackPush: false });

    expect(fetch).toHaveBeenCalledWith('/api/v1/parent/notification-preferences', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: 'Bearer parent-token',
      },
      body: JSON.stringify({ learning_feedback_push: false }),
    });
  });

  it('surfaces backend error message when update fails', async () => {
    fetch.mockResolvedValueOnce(jsonResponse(
      { message: '綁定 LINE 後才可開啟推播通知' },
      { ok: false, status: 422 },
    ));

    await expect(
      setParentNotificationPreferences('parent-token', { learningFeedbackPush: true }),
    ).rejects.toThrow('綁定 LINE 後才可開啟推播通知');
  });
});
