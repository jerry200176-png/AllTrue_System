export function formatAssessmentProgressDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('zh-TW', {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
  }).format(date);
}

export function assessmentProgressScoreLabel(item = {}) {
  if (item.score === null || item.score === undefined || item.max_score === null || item.max_score === undefined) return '—';
  const score = Number(item.score);
  const max = Number(item.max_score);
  if (!Number.isFinite(score) || !Number.isFinite(max)) return '—';
  return `${score}/${max} 分`;
}

export function assessmentProgressPercentLabel(item = {}) {
  if (item.percent === null || item.percent === undefined || item.percent === '') return '—';
  const percent = Number(item.percent);
  return Number.isFinite(percent) ? `${percent}%` : '—';
}
