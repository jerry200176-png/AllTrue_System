## 2026-11-09 — fix(calendar): 調課後顯示最新時段（in-app #528，Refs #5145）
<!-- release-notes: staff_update=staff-2026-11-09-calendar-reschedule-cache -->
- 修正同一堂課調整時間後，行事曆仍可能停留在原時段的顯示問題；連續調課與跨週移動也沿用最新讀回的時段。
- 僅修正前端快取中同課程、同實體堂次 ID 的位置合併；保留其他堂次與授課歷史，不變更排課、出席、扣堂或帳務資料。
- 回歸涵蓋 helper、實際行事曆合併與合成資料的真 Vue 調課流程。正式回報的寫入結果仍未取得，不能據此宣稱修復歷史資料；舊 HTTP 晚回、跨請求 owner/window/freshness 邊界延後另案評估。
