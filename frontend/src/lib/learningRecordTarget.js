/**
 * 從教學工作台「補填提醒」深連結進入「課表與評量」時，決定查該堂課次要用哪個分校 ID。
 *
 * 補填提醒會「跨分校」列出「已到班但尚未填評量」的課次
 * （TeacherHomePage.fetchOverdueLearning，逐一掃 teacherBranchIds）。
 * 點入後 LearningRecordsPage 以分校查 class-sessions 開啟填寫 modal；但分校切換
 * （localStorage.app_branch → props.branchId）可能尚未生效，若硬用 props.branchId 查，
 * 跨分校的課次會查無 → 提醒看得到、點進去卻填不到。(#54 / #82)
 *
 * 深連結事件本身已帶 targetBranchId（goFillRecord emit 的 branchId），優先用它查，
 * 沒有才退回目前分校；這讓填寫頁與提醒落在同一個分校範圍。
 *
 * @param {Object} args
 * @param {number|string|null} [args.targetBranchId] 深連結事件帶入的目標分校
 * @param {number|string|null} [args.currentBranchId] 目前頁面 props.branchId
 * @returns {number|null} 查詢要用的分校 ID（> 0），兩者皆無則 null
 */
export function resolveDeepLinkBranchId({ targetBranchId, currentBranchId } = {}) {
  const target = Number(targetBranchId);
  if (Number.isFinite(target) && target > 0) return target;
  const current = Number(currentBranchId);
  if (Number.isFinite(current) && current > 0) return current;
  return null;
}
