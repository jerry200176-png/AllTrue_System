// 學習評量列表排序（純函式，可單元測試）—— 修正 in-app #155 / GitHub #742。
//
// 問題背景：原本 LearningRecordsPage 的排序把「正文空白」的紀錄一律置頂，
// 連「已核准（approved）但正文空白」的舊評量也被頂到最上，與待填的混在一起，
// 造成老師看到的日期順序很亂。
//
// 大廠作法：需要老師「動作」的（pending / changes_requested 且正文未填）才置頂；
// 已核准＝已完成，無論正文是否空白，都視為一般紀錄依日期（新→舊）排序。
//
// hasBody 以 callback 注入（元件端用自己的 hasLearningRecordBody），讓本 lib 維持純粹。

/**
 * 回傳排序層級：0 = 需老師動作（置頂）；1 = 一般（依日期排序）。
 * @param {object|null} record
 * @param {(r:object)=>boolean} hasBody 判斷該紀錄正文是否已填
 * @returns {0|1}
 */
export function learningRecordActionTier(record, hasBody) {
  if (!record) return 1;
  if (hasBody(record)) return 1;
  // 僅「待填寫且需老師處理」者置頂。
  if (record.Status === 'pending' || record.Status === 'changes_requested') return 0;
  // approved（含空白）、rejected、其他狀態 → 視為已完成 / 非待辦，依日期排序。
  return 1;
}

/**
 * 評量列表比較器：先依 action tier（待辦置頂），同 tier 內依 SessionDate（新→舊），
 * 再依 StartTime（晚→早）。
 * @param {object} a
 * @param {object} b
 * @param {(r:object)=>boolean} hasBody
 * @returns {number}
 */
export function compareLearningRecords(a, b, hasBody) {
  const ta = learningRecordActionTier(a, hasBody);
  const tb = learningRecordActionTier(b, hasBody);
  if (ta !== tb) return ta - tb;
  const aDate = String(a?.SessionDate || '');
  const bDate = String(b?.SessionDate || '');
  if (aDate !== bDate) return bDate.localeCompare(aDate);
  return String(b?.StartTime || '').localeCompare(String(a?.StartTime || ''));
}
