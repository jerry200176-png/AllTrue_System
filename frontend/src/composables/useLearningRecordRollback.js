import { reactive } from 'vue';
import { httpFailureMessage } from '../lib/humanizeApiErrorMessage';

/** One mutation lane per record across the page's list, card and editor. */
export function useLearningRecordRollback({ canRollback, getContext, getToken, onSuccess }) {
  const pendingIds = reactive(new Set());
  const isRollingBack = record => pendingIds.has(String(record?.id));

  const rollbackApproval = async (record) => {
    if (!record?.id || !canRollback(record) || isRollingBack(record)) return;
    const id = String(record.id);
    const context = getContext();
    const isCurrent = () => context === getContext();
    pendingIds.add(id);
    try {
      const note = window.prompt('可輸入退回待審原因（選填）：', '');
      if (note === null) return;
      const token = await getToken();
      // Token lookup can outlive the window/account/campus that initiated it.
      if (!isCurrent() || !canRollback(record)) return;
      if (!token) throw new Error('登入狀態無法確認');
      const res = await fetch(`/api/v1/learning-records/${id}/rollback-approval`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ ReviewNote: note || null }),
      });
      const body = await res.json().catch(() => null);
      if (!isCurrent()) return;
      if (!res.ok) throw new Error(httpFailureMessage(res.status, body || {}));
      if (String(body?.id) !== id || body?.Status !== 'pending') {
        throw new Error('無法確認退回結果，請重新查詢此筆評量');
      }
      // Never replace the editor with a persisted snapshot: it may be dirty.
      onSuccess(record, {
        Status: body.Status, ReviewNote: body.ReviewNote ?? null,
        ApprovedBy: null, ApprovedAt: null,
      });
    } catch (error) {
      if (isCurrent()) window.alert(`退回待審失敗: ${error.message || '連線中斷，請重新查詢此筆評量'}`);
    } finally {
      pendingIds.delete(id);
    }
  };
  return { rollbackApproval, isRollingBack };
}
