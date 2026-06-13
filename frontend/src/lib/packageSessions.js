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

/**
 * Build the session-count summary for a course's「上課日期」panel header (#806).
 *
 * Shared-package members all carry `StudentClass.SessionCount = pool total`, so
 * showing「購買 12」per subject reads as additive (數學12 + 物理12 ≈ 24) and hides
 * the pool's already-used count. Frame package members around the SHARED pool —
 * the single source of truth (`package_total/remaining_sessions`) — instead.
 * Non-package session courses keep the per-course「已上 / 購買」framing.
 *
 * @param {Object} course   StudentClass row (with package_* fields when pooled)
 * @param {Object} [counts]
 * @param {number} [counts.completed] this course's completed sessions
 * @param {number} [counts.cancelled] cancelled sessions to annotate
 * @returns {{ isPackage: boolean, total: number, used: number, remaining: number, purchased: number, text: string }}
 */
export function packageMemberSessionSummary(course, { completed = 0, cancelled = 0 } = {}) {
  const done = Math.max(0, Number(completed) || 0);
  const cancelledN = Math.max(0, Number(cancelled) || 0);
  const cancelledSuffix = cancelledN > 0 ? `，${cancelledN} 堂已取消` : '';
  const isPackage = Number(course?.PackageID ?? course?.package_id ?? 0) > 0;

  if (isPackage) {
    const total = Math.max(0, Number(course?.package_total_sessions ?? course?.PackageTotalSessions ?? 0) || 0);
    const remaining = Math.max(0, Number(course?.package_remaining_sessions ?? course?.PackageRemainingSessions ?? 0) || 0);
    const used = Math.max(0, total - remaining);
    return {
      isPackage: true,
      total,
      used,
      remaining,
      purchased: total,
      text: `本科已上 ${done} 堂｜方案共用 ${total} 堂（已用 ${used} / 剩 ${remaining}）${cancelledSuffix}`,
    };
  }

  const purchased = Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);
  return {
    isPackage: false,
    total: purchased,
    used: done,
    remaining: Math.max(0, purchased - done),
    purchased,
    text: `已上 ${done} / 購買 ${purchased} 堂${cancelledSuffix}`,
  };
}
