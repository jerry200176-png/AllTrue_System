## 2026-10-07 — fix(schedule): course edit that adds a weekday no longer gets a false 409 (#3502)
<!-- release-notes: silent_ship=silent-2026-10-07-schedule-guard-global-remap -->
<!-- silent-reason: 只移除一種誤擋：原本要分兩步做的編輯現在一步就能存，真正的衝突照樣擋。 -->
- 新增週幾（該週幾還沒有堂次）時，同步會把所有未鎖定堂次依新節奏重排；排課防撞（`ScheduleGuardService`）原本只模擬同日重排，會把「多出來那堂」當成留在原地而誤報自我重疊 409。
- 判斷「是否整體重排」抽成 `ContractSessionSchedule::needsGlobalRemap()`，同步與防撞共用同一個函式；整體重排時防撞只把鎖定／例外／非排定的堂次當成留下來的。
