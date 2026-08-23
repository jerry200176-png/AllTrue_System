const NEXT_STEP_LABELS = {
  billing_correction: '堂數若要減少，請改走「合約／堂次調整」。',
  edit_charge_only: '堂數維持不變，請在一般編輯畫面只調整總費用。',
  void_payment: '若收款是誤登錄，請先到收費頁作廢帳單。',
  payment_report: '請先到繳費回報處理或作廢該筆回報。',
  package_adjustment: '請從方案調整入口修改共用堂數。',
  reconcile_usage: '請先完成重複堂次／扣堂對帳。',
  new_contract: '若需改變已使用的合約，請結案後另開新課程。',
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

export function editabilityNextStepLabel(nextStep) {
  return NEXT_STEP_LABELS[nextStep] || '';
}

export function editabilityFieldLabel(field) {
  return FIELD_LABELS[field] || field;
}

export function editabilityNextStepForError(error) {
  return error?.next_step || ERROR_NEXT_STEPS[error?.code] || '';
}

export function editabilityReasonSummary(reason) {
  if (!reason) return '';
  const nextStep = editabilityNextStepLabel(reason.next_step);
  return nextStep ? `${reason.message} ${nextStep}` : String(reason.message || '');
}
