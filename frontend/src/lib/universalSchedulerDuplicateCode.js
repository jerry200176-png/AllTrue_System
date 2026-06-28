// #174 / #805：建立課程時後端可能回兩種「重複/重疊」409，兩者都要導去前端的
// 「仍要新增課程（強制建立）」攔截 modal，而不是用原生 alert 把訊息丟出去造成
// 死路（使用者被叫去「勾選強制建立」卻找不到任何按鈕／勾選框）。
// - duplicate_active_course：同科目同類型既有進行中課程。
// - overlapping_active_course：同科目 + 同老師 + 日期區間重疊（續報重疊）。
//
// 這個判斷刻意獨立成無相依的純函式檔，讓 node 測試可直接 import，不必載入
// supabase / import.meta.env 等只有 Vite 能解析的相依。
export function isDuplicateInterceptCode(code) {
  return code === 'duplicate_active_course' || code === 'overlapping_active_course';
}
