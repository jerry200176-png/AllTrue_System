// 收據移到正確帳單: every refusal code of ReceiptReassignController (+ client-side ones) as one plain sentence and the next step.
// No codes or ids are shown. `kind` tells the dialog what is left to press: 'pick' (choose another bill), 'preview' (look again),
// 'reason' (fix the reason), 'hq' (回報給總部), 'end' (nothing to do here, only 關閉).
const hq = (text) => ({ kind: 'hq', text });
const end = (text) => ({ kind: 'end', text });
const pick = (text) => ({ kind: 'pick', text });

export const REFUSAL = {
  reassign_disabled: hq('這個分校還沒有開放「移到正確帳單」。請回報總部。'),
  reassign_bad_student: pick('學生選擇有誤，請重新選一位學生。'),
  reassign_report_not_found: end('找不到這張收據。請關閉後重新整理學生帳務。'),
  reassign_not_confirmed: end('這張收據已經不是「已確認」的狀態（可能剛被撤銷或移走）。請關閉後重新整理學生帳務。'),
  reassign_amount_invalid: hq('這張收據的金額不能移動。請回報總部對帳。'),
  reassign_amount_zero: end('這張收據金額為 0，沒有可以移動的款項。請直接關閉。'),
  reassign_source_unresolvable: hq('這張收據對應的帳單或課程已經找不到，這裡不能移動。請回報總部對帳。'),
  reassign_source_other_owner: hq('這張收據所在的帳單指向別位學生的課程，這裡不能移動。請回報總部對帳。'),
  reassign_source_package_mismatch: hq('這張收據所在課程的共用方案不屬於同一位學生或分校，這裡不能移動。請回報總部對帳。'),
  reassign_source_payment_other_report: hq('這張收據指到的收款屬於另一張收據，這裡不能移動。請回報總部對帳。'),
  reassign_source_not_applied: hq('這張收據的款項不在它綁定的帳單上（或已經沖回），這裡不能移動。請回報總部對帳。'),
  reassign_legacy_reversal_unreconciled: hq('這張帳單有舊的沖銷紀錄還沒對帳，這裡不能移動。請回報總部處理。'),
  reassign_cross_campus: pick('只能移到同一分校的帳單。請改選本分校的學生。'),
  reassign_same_bill: pick('收據已經在這張帳單上。請選另一張帳單。'),
  reassign_target_not_found: pick('找不到那張帳單。請重新選擇。'),
  reassign_target_voided: pick('這張帳單已作廢，不能把收款移過去。請選另一張帳單。'),
  reassign_target_other_owner: hq('這張帳單找不到對應的課程，或課程屬於別位學生。請回報總部對帳。'),
  reassign_target_not_collectable: pick('這張帳單的課程現在不能收款（例如輔導、免費或確認不收的課）。請選另一張帳單。'),
  reassign_target_overpay: pick('這張帳單放不下這筆收款（剩下要收的金額比收據少）。請選另一張帳單。'),
  reassign_target_package_paid: hq('這張帳單屬於已繳清的共用課程包，不能把收款移過去。請回報總部對帳。'),
  reassign_target_package_mismatch: hq('這張帳單的共用課程包不屬於同一位學生或分校。請回報總部對帳。'),
  reassign_target_package_unpriced: hq('這張帳單屬於沒有固定總價的共用課程包，無法確認移過去後算不算繳清。請回報總部處理。'),
  reassign_course_refunded: hq('這門課有退費紀錄，收據不能移動。請回報總部對帳。'),
  reassign_refund_or_transfer_exists: hq('其中一張帳單有退費或轉課紀錄，收據不能移動。請回報總部對帳。'),
  reassign_pending_report: end('其中一門課還有待確認的繳費回報。請先到收款紀錄按「確認入帳」或「退回」，再回來移動收據。'),
  reassign_state_changed: { kind: 'preview', text: '帳單資料剛剛有變動（或這張收據已經移過了）。請按「重新預覽」看最新結果，再決定要不要移動。' },
  reassign_state_hash_required: { kind: 'preview', text: '請先看過移動結果再送出。請按「重新預覽」。' },
  reassign_reason_required: { kind: 'reason', text: '請寫下為什麼要把收據移到這張帳單。' },
  reassign_reason_too_long: { kind: 'reason', text: '原因請寫在 500 字以內。' },
  forbidden: end('只有主任或總部可以移動收據，而且只能移動自己分校的收據。'),
  preview_failed: { kind: 'preview', text: '預覽沒有完成，收據沒有任何改變。請按「重新預覽」再試一次。' },
  load_failed: { kind: 'reload', text: '讀不到可以移過去的帳單，收據沒有任何改變。請按「重新讀取」再試一次。' },
  network: { kind: 'preview', text: '連線中斷，不確定有沒有移動成功。請先看學生帳務（畫面會自動更新）；收據還在原帳單的話，再按一次「確認移動」，不會重複移動。' },
  fallback: { kind: 'preview', text: '這次不確定有沒有移動成功。請先看學生帳務（畫面會自動更新）；收據還在原帳單的話，再按一次「確認移動」，還是不行請回報總部。' },
};

// A director over the limit gets one of three plain outcomes: HQ takes over (the existing approval path).
export const NEEDS_HQ_COPY = {
  off: '目前主任還不能自行移動收據，這筆要由總部處理。請按「回報給總部」，說明要移到哪一張帳單。',
  amount: '這筆金額超過主任可以自行處理的上限，這筆要由總部處理。請按「回報給總部」，說明要移到哪一張帳單。',
  age: '這張收據已超過主任可以自行更正的期限，這筆要由總部處理。請按「回報給總部」，說明要移到哪一張帳單。',
};

export const REASON_CHIPS = ['選錯學生', '選錯課程或帳單', '其他'];

// Warnings the preview may carry, in plain words.
export const WARNING_COPY = {
  source_bill_becomes_unpaid: '原帳單會變回未繳。',
  source_bill_voided: '原帳單是這張收據確認時自動開的，會跟著作廢。',
  shared_package: '這門課屬於共用課程包，同包課程的已繳標記會一起更新。',
};

/** Map a failed api result to { code, kind, text }. `phase` is 'targets', 'preview' or 'apply': only an apply can leave the outcome unknown. */
export function refusalOf(res, phase) {
  if (res.status === 403 || res.data?.message === 'Forbidden') return { code: 'forbidden', ...REFUSAL.forbidden };
  let code = res.code;
  if (code === 'reassign_needs_super_admin') {
    return { code, kind: 'hq', text: NEEDS_HQ_COPY[res.data?.hq_reason] || NEEDS_HQ_COPY.off };
  }
  if (!code && res.status === 422) code = res.data?.errors?.reason ? 'reassign_reason_required' : 'preview_failed';
  if (!code || !Object.hasOwn(REFUSAL, code)) code = phase === 'apply' ? 'fallback' : phase === 'targets' ? 'load_failed' : 'preview_failed';
  else if (phase !== 'apply' && (code === 'network' || code === 'fallback')) code = phase === 'targets' ? 'load_failed' : 'preview_failed';
  return { code, ...REFUSAL[code] };
}
