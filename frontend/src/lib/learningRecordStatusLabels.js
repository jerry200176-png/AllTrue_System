/**
 * Director vs teacher assessment status copy.
 * Director surfaces must name the dimension (content fill vs review)
 * so short pills like「未填」「待審核」are not mistaken for attendance.
 */

const TEACHER_REVIEW_LABELS = {
  pending: '待審核',
  approved: '已核准',
  rejected: '已退回',
  changes_requested: '需修改',
};

const DIRECTOR_REVIEW_LABELS = {
  pending: '審核：待主任核准',
  approved: '審核：已核准',
  rejected: '審核：已退回',
  changes_requested: '審核：老師需修改',
};

/** Ambiguous short pills previously shown on director cards/tables (#2715). */
export const AMBIGUOUS_DIRECTOR_FILL_LABELS = Object.freeze(['未填', '已填']);
export const AMBIGUOUS_DIRECTOR_REVIEW_LABELS = Object.freeze([
  '待審核',
  '已核准',
  '已退回',
  '需修改',
]);

export function fillStatusLabel(hasBody, { director = false } = {}) {
  if (director) {
    return hasBody ? '評量內容已填' : '評量內容未填';
  }
  return hasBody ? '已填' : '未填';
}

export function reviewStatusLabel(status, { director = false } = {}) {
  const map = director ? DIRECTOR_REVIEW_LABELS : TEACHER_REVIEW_LABELS;
  return map[status] || status;
}

export function isAmbiguousDirectorFillLabel(label) {
  return AMBIGUOUS_DIRECTOR_FILL_LABELS.includes(String(label || ''));
}

export function isAmbiguousDirectorReviewLabel(label) {
  return AMBIGUOUS_DIRECTOR_REVIEW_LABELS.includes(String(label || ''));
}
