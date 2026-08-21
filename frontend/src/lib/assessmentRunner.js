export const QUESTION_TYPES = new Set(['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'short_answer']);

export function answerMapFromAttempt(attempt) {
  return Object.fromEntries((attempt?.answers || []).map((answer) => [String(answer.question_id), answer.answer]));
}

export function buildAnswerPayload(questions, answers) {
  return (questions || []).map((question) => ({
    question_id: Number(question.id),
    answer: answers?.[String(question.id)] ?? null,
  }));
}

export function isManualQuestion(question) {
  return question?.question_type === 'short_answer';
}

export function attemptStatusLabel(status) {
  return { in_progress: '作答中', submitted: '待人工複核', reviewed: '已完成', voided: '已作廢' }[status] || status;
}
