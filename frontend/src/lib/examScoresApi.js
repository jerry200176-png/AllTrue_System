// 段考 score save / history calls plus Chinese messages for the API's 409 / 422 / 403 errors.
// `api` is the page's authed fetch helper: (path, options) => body, throwing Error with .status / .raw.

const MESSAGES = [
  [/Entry deadline has passed/i, '已截止，請找主任更正'],
  [/Round is not open for entry/i, '此段考目前不開放登分，請找主任更正'],
  [/Publish the round before/i, '請先發布段考再登分'],
  [/Score is required unless/i, '請填分數，或勾選缺考'],
  [/Score must be between 0 and ([\d.]+)/i, (m) => `分數要在 0 到 ${m[1]} 之間`],
  [/Unknown subject/i, '找不到這個科目，請重新整理'],
  [/Round has no subjects/i, '此段考沒有科目'],
];

export function examErrorMessage(err) {
  const raw = String(err?.raw ?? err?.message ?? '');
  for (const [re, out] of MESSAGES) {
    const m = raw.match(re);
    if (m) return typeof out === 'function' ? out(m) : out;
  }
  if (/[一-鿿]/.test(raw)) return raw;
  const status = err?.status;
  if (status === 403) return '你沒有這位學生的登分權限';
  if (status === 404) return '找不到這個段考，請重新整理';
  if (status === 409) return '目前不能登分，請找主任更正';
  if (status === 422) return '資料不正確，請檢查後再存';
  return raw || '儲存失敗，請再試一次';
}

export function saveScores(api, roundId, scores, reason) {
  return api(`/exam-rounds/${roundId}/scores`, {
    method: 'POST',
    body: JSON.stringify({ scores, ...(reason ? { reason } : {}) }),
  });
}

export async function loadScoreHistory(api, roundId, studentId) {
  const res = await api(`/exam-rounds/${roundId}/history?student_id=${encodeURIComponent(studentId)}`);
  return res.data || [];
}

const ACTION_LABELS = { score_created: '新增', score_updated: '修改', score_corrected: '主任更正', score_deleted: '清除' };
export const historyActionLabel = (a) => ACTION_LABELS[a] || a;

export function describeScore(v) {
  if (!v) return '（空白）';
  return v.is_absent ? '缺考' : (v.score ?? '（空白）');
}
