/**
 * #1130 P2 審核 API — 查詢 / 逐組決策並執行
 *
 * 端點（詳見技術規格 NOTE id: 408d686280feb7668d86f7e2 第五節）：
 *   GET   /api/v1/admin/duplicate-sessions/p2-review
 *   PATCH /api/v1/admin/duplicate-sessions/p2-review/{group_id}
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
 * @param {string} [opts.status] pending | all，預設 pending
 * @returns {Promise<{ groups: Array }>}
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
 * 逐組提交審核決策（同時執行取消非保留側的堂次）。
 * @param {string} groupId — group id（來自 GET response 的 id 欄位）
 * @param {object} payload
 * @param {number} payload.keep_student_class_id — 要保留的 StudentClassID
 * @param {string} [payload.reason] — 決策原因
 * @returns {Promise<{ cancelled_count: number, kept_session_ids: number[] }>}
 */
export async function patchP2ReviewGroup(groupId, { keep_student_class_id, reason } = {}) {
  const res = await fetch(`${API}/admin/duplicate-sessions/p2-review/${encodeURIComponent(groupId)}`, {
    method: 'PATCH',
    headers: headers(),
    body: JSON.stringify({ keep_student_class_id, reason }),
  });
  return parse(res);
}

export const STATUS_LABELS = {
  pending: '待審核',
  executed: '已處理',
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
