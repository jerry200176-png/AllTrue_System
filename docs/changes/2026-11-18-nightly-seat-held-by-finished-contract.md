## 2026-11-18 — chore(nightly): count upcoming lessons of locked courses that still block a slot
<!-- release-notes: silent_ship=silent-2026-11-18-nightly-seat-held-by-finished-contract -->
<!-- silent-reason: 只新增夜間唯讀檢查（只回報、不擋），不變更產品畫面、資料或操作。 -->

- 夜間檢查新增 `seat_held_by_finished_contract`：已結算或已關閉（使用鎖）但沒停課的課程，還留著未來的有效堂次。排課檢查會把這些堂次算成「位子有人」，可是它們永遠不會上，所以老師、教室、學生的時段被白白佔住。
- 先只回報；連續 14 晚為 0 後改成強制（#4991 線 3）。
