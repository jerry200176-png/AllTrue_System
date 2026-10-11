// Batch record / confirm answer HTTP 207 when only some entries went through. The director must see which ones did not
// and why (review follow-up on #3853), not just "1 / 2".

/**
 * @param {{results?: Array<{http_status:number, message?:string|null, student_class_id?:number, id?:number}>}} json
 * @param {Array} rows selected queue rows that were sent
 * @param {(row:object)=>number} keyOf the id the server echoes for a row (student_class_id for record, report id for confirm)
 * @param {(row:object)=>string} labelOf how the director recognises the row
 */
export function summarizeBatchResults(json, rows, keyOf, labelOf) {
  const byKey = new Map((rows || []).map((r) => [Number(keyOf(r)), r]));
  const failed = [];
  for (const res of json?.results || []) {
    if (Number(res.http_status) < 300) continue;
    const key = Number(res.student_class_id ?? res.id);
    const row = byKey.get(key);
    failed.push({ row, key, label: row ? labelOf(row) : `#${key}`, message: res.message || '這筆沒有成功' });
  }
  const shown = failed.slice(0, 5).map((f) => `${f.label}：${f.message}`).join('\n');
  const more = failed.length > 5 ? `\n…還有 ${failed.length - 5} 筆` : '';
  return {
    failed,
    failedRowIds: failed.filter((f) => f.row).map((f) => f.row.id),
    text: failed.length ? `${failed.length} 筆沒有成功（其餘已處理）：\n${shown}${more}` : '',
  };
}
