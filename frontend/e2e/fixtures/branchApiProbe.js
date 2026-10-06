// Keep every branch-scoped response: a later 200 must not erase an earlier
// authorization failure, and readiness must belong to the selected branch.
export function createBranchApiProbe(pathname) {
  const responses = [];
  const pending = new Set();
  const matches = (request) => new URL(request.url()).pathname === pathname;
  return {
    // Requests still in flight; assert unauthorizedStatuses() only once this is 0.
    pendingCount: () => pending.size,
    attach(page) {
      page.on('request', (request) => { if (matches(request)) pending.add(request); });
      page.on('requestfinished', (request) => pending.delete(request));
      page.on('requestfailed', (request) => pending.delete(request));
      page.on('response', this.observe);
    },
    observe(response) {
      const url = new URL(response.url());
      if (url.pathname !== pathname) return;
      responses.push({ status: response.status(), branchId: url.searchParams.get('branch_id') });
    },
    hasSuccessFor(branchId) {
      return responses.some((response) => response.status === 200 && response.branchId === String(branchId));
    },
    unauthorizedStatuses() {
      return responses.filter((response) => response.status === 401 || response.status === 403)
        .map((response) => response.status);
    },
  };
}
