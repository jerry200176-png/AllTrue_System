export function shouldApplyLearningRecordsDefaultWindow({
  days,
  startDate,
  defaultWindowDisabled,
  isTeacher,
  teacherFilterTab,
  teacherPriorityFilter,
  reviewTab,
} = {}) {
  if (!(Number(days) > 0)) return false;
  if (startDate) return false;
  if (defaultWindowDisabled) return false;

  if (isTeacher) {
    return ![
      'pending',
      'changes_requested',
    ].includes(String(teacherFilterTab || ''))
      && !['unfilled', 'changes_requested'].includes(String(teacherPriorityFilter || ''));
  }

  return !['pending', 'changes_requested'].includes(String(reviewTab || ''));
}

export function resolveLearningRecordsDefaultWindowStart(options = {}) {
  if (!shouldApplyLearningRecordsDefaultWindow(options)) return '';
  const d = new Date(options.now || Date.now());
  d.setDate(d.getDate() - Number(options.days || 0));
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}
