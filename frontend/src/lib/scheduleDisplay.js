/**
 * Schedule / 排課 display layer.
 *
 * Directors creating courses must see actionable Chinese guidance —
 * never snake_case field names, HTTP status codes, or raw English payloads.
 */

export const SCHEDULE_FIELD_LABELS = {
  end_date: '課程結束日',
  course_start_date: '開課日',
  days_of_week: '固定上課星期',
  monthly_sessions: '本月預排堂數',
  session_plan: '排課清單',
  start_time: '開始時間',
  duration_minutes: '上課時長',
  total_classes: '購買堂數',
  teacher_id: '老師',
  student_id: '學生',
  subject: '科目',
  payment_type: '計費方式',
  settlement_day: '結算日',
};

const FIELD_TOKEN_ENTRIES = Object.entries(SCHEDULE_FIELD_LABELS)
  .sort((a, b) => b[0].length - a[0].length);

/** Replace backend snake_case tokens inside a message with Chinese labels. */
export function humanizeScheduleFieldTokens(text) {
  let out = String(text || '');
  for (const [token, label] of FIELD_TOKEN_ENTRIES) {
    out = out.replace(new RegExp(`\\b${token}\\b`, 'g'), label);
  }
  return out;
}

function looksLikeEngineerPayload(text) {
  const s = String(text || '');
  if (!s.trim()) return true;
  if (/SQLSTATE|Exception|Stack trace|Integrity constraint|QueryException/i.test(s)) return true;
  if (/^\s*\{[\s\S]*\}\s*$/.test(s)) return true;
  // Pure English / status crumbs without Chinese guidance.
  if (!/[\u4e00-\u9fff]/.test(s) && /error|failed|internal|forbidden|unauthorized|exception/i.test(s)) {
    return true;
  }
  return false;
}

function mapScheduleValidationMessage(field, text) {
  const raw = String(text || '').trim();
  if (!raw) return '';

  if (
    field === 'end_date'
    && (raw.includes('指定期間內無任何排課日') || raw.includes('無符合的課堂日期'))
  ) {
    return '選擇的期間內沒有符合固定星期的上課日，請調整結束日或上課星期。';
  }

  const label = SCHEDULE_FIELD_LABELS[field];
  if (label && /必填/.test(raw) && /大於\s*0|大於0/.test(raw)) {
    return `請填寫${label}，且須大於 0。`;
  }

  const normalized = humanizeScheduleFieldTokens(raw);
  if (!label) return normalized;
  if (normalized.startsWith(label)) return normalized;
  return `${label}：${normalized}`;
}

/**
 * Normalize create-schedule API failures for director UI.
 * Prefer structured validation → body message → generic Chinese fallback.
 * Never lead with HTTP status or English stack crumbs.
 */
export function formatScheduleErrorMessage(body, rawText, status) {
  const validationPairs = body?.errors && typeof body.errors === 'object'
    ? Object.entries(body.errors)
      .map(([field, messages]) => {
        const merged = Array.isArray(messages) ? messages.join('、') : String(messages || '');
        return mapScheduleValidationMessage(field, merged);
      })
      .filter(Boolean)
    : [];
  const validation = validationPairs.join(' | ');
  if (validation) return validation;

  const bodyMsg = humanizeScheduleFieldTokens(
    String(body?.message || body?.error || '').trim(),
  );
  if (bodyMsg && !looksLikeEngineerPayload(bodyMsg)) return bodyMsg;

  const textFallback = humanizeScheduleFieldTokens(String(rawText || '').trim())
    .replace(/\s+/g, ' ')
    .slice(0, 220);
  if (textFallback && !looksLikeEngineerPayload(textFallback)) {
    return textFallback;
  }

  if (Number(status) === 403) {
    return '沒有權限建立這門課，請確認分校與帳號後再試。';
  }
  if (Number(status) === 422) {
    return '排課資料不完整或不符合規則，請檢查學生、老師、日期與上課星期。';
  }
  return '排課沒有完成，請檢查學生、老師、日期與上課星期後再試一次。';
}
