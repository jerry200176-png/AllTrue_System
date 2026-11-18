## 2026-11-18 — fix(calendar): remove cancelled cached lessons before showing a rescheduled renewal
<!-- release-notes: staff_update=staff-2026-11-18-calendar-cancelled-cache -->

- 行事曆重新讀回已取消的舊堂次時，先按實體堂次更新快取，再過濾顯示；避免續報或調課後，舊課卡仍與新課卡同時出現（in-app #568／#5439）。
- 保留其他週與已上課歷史；兩筆真正有效的課程仍各自顯示，讀取失敗也不清空舊資料。只修讀取後的顯示，不修改排課、出席、扣堂或帳務。
- 回歸涵蓋真實載入層與合成資料的完整 Vue 調課流程；原回報瀏覽器快取未被直接觀測，上線後仍須回報者確認。
