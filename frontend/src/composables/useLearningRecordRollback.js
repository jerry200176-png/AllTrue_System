import { reactive } from 'vue';
import { httpFailureMessage } from '../lib/humanizeApiErrorMessage';

/** Per-owner mutation lanes survive modal changes; editor writes have their own epoch. */
export function useLearningRecordRollback({ canRollback, getContext, getOwner, getToken, onSuccess, reconcile }) {
  const pending = reactive(new Set());
  const uncertain = reactive(new Map());
  const keyFor = record => `${getOwner()}:${record?.id}`;
  const isRollbackPending = record => pending.has(keyFor(record));
  const rollbackIssue = record => uncertain.get(keyFor(record)) || '';
  const isRollingBack = record => isRollbackPending(record) || Boolean(rollbackIssue(record));
  const unknownMessage = '無法確認退回結果；伺服器可能已完成操作。輸入已保留，請先重新查詢狀態，再決定是否重試。';

  const recheckRollback = async (record) => {
    const key = keyFor(record);
    if (!uncertain.has(key) || pending.has(key)) return;
    pending.add(key);
    try {
      if (await reconcile({ ...record })) uncertain.delete(key);
    } catch {
      // Keep the lane blocked until a validated read updates record and counts.
    } finally {
      pending.delete(key);
    }
  };

  const rollbackApproval = async (record) => {
    if (!record?.id || !canRollback(record) || isRollingBack(record)) return;
    const snapshot = { ...record };
    const key = keyFor(record);
    const context = getContext();
    const owner = getOwner();
    const editorIsCurrent = () => context === getContext();
    pending.add(key);
    let sent = false;
    let confirmed = false;
    try {
      const note = window.prompt('可輸入退回待審原因（選填）：', '');
      if (note === null) return;
      const token = await getToken();
      if (!editorIsCurrent() || !canRollback(record)) return;
      if (!token) throw new Error('登入狀態無法確認');
      sent = true;
      const res = await fetch(`/api/v1/learning-records/${snapshot.id}/rollback-approval`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ ReviewNote: note || null }),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok) {
        // A server error/timeout can occur after commit. Only explicit client refusals are definite.
        if (res.status >= 400 && res.status < 500 && ![408, 425, 429].includes(res.status)) {
          sent = false;
          if (editorIsCurrent()) window.alert(`退回待審未執行: ${httpFailureMessage(res.status, body || {})}`);
          return;
        }
        throw new Error('outcome unknown');
      }
      if (String(body?.id) !== String(snapshot.id) || body?.Status !== 'pending') throw new Error('outcome unknown');
      confirmed = true;
      // A committed result belongs to the page's data, even if its originating modal has closed.
      await onSuccess(snapshot, {
        Status: 'pending', ReviewNote: body.ReviewNote ?? null, ApprovedBy: null, ApprovedAt: null,
      }, editorIsCurrent, owner);
    } catch (error) {
      if (sent) uncertain.set(key, confirmed ? '已退回待審；狀態重新整理未完成。輸入已保留，請重新查詢狀態。' : unknownMessage);
      else if (editorIsCurrent()) window.alert(`退回待審未執行: ${error.message || '登入狀態無法確認'}`);
    } finally {
      pending.delete(key);
    }
  };
  return { rollbackApproval, isRollingBack, isRollbackPending, rollbackIssue, recheckRollback };
}
