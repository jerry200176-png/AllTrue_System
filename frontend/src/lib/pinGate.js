// #769 敏感頁 PIN 二次驗證 — 前端 gate 純邏輯（可單元測試，無 DOM/fetch 依賴）。
// 真正的安全邊界在後端 require_pin middleware；此處只決定 UX gate 是否顯示。

/** D2：受保護的 active 頁集合。 */
export const PIN_PROTECTED_PAGES = ['parttime-payroll', 'tuition-collect', 'tuition-report', 'teachers'];

/** 對齊後端常數。 */
export const PIN_UNLOCK_TTL_MS = 10 * 60 * 1000; // pin_verified_until 10 分鐘
export const PIN_IDLE_LOCK_MS = 5 * 60 * 1000;   // 閒置 5 分鐘自動鎖
export const PIN_BLUR_LOCK_MS = 60 * 1000;       // 切分頁逾 60 秒自動鎖

/**
 * 此頁是否受 PIN 保護。
 * D3：super_admin 不納管（避免最高權限自鎖）。
 */
export function isPinProtectedPage(page, role) {
  return PIN_PROTECTED_PAGES.includes(page) && role !== 'super_admin';
}

/**
 * 是否該掛 PinLockModal（擋住內容）。
 * D1 soft：未設 PIN 者可 softSkip 略過 → 不擋。
 */
export function shouldShowPinModal({ page, role, hasSession, passwordLocked, unlocked, softSkip }) {
  if (!hasSession) return false;
  if (passwordLocked) return false;
  if (!isPinProtectedPage(page, role)) return false;
  if (unlocked) return false;
  if (softSkip) return false;
  return true;
}

/** PIN 格式：4–6 位純數字。回傳錯誤訊息或 null（合法）。 */
export function pinFormatError(pin) {
  if (!/^\d{4,6}$/.test(String(pin))) return 'PIN 須為 4 到 6 位數字';
  return null;
}

/**
 * 切回前景時是否該因離開過久而自動鎖。
 * @param {boolean} unlocked 目前是否已解鎖
 * @param {number} hiddenAt 進背景的時間戳（ms），0 = 未記錄
 * @param {number} now 現在時間戳（ms）
 */
export function shouldBlurLock(unlocked, hiddenAt, now) {
  return Boolean(unlocked) && hiddenAt > 0 && (now - hiddenAt) > PIN_BLUR_LOCK_MS;
}
