## 2026-10-23 — fix(staff): 待審核的加入申請不給該分校主任管理權（Founder policy 1，#4433）
<!-- release-notes: silent_ship=silent-2026-10-23-pending-link-no-management -->
<!-- silent-reason: authorization hardening; a teacher who only asked to join your campus is decided in 老師管理「待審核」(#4457), not edited -->

老師只是「申請加入」B 分校（`UserCampus.Approved = 0`，註冊時另有一筆 legacy `teacher_branches`）時，B 分校主任原本就能重設該老師密碼（會拿到臨時密碼）、改帳號或密碼、刪除老師、清掉分校連結。現在管理權只看已核准的連結（`Approved = 1`，或舊資料的 NULL；與 `StaffCapabilityAuthorizer::legacyCampusIds` 相同），legacy `teacher_branches` 只在該分校沒有任何 `UserCampus` 列時才算。`ProfileController::canManageTeacher` 與 `TeacherBranchController::managesTeacher` 共用新的 `StaffCapabilityAuthorizer::hasApprovedCampusLink`。核准或拒絕申請改走 #4457 的分校審核端點，核准後即可管理。回歸：`PendingCampusLinkNoManagementTest`。不需 migration；Rollback 為一般 revert。
