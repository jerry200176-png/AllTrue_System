## 2026-10-23 — fix(staff): 加入分校只由該分校主任核准（Founder policy 1，#4433）
<!-- release-notes: staff_update=staff-2026-10-23-campus-join-approval -->

- 老師管理「待審核」改用與側欄 `pending_teachers` 相同的定義：帳號待審核，或有加入「你的分校」的待審核連結（`UserCampus.Approved = 0`）。已在職的老師申請加入你的分校時，現在會出現在待審核，卡片上有「核准加入／拒絕加入 <分校>」，確認用 `AtDialog`。別校的申請在「跨校」標示（待審核），沒有按鈕。
- 新端點 `POST /api/v1/profiles/{id}/campus-links/{campusId}/approve`、`.../reject`：只有該分校的主任或超級管理員能決定；其他分校主任 403；沒有已核准分校的主任 403（`require_campus`）；不能審核自己的連結（422 `self_approval_forbidden`）。不改 `User.status`，也不動其他分校的連結；拒絕只刪除該分校的待審核連結與對應的 legacy `teacher_branches` 列。稽核 `staff.campus_link.approved`／`staff.campus_link.rejected`。
- `PUT /profiles/{id}` 帶 `status: active`（帳號「核准」與編輯存檔）只釋出操作者自己分校的待審核連結，超級管理員照舊全部。編輯時把老師加到操作者沒管理的分校，改為建立待審核連結（原本直接核准）；主分校沒變時存檔不再改動該連結的核准狀態。
- `GET /teachers`、`GET /profiles` 每列新增 `pending_branch_ids`（全部待審核分校）與 `approvable_branch_ids`（操作者可審核的部分）。
- 不需 migration，不回溯改寫既有連結。Rollback：revert 本 PR。回歸：`TeacherCampusLinkApprovalTest`、`TeachersListPendingCampusLink.test.js`。
