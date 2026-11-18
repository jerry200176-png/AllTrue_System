## 2026-11-18 — fix(schedule): 改開課日不再替已用完堂數的課程多排兩堂（in-app #524, #5133）
<!-- release-notes: silent_ship=silent-2026-11-18-extend-sessions-ended-unmarked -->
<!-- silent-reason: 只防止多排堂次，正常課程畫面不變 -->
- `ContractSessionSchedule::extendSessionsIfNeeded`：堂數已足夠時，已結束但尚未點名的堂次不再被視為「契約外」而換成新開課日的堂次；補建上限為缺口或可替換的未來堂次數。
