## 2026-10-07 — fix(schedule): moving a taught lesson into the renewal no longer fails with a false overlap (in-app #380)
<!-- release-notes: silent_ship=silent-2026-10-07-inapp-380-transfer-refill -->
<!-- silent-reason: 修正原本會失敗的轉移堂次操作，成功後畫面與既有流程一致，不另發卡。 -->
- 轉移已上堂次到續約合約時，來源合約補回的尾端堂次會避開續約合約自己的堂次，不再撞到續約第一堂而整筆失敗；與其他無關課程衝突時仍整筆回滾。
- 向前生成（`sessions:generate-forward`）與 ensure-horizon 改用寫入守門同一規則（`isStudentSlotFree()`）略過與其他合約部分重疊的日期，不再因部分重疊整批失敗。
