const API = '/api/v1';

function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return null;
    const s = JSON.parse(raw);
    return s?.access_token ?? null;
  } catch { return null; }
}

function headers() {
  const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
  const t = getToken();
  if (t) h.Authorization = `Bearer ${t}`;
  return h;
}

async function parse(res) {
  const payload = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(payload?.message || `HTTP ${res.status}`);
    err.status = res.status;
    err.payload = payload;
    throw err;
  }
  return payload;
}

/**
 * Fetch binding list (paginated, filterable).
 * @param {object} opts
 * @param {number}  [opts.branchId]
 * @param {string}  [opts.studentName]
 * @param {string}  [opts.dateFrom]
 * @param {string}  [opts.dateTo]
 * @param {number}  [opts.page=1]
 * @param {number}  [opts.perPage=20]
 * @returns {Promise<{data: Array, meta: {current_page, last_page, total, per_page}}>}
 */
export async function fetchBindings({ branchId, studentName, dateFrom, dateTo, page = 1, perPage = 20 } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (branchId) params.set('campus_id', String(branchId));
  if (studentName) params.set('student_name', studentName);
  if (dateFrom) params.set('date_from', dateFrom);
  if (dateTo) params.set('date_to', dateTo);
  const res = await fetch(`${API}/bindings?${params}`, { headers: headers() });
  return parse(res);
}

/**
 * Fetch binding statistics.
 * @param {number} [branchId] - optional campus filter
 * @returns {Promise<{total_students, bound_students, binding_rate, total_bindings, campus_breakdown?}>}
 */
export async function fetchBindingStats(branchId) {
  const params = new URLSearchParams();
  if (branchId) params.set('campus_id', String(branchId));
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/bindings/stats${qs}`, { headers: headers() });
  return parse(res);
}

/**
 * Unbind a LINE binding.
 * @param {number|string} bindingId
 * @returns {Promise<object>}
 */
export async function unbindBinding(bindingId) {
  const res = await fetch(`${API}/bindings/${bindingId}`, {
    method: 'DELETE',
    headers: headers(),
  });
  return parse(res);
}
