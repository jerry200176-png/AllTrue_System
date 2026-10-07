## 2026-10-07 — fix(security): student endpoints share one fail-closed campus gate (bindCard cross-campus write)
<!-- release-notes: silent_ship=silent-2026-10-07-student-campus-gate -->
<!-- silent-reason: 權限修補；合法操作行為不變，只擋掉跨分校綁卡。 -->
- `StudentController::bindCard` 沒有檢查學生分校，主任可把 RFID 卡號寫到別分校學生；7 個學生端點改共用 `denyOutsideCampus()`（空分校清單一律拒絕）。
