const SCHEDULE_FIELD_LABELS = {
  end_date: '課程結束日',
  course_start_date: '開課日',
  days_of_week: '固定上課星期',
  monthly_sessions: '本月預排堂數',
  session_plan: '排課清單',
};

function mapScheduleValidationMessage(field, text) {
  const normalized = String(text || '').trim();
  if (!normalized) return '';

  if (
    field === 'end_date'
    && (normalized.includes('指定期間內無任何排課日') || normalized.includes('無符合的課堂日期'))
  ) {
    return '選擇的期間內沒有符合固定星期的上課日，請調整結束日或上課星期。';
  }

  const label = SCHEDULE_FIELD_LABELS[field];
  return label ? `${label}：${normalized}` : normalized;
}

export function normalizeUniversalScheduleErrorMessage(body, rawText, status) {
  const validationPairs = body?.errors && typeof body.errors === 'object'
    ? Object.entries(body.errors)
      .map(([field, messages]) => {
        const merged = Array.isArray(messages) ? messages.join('、') : String(messages || '');
        return mapScheduleValidationMessage(field, merged);
      })
      .filter(Boolean)
    : [];
  const validation = validationPairs.join(' | ');
  const textFallback = String(rawText || '').trim();
  const compactText = textFallback.replace(/\s+/g, ' ').slice(0, 220);
  return validation
    || body?.message
    || body?.error
    || (compactText ? `排課請求失敗 (HTTP ${status}) - ${compactText}` : `排課請求失敗 (HTTP ${status})`);
}
