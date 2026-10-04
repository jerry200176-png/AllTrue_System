import { computed, ref } from 'vue';

/** Keep branch-scoped director pages unmounted until the authenticated list wins. */
export function useDirectorBranchAuthorization() {
  const status = ref('idle');
  const authorizedIds = ref(new Set());
  const identity = ref(null);
  let requestId = 0;
  let pending = null;

  function reset() {
    requestId += 1;
    status.value = 'idle';
    authorizedIds.value = new Set();
    identity.value = null;
    pending = null;
  }

  function load(contextKey, fetchCampuses, applyCampuses, { timeoutMs = 25_000, refresh = false } = {}) {
    if (status.value === 'loading' && identity.value === contextKey && pending) return pending;
    if (status.value === 'ready' && identity.value === contextKey && !refresh) return Promise.resolve(true);
    const preserveReady = refresh && status.value === 'ready' && identity.value === contextKey;
    if (preserveReady) {
      // A routine token refresh for the same user and role must not unmount
      // branch pages and discard in-progress forms. Keep the last authorized
      // IDs active until the refreshed authenticated campus response arrives.
      requestId += 1;
      pending = null;
    } else {
      reset();
    }
    if (!contextKey) {
      status.value = 'failed';
      return Promise.resolve(false);
    }
    const ownRequest = requestId;
    identity.value = contextKey;
    if (!preserveReady) status.value = 'loading';
    pending = (async () => {
      let timer;
      try {
        const campuses = await Promise.race([
          Promise.resolve().then(fetchCampuses),
          new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('campus authorization timed out')), timeoutMs);
          }),
        ]);
        if (ownRequest !== requestId) return false;
        const ids = Array.isArray(campuses) ? campuses.map((campus) => Number(campus?.id)) : [];
        if (!ids.length || ids.some((id) => !Number.isSafeInteger(id) || id <= 0)) {
          status.value = 'failed';
          return false;
        }
        // Apply the authenticated list and select a branch before pages can mount.
        applyCampuses(campuses);
        authorizedIds.value = new Set(ids);
        status.value = 'ready';
        return true;
      } catch {
        if (ownRequest === requestId) status.value = 'failed';
        return false;
      } finally {
        clearTimeout(timer);
      }
    })();
    return pending;
  }

  function canMount(contextKey, branchId) {
    const id = Number(branchId);
    return status.value === 'ready'
      && Boolean(contextKey)
      && identity.value === contextKey
      && Number.isSafeInteger(id)
      && id > 0
      && authorizedIds.value.has(id);
  }

  return { status: computed(() => status.value), load, reset, canMount };
}
