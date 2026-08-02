/**
 * Keep the director review surface independent from the legacy grouped-history
 * layout.  A review queue is ordered by work that needs a human decision,
 * while the history view remains chronological.
 */
export function learningReviewPriority(record = {}) {
  const status = String(record.Status || '').toLowerCase();
  if (status === 'changes_requested') return 0;
  if (status === 'pending') return 1;
  return 2;
}

export function buildLearningReviewQueue(records = []) {
  return [...records]
    .filter((record) => ['pending', 'changes_requested'].includes(String(record?.Status || '').toLowerCase()))
    .sort((a, b) => {
      const priority = learningReviewPriority(a) - learningReviewPriority(b);
      if (priority) return priority;
      const aDate = `${a?.SessionDate || ''} ${a?.StartTime || ''}`;
      const bDate = `${b?.SessionDate || ''} ${b?.StartTime || ''}`;
      return bDate.localeCompare(aDate);
    });
}

export function learningReviewPreview(record = {}) {
  const text = String(record.Progress || record.body || '').trim();
  return text || '尚未填寫授課進度';
}
