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
  'Course not found': '找不到課程',
  'Payment report not found': '找不到收據',
  'Binding not found': '找不到綁定資料',
  'Campus not found': '找不到分校',
  'Not found': '找不到資料',
  'Invalid payment_method': '繳費方式無效',
  'Invalid JSON': '資料格式不正確',
  'Invalid LINE authentication': 'LINE 驗證失敗，請重新登入',
  'LINE authentication unavailable': '目前無法用 LINE 登入，請稍後再試',
  'teacher_id required': '請指定老師',
  'branch_id required': '請指定分校',
  'branch_id is required': '請指定分校',
  'Campus required': '請指定分校',
  'Teacher not linked': '尚未綁定老師帳號',
  'Learning record context missing': '學習紀錄資料不完整',
  'Forbidden: no campus assignment': '尚未分配分校，無法操作',
  'Forbidden: branch not accessible': '無法存取此分校',
  'Director confirmation is required first': '請先由主任確認',
  'Only headquarters can approve deductions': '僅總部可核准扣款',
  'Only headquarters can approve admin allowances': '僅總部可核准行政加給',
  'Only headquarters can approve cash adjustments': '僅總部可核准現金加扣款',
  'Only headquarters can approve salary profiles': '僅總部可核准底薪',
  'leave event requires hours': '請假請填時數',
  'ends_on must be on or after starts_on': '結束日不可早於開始日',
  'student is outside the selected branch': '學生不屬於所選分校',
  'Teacher scheduling conflict detected': '老師此時段已有課',
  'Course deleted successfully': '課程已刪除',
  'Server error': '系統忙碌，請稍後再試',
  ok: '完成',
};

const PATTERNS = [
  [/^Forbidden\b/i, '沒有權限執行此操作'],
  [/^Unauthorized\b/i, '請重新登入'],
  [/\bSQLSTATE\b|QueryException|Integrity constraint/i, '系統忙碌，請稍後再試或聯絡總部'],
  [/^Request failed \(\d+\)$/i, '操作失敗，請稍後再試'],
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
