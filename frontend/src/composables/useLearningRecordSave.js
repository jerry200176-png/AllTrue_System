export function extractLearningRecordResponse(payload, snapshot) {
  const positiveId = value => Number.isInteger(Number(value)) && Number(value) > 0;
  if (!payload || !positiveId(payload.id)) return null;
  const same = key => positiveId(snapshot[key]) && positiveId(payload[key]) && Number(payload[key]) === Number(snapshot[key]);
  if (!same('StudentID') || !same('TeacherID')) return null;
  if (positiveId(snapshot.id) && !same('id')) return null;
  if (positiveId(snapshot.ClassSessionID) && !same('ClassSessionID')) return null;
  if (!['pending', 'approved', 'rejected', 'changes_requested'].includes(payload.Status)) return null;
  return payload;
}

export async function saveLearningRecord({ fetchImpl = fetch, url, token, snapshot }) {
  try {
    const response = await fetchImpl(url, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(snapshot),
    });
    const body = await response.json().catch(() => null);
    if (response.ok) {
      const record = extractLearningRecordResponse(body, snapshot);
      return record ? { ok: true, record } : { ok: false, kind: 'malformed', message: '無法確認儲存結果，輸入已保留。請先重新查詢此堂紀錄，避免重複提交。' };
    }
    return { ok: false, kind: response.status === 409 ? 'conflict' : 'http',
      message: `${body?.message || `儲存失敗（${response.status}）`}；輸入已保留。${response.status === 409 ? '請關閉後重新查詢此堂紀錄，不會自動覆蓋。' : '請稍後再試。'}` };
  } catch {
    return { ok: false, kind: 'network', message: '連線中斷，無法確認儲存結果；輸入已保留。請先重新查詢此堂紀錄。' };
  }
}

/** Per mounted page, never share an in-flight response across accounts or pages. */
export function createLearningRecordSaver() {
  let pending = null;
  return options => {
    if (pending) return pending;
    pending = saveLearningRecord(options).finally(() => { pending = null; });
    return pending;
  };
}
