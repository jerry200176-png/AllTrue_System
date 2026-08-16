/**
 * Map common English API error payloads to director/teacher Chinese.
 * Keep matching exact known strings; unknown messages pass through.
 */
const EXACT = {
  Forbidden: '沒有權限執行此操作',
  Unauthorized: '請重新登入',
  'User not found': '找不到使用者',
  'Student not found': '找不到學生',
  'Student class not found': '找不到課程',
  'Payment report not found': '找不到收據',
  'Invalid payment_method': '繳費方式無效',
  'teacher_id required': '請指定老師',
  'branch_id required': '請指定分校',
  'Campus required': '請指定分校',
  'Teacher not linked': '尚未綁定老師帳號',
  'Learning record context missing': '學習紀錄資料不完整',
  'Forbidden: no campus assignment': '尚未分配分校，無法操作',
  'Forbidden: branch not accessible': '無法存取此分校',
  ok: '完成',
};

const PATTERNS = [
  [/^Forbidden:/i, '沒有權限執行此操作'],
  [/\bSQLSTATE\b|QueryException|Integrity constraint/i, '系統忙碌，請稍後再試或聯絡總部'],
];

export function humanizeApiErrorMessage(message, fallback = '操作失敗，請稍後再試') {
  const raw = String(message ?? '').trim();
  if (!raw) return fallback;
  if (Object.prototype.hasOwnProperty.call(EXACT, raw)) return EXACT[raw];
  for (const [re, label] of PATTERNS) {
    if (re.test(raw)) return label;
  }
  // Bare snake_case / camel English field errors → generic
  if (/^[A-Za-z][A-Za-z0-9_ .:-]{0,80}$/.test(raw) && /[A-Za-z]{3,}/.test(raw) && !/[\u4e00-\u9fff]/.test(raw)) {
    if (/not found/i.test(raw)) return '找不到資料';
    if (/required/i.test(raw)) return '請確認必填資料後再試';
    if (/invalid/i.test(raw)) return '資料格式不正確';
  }
  return raw;
}
