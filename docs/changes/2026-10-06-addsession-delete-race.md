## 2026-10-06 — fix(schedule): quick-add session locks the contract so it cannot race a delete (#3593)
<!-- release-notes: silent_ship=silent-2026-10-06-addsession-delete-race -->
<!-- silent-reason: 只補同時操作的防護，畫面行為不變 -->
- `StudentClassController::addSession` 在交易內先鎖定合約列，合約已被同時刪除時回 404，不再替已刪合約建立堂次／學習紀錄。
