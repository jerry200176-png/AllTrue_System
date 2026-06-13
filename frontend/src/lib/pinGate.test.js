// node src/lib/pinGate.test.js — #769 PIN gate 純邏輯測試
import assert from 'node:assert';
import {
  PIN_PROTECTED_PAGES,
  isPinProtectedPage,
  shouldShowPinModal,
  pinFormatError,
  shouldBlurLock,
  PIN_BLUR_LOCK_MS,
} from './pinGate.js';

let passed = 0;
function t(name, fn) { fn(); passed += 1; console.log(`  ✓ ${name}`); }

// ── isPinProtectedPage ───────────────────────────────────────────────
t('受保護頁對 director 為 true', () => {
  for (const p of PIN_PROTECTED_PAGES) {
    assert.strictEqual(isPinProtectedPage(p, 'director'), true, p);
  }
});
t('非受保護頁為 false', () => {
  assert.strictEqual(isPinProtectedPage('calendar', 'director'), false);
  assert.strictEqual(isPinProtectedPage('students', 'director'), false);
});
t('D3：super_admin 一律不納管', () => {
  for (const p of PIN_PROTECTED_PAGES) {
    assert.strictEqual(isPinProtectedPage(p, 'super_admin'), false, p);
  }
});

// ── shouldShowPinModal ───────────────────────────────────────────────
const base = {
  page: 'parttime-payroll', role: 'director', hasSession: true,
  passwordLocked: false, unlocked: false, softSkip: false,
};
t('受保護頁、未解鎖、有 session → 顯示', () => {
  assert.strictEqual(shouldShowPinModal(base), true);
});
t('已解鎖 → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, unlocked: true }), false);
});
t('D1 soft：softSkip → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, softSkip: true }), false);
});
t('無 session → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, hasSession: false }), false);
});
t('密碼變更鎖優先 → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, passwordLocked: true }), false);
});
t('非受保護頁 → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, page: 'calendar' }), false);
});
t('super_admin 在受保護頁 → 不顯示', () => {
  assert.strictEqual(shouldShowPinModal({ ...base, role: 'super_admin' }), false);
});

// ── pinFormatError ───────────────────────────────────────────────────
t('4–6 位數字合法', () => {
  assert.strictEqual(pinFormatError('1357'), null);
  assert.strictEqual(pinFormatError('028461'), null);
});
t('過短 / 過長 / 非數字 被擋', () => {
  assert.ok(pinFormatError('123'));
  assert.ok(pinFormatError('1234567'));
  assert.ok(pinFormatError('12a4'));
  assert.ok(pinFormatError(''));
});

// ── shouldBlurLock ───────────────────────────────────────────────────
t('已解鎖且離開逾 60s → 鎖', () => {
  const now = 1_000_000;
  assert.strictEqual(shouldBlurLock(true, now - PIN_BLUR_LOCK_MS - 1, now), true);
});
t('離開未逾 60s → 不鎖', () => {
  const now = 1_000_000;
  assert.strictEqual(shouldBlurLock(true, now - 1000, now), false);
});
t('未解鎖 / 未記錄 hiddenAt → 不鎖', () => {
  const now = 1_000_000;
  assert.strictEqual(shouldBlurLock(false, now - 999_999, now), false);
  assert.strictEqual(shouldBlurLock(true, 0, now), false);
});

console.log(`\npinGate: ${passed} tests passed`);
