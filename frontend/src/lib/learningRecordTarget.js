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

/**
 * 剛儲存的評量是否落在「近 N 天」預設視窗之外，需自動解除視窗才看得到。
 *
 * 評量列表預設只顯示近 90 天，較舊記錄收在「查看全部歷史」。老師若替較舊的課次補填評量，
 * 存檔後重新抓清單時該筆會被視窗濾掉 → 造成「我剛新增，列表卻看不到」的困惑（in-app #105）。
 * 存檔後若日期早於視窗起點，呼叫端應解除視窗，讓剛新增的那筆穩定顯示。
 *
 * @param {Object} args
 * @param {string} [args.savedDate]  剛存評量的上課日期（YYYY-MM-DD）
 * @param {string} [args.windowStart] 目前套用的視窗起點（YYYY-MM-DD）；未套用視窗時為空字串
 * @returns {boolean} true = 需解除預設視窗
 */
export function shouldLiftDefaultWindowForDate({ savedDate, windowStart } = {}) {
  const date = String(savedDate || '').slice(0, 10);
  const start = String(windowStart || '').slice(0, 10);
  // 未套用視窗（start 空）或無有效日期時無需解除。日期皆 YYYY-MM-DD，可直接字串比較。
  if (!start || !date || date.length !== 10) return false;
  return date < start;
}

/**
 * 主任／老師從「家長回饋待看」CTA 導進評量頁時，要套用的篩選狀態。
 *
 * 問題（in-app #138，與 #54/#105 同類）：CTA 的未讀回饋計數來自「server 通知 badge」，
 * 但評量列表預設用「待審分頁 + 近 90 天視窗」載入，而家長回饋多發生在「已核准」且可能較舊的
 * 課次 → 那些有回饋的紀錄根本不在列表內 → 導過去切「未讀回饋」也是空的。
 *
 * 解法：導入時把資料集放到最大（reviewTab=all、不只看未填、解除近 90 天視窗），
 * 並把回饋篩選設為「未讀」，讓有回饋的紀錄一定被載入並列出。
 *
 * @param {Object} args
 * @param {boolean} [args.isTeacher]
 * @returns {{ feedbackFilter:'unread', reviewTab:'all', teacherFilterTab:'all', onlyUnfilled:false, liftWindow:true }}
 */
export function feedbackFocusState({ isTeacher = false } = {}) {
  return {
    feedbackFilter: 'unread',
    reviewTab: 'all',
    teacherFilterTab: 'all',
    onlyUnfilled: false,
    liftWindow: true,
    isTeacher: Boolean(isTeacher),
  };
}
