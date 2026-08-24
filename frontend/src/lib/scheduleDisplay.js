/**
 * Schedule domain display layer（排課／調課／補課 — 同一套使用者語言）.
 *
 * Directors must see actionable Chinese guidance —
 * never snake_case field names, HTTP status codes, raw English payloads,
 * or internal ledger phrases like「原堂改期／新堂排入」.
 */

import { formatDirectorPersonName } from './studentClassDisplay.js';

/** Cross-page: same label on RescheduleModal + SessionEditModal. */
export const MAKEUP_SLOT_QUERY_LABEL = '查詢老師可補課時段';

/** Cross-page: reschedule modal short description. */
export const RESCHEDULE_ACTION_DESC = '將原本的課程改到新的日期時間';

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

function trimStr(v) {
  return String(v ?? '').trim();
}

function whoLabel({ studentName = '', subject = '' } = {}) {
  const who = trimStr(studentName);
  const subj = trimStr(subject);
  if (who && subj) return `${who}的${subj}`;
  if (who) return who;
  if (subj) return subj;
  return '';
}

function slotLabel(date, start, end) {
  const d = trimStr(date);
  const s = trimStr(start);
  const e = trimStr(end);
  if (d && s && e) return `${d} ${s}~${e}`;
  if (d && s) return `${d} ${s}`;
  return d || s || '';
}

/**
 * User Task: 調課 — 送出前影響說明（人話，無內部帳本術語）.
 */
export function formatRescheduleImpactLines({
  originalDate = '',
  originalStart = '',
  originalEnd = '',
  newDate = '',
  newStart = '',
  newEnd = '',
} = {}) {
  return [
    `原本：${slotLabel(originalDate, originalStart, originalEnd) || '—'}`,
    `改為：${slotLabel(newDate, newStart, newEnd) || '—'}`,
    '原時段會空出；之後可在課程管理查看這次調課',
  ];
}

/** Native confirm() body — CourseManagement reschedule path. */
export function formatRescheduleConfirmDialog(opts = {}) {
  const who = whoLabel(opts);
  const head = who ? `${who} — 調課確認` : '調課確認';
  const lines = formatRescheduleImpactLines(opts);
  return `${head}\n\n${lines.join('\n')}\n\n確定改到新時間？`;
}

/** Success alert — same wording on calendar / course-mgmt / session-edit. */
export function formatRescheduleSuccessMessage(opts = {}) {
  const who = whoLabel(opts);
  const from = slotLabel(opts.originalDate, opts.originalStart, opts.originalEnd);
  const to = slotLabel(opts.newDate, opts.newStart, opts.newEnd);
  if (who && from && to) return `${who}已從 ${from} 改到 ${to}。`;
  if (who && to) return `${who}已改到 ${to}。`;
  if (from && to) return `已從 ${from} 改到 ${to}。`;
  return '調課完成。';
}

/** Conflict list — never「#student_id」. */
export function formatRescheduleConflictStudents(details) {
  const list = Array.isArray(details) ? details : [];
  const labels = list
    .map((d) => {
      const name = formatDirectorPersonName({
        student_name: d?.student_name || d?.name,
        name: d?.name,
      });
      if (!name || name === '名單上的學生') return '';
      // #2006：只顯示姓名時，主任分不出是「同一堂」還是這位學生的另一筆課程
      // （常見於續約時舊課程沒關閉，跟新課程同時段並存）。補上科目/期別。
      const courseLabel = [d?.subject_name, d?.course_period].filter(Boolean).join('・');
      return courseLabel ? `${name}（${courseLabel}）` : name;
    })
    .filter(Boolean);
  if (labels.length) return `此時段已有：${labels.join('、')}`;
  if (list.length) return '目標時段已有其他學生，請換一個時段再試。';
  return '';
}

/** Soften engineer failure crumbs shared by calendar / CM / session-edit. */
export function humanizeRescheduleFailure(raw) {
  const s = trimStr(raw);
  if (!s) return '調課沒有完成，請稍後再試。';
  if (/無法寫入原堂次|原堂次紀錄/.test(s)) {
    return '無法更新原本的上課時間，請稍後再試。';
  }
  if (/無法寫入新堂次/.test(s)) {
    return '無法排入新的上課時間，請換一個時段或稍後再試。';
  }
  if (looksLikeEngineerPayload(s)) {
    return '調課沒有完成，請確認新日期與時間後再試。';
  }
  return s;
}
