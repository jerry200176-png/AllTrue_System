/**
 * Bug #173 P2 審核 API — 查詢 / 決策 / 執行
 *
 * 端點（詳見技術規格 NOTE id: 203dd1c683f81de8dd4ea4ef 第四節）：
 *   GET  /api/v1/admin/duplicate-sessions/p2-review
 *   POST /api/v1/admin/duplicate-sessions/decide
 *   POST /api/v1/admin/duplicate-sessions/execute
 */

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

/**
 * Parse response, throw on non-OK with server message.
 */
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
 * 取得 P2 審核清單。
 * @param {object} [opts]
 * @param {number|string} [opts.campusId] 分校 ID（director 必填）
 * @param {string} [opts.status] pending | decided | executed | all，預設 pending
 * @returns {Promise<{ data: { groups: Array, total: number, p2_review_groups: number } }>}
 */
export async function fetchP2ReviewGroups({ campusId, status = 'pending' } = {}) {
  const params = new URLSearchParams();
  if (campusId) params.set('campus_id', String(campusId));
  if (status && status !== 'all') params.set('status', status);
  const qs = params.toString() ? `?${params}` : '';
  const res = await fetch(`${API}/admin/duplicate-sessions/p2-review${qs}`, { headers: headers() });
  return parse(res);
}

/**
 * 儲存審核決策。
 * @param {{ student_id: number, session_date: string, start_time: string, keeper_sc_id: number }[]} groups
 * @returns {Promise<{ data: { saved: number, review_ids: number[] } }>}
 */
export async function saveDecisions(groups) {
  const res = await fetch(`${API}/admin/duplicate-sessions/decide`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ groups }),
  });
  return parse(res);
}

/**
 * 批次執行已審核決策（僅 super_admin）。
 * @param {object} [opts]
 * @param {number[]} [opts.reviewIds] 不傳 = 執行所有 decided
 * @param {number} [opts.campusId]
 * @returns {Promise<{ data: { executed: number, skipped: number, details: Array } }>}
 */
export async function executeDecisions({ reviewIds, campusId } = {}) {
  const body = {};
  if (reviewIds && reviewIds.length > 0) body.review_ids = reviewIds;
  if (campusId) body.campus_id = campusId;
  const res = await fetch(`${API}/admin/duplicate-sessions/execute`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify(body),
  });
  return parse(res);
}

export const STATUS_LABELS = {
  pending: '待審核',
  decided: '已決策',
  executed: '已執行',
};

export const SESSION_STATUS_LABELS = {
  scheduled: '已排定',
  attended: '已出席',
  completed: '已完成',
  late: '遲到',
  leave: '請假',
  cancelled: '已取消',
  absent: '缺席',
};
