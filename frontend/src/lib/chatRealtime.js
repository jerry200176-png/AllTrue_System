/**
 * Chat realtime module.
 *
 * Strategy: start with polling; upgrade to WebSocket (laravel-echo + pusher-js)
 * when the soketi server is running. Falls back gracefully.
 */

let echoInstance = null;
let subscribedChannels = {};
let pollingIntervals = {};

/**
 * Try to initialize Laravel Echo. Returns true if successful.
 */
export async function initEcho() {
  try {
    const Echo = (await import('laravel-echo')).default;
    const Pusher = (await import('pusher-js')).default;
    window.Pusher = Pusher;

    echoInstance = new Echo({
      broadcaster: 'pusher',
      key: window.__ALLTRUE_WS_KEY || 'alltrue-local-key',
      wsHost: window.__ALLTRUE_WS_HOST || window.location.hostname,
      wsPort: window.__ALLTRUE_WS_PORT || 6001,
      forceTLS: false,
      disableStats: true,
      enabledTransports: ['ws'],
      authEndpoint: '/api/v1/broadcasting/auth',
      auth: {
        headers: {
          Authorization: `Bearer ${getToken()}`,
        },
      },
    });

    return true;
  } catch (e) {
    console.warn('[Chat] WebSocket init failed, using polling fallback:', e.message);
    return false;
  }
}

/**
 * Subscribe to a thread channel for new messages.
 * Falls back to polling if Echo is unavailable.
 */
export function subscribeThread(threadId, onMessage, pollFn = null) {
  if (echoInstance) {
    try {
      const channel = echoInstance.private(`chat.thread.${threadId}`);
      channel.listen('.message.created', (data) => {
        onMessage(data);
      });
      subscribedChannels[threadId] = channel;
      return;
    } catch (e) {
      console.warn('[Chat] Channel subscribe failed, falling back to poll:', e.message);
    }
  }

  if (pollFn) {
    pollingIntervals[threadId] = setInterval(pollFn, 3000);
  }
}

/**
 * Unsubscribe from a thread channel and clear polling.
 */
export function unsubscribeThread(threadId) {
  if (echoInstance && subscribedChannels[threadId]) {
    echoInstance.leave(`chat.thread.${threadId}`);
    delete subscribedChannels[threadId];
  }
  if (pollingIntervals[threadId]) {
    clearInterval(pollingIntervals[threadId]);
    delete pollingIntervals[threadId];
  }
}

/**
 * Unsubscribe all.
 */
export function unsubscribeAll() {
  Object.keys(subscribedChannels).forEach(id => unsubscribeThread(id));
  Object.keys(pollingIntervals).forEach(id => {
    clearInterval(pollingIntervals[id]);
    delete pollingIntervals[id];
  });
}

function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    return raw ? JSON.parse(raw)?.access_token : null;
  } catch { return null; }
}
