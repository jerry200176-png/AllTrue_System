/**
 * 同一位老師若掛兩個帳號、顯示名稱相同，行事曆的老師欄位會合併顯示成一欄
 * （代表 ID 為課程數較多的那個帳號，見 SmartCalendar.vue filterTeacherOptions），
 * 但個別課程的 teacher_id 仍是原本各自的帳號 ID，不會跟著改。
 * 判斷「這門課是否屬於這個（合併後的）老師欄位」時，必須比對別名帳號集合，
 * 不能只比對合併後的單一 ID——否則掛在「輸家」帳號下的課程會直接消失
 * （in-app #219/#223：吳宥萱試聽掛在「高為澎」的第二個帳號，欄位被合併後課程再也比對不到）。
 */
export function resolveTeacherAliasIds(teacherEntry, fallbackId) {
  const ids = Array.isArray(teacherEntry?.alias_ids) && teacherEntry.alias_ids.length > 0
    ? teacherEntry.alias_ids
    : [fallbackId];
  return ids
    .map((id) => Number(id))
    .filter((id) => Number.isFinite(id) && id > 0);
}

export function courseBelongsToTeacherAlias(course, aliasIds) {
  const set = aliasIds instanceof Set ? aliasIds : new Set(aliasIds);
  return set.has(Number(course?.teacher_id));
}
