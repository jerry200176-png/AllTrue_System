## 2026-11-19 — fix(scheduling): schedule edits recheck the teacher slot with locks (TD-096)
<!-- release-notes: silent_ship=silent-2026-11-19-schedule-update-locked-recheck -->
<!-- silent-reason: 只把既有的改課複查改成加鎖讀取並自動重試；規則、訊息與畫面都不變。 -->

- 改單堂課時間時，寫入前的最後一次「老師這時段有沒有空」檢查，改成跟新增單堂一樣加鎖讀取。同一秒有兩位主任把不同學生改進同一位老師的同一時段，第二筆會被正確拒絕。
- TD-096 結案：轉換暫留等路徑在 #5531 後都會自動重試，不再回錯誤。
