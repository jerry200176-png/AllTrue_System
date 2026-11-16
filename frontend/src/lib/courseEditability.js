import { REPORT_STATUS_LABELS } from './courseMoneyState.js';

// The time-only shortcut cannot preserve each lesson's length when a mixed cadence is remapped.
export function hasUniformFixedSlotDuration(course) {
  const hours = Number(course?.duration_hours);
  const minutes = Number(course?.SessionDuration);
  // duration_hours is rounded by the index serializer (75min -> 1.3h); require raw exact evidence.
  if (!Number.isFinite(hours) || hours < 0.5 || !Number.isInteger(hours * 2) || minutes !== hours * 60) return false;
  if (!Array.isArray(course?.day_time_slots)) return false;
  for (let i = 1; i <= 6; i += 1) {
    if (!(Number(course[`week${i}`]) >= 1 && Number(course[`week${i}`]) <= 7) || !course[`time${i}`]) continue;
    const raw = course[`duration${i}`];
    if (raw != null && Number(raw) !== 0 && Number(raw) !== minutes) return false;
  }
  return course.day_time_slots.every((slot) => {
    if (!slot || typeof slot !== 'object') return false;
    const slotHours = slot.duration_hours == null ? hours : Number(slot.duration_hours);
    const slotMinutes = slot.duration_minutes == null ? hours * 60 : Number(slot.duration_minutes);
    return slotHours === hours && slotMinutes === hours * 60;
  });
}
const NEXT_STEP_LABELS = {
  billing_correction: '堂數若要更正，請改走「合約／堂次調整」；要加之後的課請用「續課」。',
  edit_charge_only: '堂數維持不變，請在一般編輯畫面只調整總費用。',
  void_payment: '若收款是誤登錄，請先到收費頁作廢帳單。',
  payment_report: '請先到繳費回報處理或作廢該筆回報。',
  package_adjustment: '請從方案調整入口修改共用堂數。',
  reconcile_usage: '請先完成重複堂次／扣堂對帳。',
  new_contract: '若需改變已使用的合約，請結案後另開新課程。',
  course_transfer: '要從某天起改科目、老師或時段，請改用「轉課」：之前的上課與扣堂紀錄留在原課程，之後的堂次轉到新合約。',
};

const ERROR_NEXT_STEPS = {
  billing_contract_locked: 'billing_correction',
  payment_record_locked: 'void_payment',
  billing_correction_payment_locked: 'void_payment',
  billing_correction_payment_report_locked: 'payment_report',
  billing_correction_below_observed_usage: 'edit_charge_only',
  billing_correction_package_forbidden: 'package_adjustment',
};

const FIELD_LABELS = {
  sessions_purchased: '購買堂數',
  standard_lesson_minutes: '標準堂長',
  deduction_basis: '扣堂方式',
  paid_at: '繳費日期',
  remaining_sessions: '剩餘堂數',
};

const ACTION_LABELS = {
  billing_correction: '更正未付款堂數',
  transfer_sessions: '搬移上課紀錄',
  void_payment: '查看帳單與作廢流程',
  payment_report: '查看帳單與繳費回報',
  package_adjustment: '改走方案調整',
  reconcile_usage: '先完成堂數對帳',
  new_contract: '結案後建立新課程',
  course_transfer: '改用轉課',
};

const ACTION_DESCRIPTIONS = {
  billing_correction: '只調整尚未收款的堂數；已上課紀錄會保留。',
  transfer_sessions: '把已上課、點名與評量紀錄搬到正確合約，不改金額。',
  void_payment: '先在帳單與對帳查看收款，再依權限作廢或更正。',
  payment_report: `先處理「${REPORT_STATUS_LABELS.pending}」的繳費回報，完成後再回來編輯。`,
  package_adjustment: '共用方案的堂數要由方案池管理，不能單獨改這門課。',
  reconcile_usage: '先釐清課堂狀態與扣堂紀錄的差異，再決定帳務變更。',
  new_contract: '已使用的合約不直接改寫；需要新條件時建立新課程。',
  course_transfer: '從某天起改科目／老師／時段；之前的上課紀錄留在原課程。',
};

export function editabilityNextStepLabel(nextStep) {
  return NEXT_STEP_LABELS[nextStep] || '';
}

export function editabilityFieldLabel(field) {
  return FIELD_LABELS[field] || field;
}

export function editabilityActionLabel(action) {
  return ACTION_LABELS[action] || '';
}

export function editabilityActionDescription(action) {
  return ACTION_DESCRIPTIONS[action] || '';
}

export function editabilityNextStepForError(error) {
  return error?.next_step || ERROR_NEXT_STEPS[error?.code] || '';
}

export function editabilityReasonSummary(reason) {
  if (!reason) return '';
  const nextStep = editabilityNextStepLabel(reason.next_step);
  return nextStep ? `${reason.message} ${nextStep}` : String(reason.message || '');
}
