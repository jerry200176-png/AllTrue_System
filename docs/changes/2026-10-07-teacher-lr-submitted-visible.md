## 2026-10-07 — fix(learning): 老師送出評量後可見「已送出待審」
<!-- release-notes: staff_update=staff-2026-10-07-teacher-lr-submitted-visible -->
- 老師送出評量後，首頁課表會顯示「已送出待審」並可點「查看」；評量待辦會留在「待審核」並提示「已送出，等待主任核准」。
- 修正課表狀態字串 `missing` 擋住已載入評量狀態的問題，避免誤以為沒存到。
- 主任待審佇列行為不變。追蹤：GitHub #3760。
