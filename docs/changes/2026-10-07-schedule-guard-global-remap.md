## 2026-10-07 — fix(schedule): course edit that adds a weekday no longer gets a false 409 (#3502)
<!-- release-notes: silent_ship=silent-2026-10-07-schedule-guard-global-remap -->
<!-- silent-reason: 只移除一種誤擋：原本要分兩步做的編輯現在一步就能存，真正的衝突照樣擋。 -->
- 新增週幾（該週幾還沒有堂次）時，同步會把所有未鎖定堂次依新節奏整體重排；排課防撞（`ScheduleGuardService`）原本只模擬同日重排，會把「多出來那堂」當成留在原地而誤報自我重疊 409。
- 同步的完整決策（收編例外、是否整體重排、每堂落點）抽成純函式 `ContractSessionSchedule::planFutureSync()`；同步與課程編輯的防撞呼叫同一個函式，防撞用重排後的確切落點檢查自我重疊與佔用。
