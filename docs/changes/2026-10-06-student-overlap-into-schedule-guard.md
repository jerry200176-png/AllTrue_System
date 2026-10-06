## 2026-10-06 — refactor(schedule): 逐堂排課的學生衝堂檢查移入 ScheduleGuardService
<!-- release-notes: silent_ship=silent-2026-10-06-student-overlap-guard -->
<!-- silent-reason: 純內部搬移衝堂規則，判斷條件與提示文字不變，教職員看到的畫面完全相同。 -->
- `ManualSessionBookingService` 自有的學生同時段檢查改呼叫 `ScheduleGuardService::studentHasOverlap`（占用定義與訊息不變），並補上三個特徵測試（重疊／相鄰、他課已停用或已取消不計、本課停用仍計）。
