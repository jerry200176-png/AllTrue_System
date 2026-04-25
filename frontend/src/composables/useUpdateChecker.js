import { ref, onMounted, onBeforeUnmount } from 'vue';

// PR #51: parent learning record feedback UI
const POLL_INTERVAL_MS = 5 * 60 * 1000; // 5 minutes

export function useUpdateChecker() {
  const updateAvailable = ref(false);
  const dismissed = ref(false);
  let timer = null;
  let onVisChange = null;

  const bundledTime = typeof __APP_BUILD_TIME__ !== 'undefined' ? __APP_BUILD_TIME__ : null;

  async function check() {
    if (updateAvailable.value || !bundledTime) return;
    try {
      const res = await fetch('./version.json?_=' + Date.now(), { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data.t && data.t !== bundledTime) {
        updateAvailable.value = true;
        stop();
      }
    } catch (_) {
      // network error, offline, dev-mode 404 — silently ignore
    }
  }

  function stop() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  function dismiss() {
    dismissed.value = true;
  }

  function reload() {
    location.reload();
  }

  onMounted(() => {
    timer = setInterval(check, POLL_INTERVAL_MS);

    onVisChange = () => {
      if (document.visibilityState === 'visible') check();
    };
    document.addEventListener('visibilitychange', onVisChange);
  });

  onBeforeUnmount(() => {
    stop();
    if (onVisChange) document.removeEventListener('visibilitychange', onVisChange);
  });

  return { updateAvailable, dismissed, dismiss, reload };
}
