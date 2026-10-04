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

  function load(token, fetchCampuses, applyCampuses) {
    if (status.value === 'loading' && identity.value === token && pending) return pending;
    if (status.value === 'ready' && identity.value === token) return Promise.resolve(true);
    reset();
    if (!token) {
      status.value = 'failed';
      return Promise.resolve(false);
    }
    const ownRequest = requestId;
    identity.value = token;
    status.value = 'loading';
    pending = (async () => {
      try {
        const campuses = await fetchCampuses(token);
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
      }
    })();
    return pending;
  }

  function canMount(token, branchId) {
    const id = Number(branchId);
    return status.value === 'ready'
      && Boolean(token)
      && identity.value === token
      && Number.isSafeInteger(id)
      && id > 0
      && authorizedIds.value.has(id);
  }

  return { status: computed(() => status.value), load, reset, canMount };
}
