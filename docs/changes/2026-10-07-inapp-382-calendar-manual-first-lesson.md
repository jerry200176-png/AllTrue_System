## 2026-10-07 — fix(calendar): a manual course created from the calendar opens 新增下一堂 (in-app #382)
<!-- release-notes: staff_update=staff-2026-10-07-calendar-manual-first-lesson -->
- 行事曆用「逐堂手動排課」建立課程後，會直接帶到課程查找並打開該課的「新增下一堂」，排好第一堂就會出現在行事曆。
- 再次新增同科目同類型時，若既有課是「手動排課且還沒有未來堂次」，重複提示會提供「新增下一堂」，不再只引導加購或另建一筆空課；後端重複課程回應新增 `scheduling_policy`、`future_session_count`。
