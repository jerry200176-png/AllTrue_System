## 2026-11-17 — feat(billing): prepare current reference checks for explicit reservation release
<!-- release-notes: silent_ship=silent-2026-11-17-credit-reservation-current-references -->
<!-- silent-reason: 尚未開放既有課程配置入口；僅讓既有參照守衛可在交易內採用鎖定讀取，原呼叫行為保留。 -->

- 沿用 ClassSessionDeleteGuard 的完整參照清單，提供交易內的 current read，避免使用較早的 MySQL 快照漏看新提交的評量或具日期排課。
- 兩條獨立連線的隔離測試驗證：新參照可見、後續競爭寫入必須等待鎖，且要求鎖定時不能在交易外靜默退化。
- 尚未接上取消預約或轉用寫入；不改動正式堂次、帳務或既有只讀呼叫端。
