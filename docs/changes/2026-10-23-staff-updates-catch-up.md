## 2026-10-23 — docs(staff-updates): catch-up cards for user-visible changes shipped 2026-10-22/23
<!-- release-notes: staff_update=staff-2026-10-23-account-subject-permissions -->

- 2026-10-22／23 上線的多個修正被標成 silent ship，「版本更新」看不到。補四張教職員卡，`source_refs` 指回原 fragment；原 fragment 的 silent 標記保留為歷史，不改。
- `staff-2026-10-23-account-subject-permissions`（主任）：老師管理新增老師只能選自己負責的分校、主任帳號與有主任身分的帳號改由超級管理員管理、共用科目更名改由超級管理員處理。
- `staff-2026-10-23-campus-scope-students`（主任）：收款回報、學習紀錄審核、複製繳費通知只限自己分校的學生；看到「沒有權限」時找誰。
- `staff-2026-10-23-shared-package-billing-fixes`（主任）：共用方案重新點名會再扣一堂（以前少扣的堂數另行處理，本卡不宣稱已更正）、待核對回報擋「免費／贈送」、收款入口不落在輔導課。
- `staff-2026-10-23-calendar-conflict-leave-fixes`（主任、老師）：重複課程提示不再出現走不通的選項、請假補課留在原本的星期幾。
- 沒有畫面可見差異的 fragment 不發卡（銀行對帳、排課列刪除、老師分校指派三個沒有畫面呼叫的端點；已只對主任顯示的「復原」；只影響沒有分校的孤兒資料）。家長端「每門課的上課日期」需正式站驗證後另以 `docs/parent-updates/` 發布。
