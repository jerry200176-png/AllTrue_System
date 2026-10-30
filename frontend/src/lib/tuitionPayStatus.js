// in-app #447: wording for the 當月學收 month-scoped payment status (values come from the backend verdict).
export const PAY_STATUS_LABELS = { paid: '已收', partial: '部分', unpaid: '未收', review: '待確認', waived: '已免收' };
export const payStatusLabel = (s) => PAY_STATUS_LABELS[s] || '—';

const money = (n) => Number(n || 0).toLocaleString('zh-TW');

// allCollected only when something is due and nothing is outstanding.
export function collectionSummary(summary = {}) {
  const people = Number(summary.unpaid_students || 0);
  const review = Number(summary.review_students || 0);
  // 待確認 rows (unconfirmed evidence) never count as collected.
  const allCollected = Number(summary.total_outstanding || 0) <= 0 && people === 0 && review === 0 && Number(summary.total_due || 0) > 0;
  const base = `已收 ${money(summary.total_collected)} / 應收 ${money(summary.total_due)}，未收 ${people} 人`;
  return {
    allCollected,
    text: allCollected ? '本月學收已收齊' : review > 0 ? `${base}，另有 ${review} 人待確認` : base,
  };
}
