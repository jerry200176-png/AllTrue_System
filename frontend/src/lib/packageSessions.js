/**
 * Pure helpers for shared-package (共用方案) total-session edits.
 *
 * A shared-package course (StudentClass.PackageID > 0) draws from one pooled
 * total. The pool total is the single source of truth and must be changed via
 * PUT /course-packages/{id} (total_sessions) — never via per-member edits.
 *
 * Directors need two operations on that pool:
 *   - 'add': 加購 — increase the total by N.
 *   - 'set': 設定總堂數 — set the total to an absolute value (correction).
 *
 * computePackageNextTotal resolves the resulting absolute total and validates
 * it against the already-used sessions (the backend rejects total < used). (#553)
 */

/**
 * @param {Object} args
 * @param {('add'|'set')} args.mode
 * @param {number} args.currentTotal  current pooled total_sessions
 * @param {number} args.value         the user-entered number (delta for add, absolute for set)
 * @param {number} [args.usedSessions] sessions already consumed from the pool
 * @returns {{ ok: boolean, nextTotal: number|null, error: string }}
 */
export function computePackageNextTotal({ mode, currentTotal, value, usedSessions = 0 } = {}) {
  const cur = Number(currentTotal);
  const val = Number(value);
  const used = Number(usedSessions) || 0;

  if (!Number.isFinite(val)) {
    return { ok: false, nextTotal: null, error: '請輸入正確的堂數' };
  }

  if (mode === 'set') {
    if (!Number.isInteger(val) || val < 1) {
      return { ok: false, nextTotal: null, error: '請輸入正確的總堂數（需為大於 0 的整數）' };
    }
    if (val < used) {
      return {
        ok: false,
        nextTotal: null,
        error: `總堂數不可小於已使用的 ${used} 堂`,
      };
    }
    return { ok: true, nextTotal: val, error: '' };
  }

  // default: 'add'
  if (!Number.isInteger(val) || val < 1) {
    return { ok: false, nextTotal: null, error: '請輸入正確的加購堂數（需為大於 0 的整數）' };
  }
  if (!Number.isFinite(cur) || cur <= 0) {
    return { ok: false, nextTotal: null, error: '找不到方案總堂數，請先重新整理後再試' };
  }
  return { ok: true, nextTotal: cur + val, error: '' };
}
