const textValue = (value) => String(value ?? '').trim();

const HOMEWORK_LABELS = {
  completed: '已完成',
  partial: '部分完成',
  incomplete: '未完成',
  missing: '未攜帶',
};
const PERFORMANCE_LABELS = {
  good: '良好',
  average: '普通',
  bad: '需關注',
};

/**
 * Build the read-only content surface used by the list/card preview.
 * This deliberately contains no status or permission decisions: those remain
 * owned by LearningRecordsPage and the API response.
 */
export const buildLearningRecordPreview = (record = {}) => {
  const details = [
    ['授課進度', record.Progress],
    ['本次作業範圍', record.NextHomework],
    ['下次週考範圍', record.NextWeekTestScope],
    ['學習進度與家長溝通', record.Comment],
  ]
    .map(([label, value]) => ({ label, value: textValue(value) }))
    .filter(({ value }) => value);

  const signals = [
    { label: '作業', value: HOMEWORK_LABELS[textValue(record.HomeworkStatus)] || textValue(record.HomeworkStatus) },
    { label: '週考', value: textValue(record.QuizScore) },
    { label: '上課狀況', value: PERFORMANCE_LABELS[textValue(record.Performance)] || textValue(record.Performance) },
  ].filter(({ value }) => value);

  return {
    details,
    signals,
    hasContent: details.length > 0 || signals.length > 0,
  };
};
