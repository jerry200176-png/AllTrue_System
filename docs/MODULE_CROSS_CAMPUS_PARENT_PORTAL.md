# AllTrue 跨分校學生／家長入口模組

## 目的

讓同一位經主任確認的學生身份，可以在同一個家長入口查看多個分校的 branch-local enrollment；不改寫既有 `StudentID` 外鍵，也不把不同家庭因姓名或手機相同而自動合併。

## 邊界與授權

- `student_identity_groups` 是 AllTrue 層級身份；`student_identity_members` 將既有 `Student` row 掛到身份群組，保留原分校與 legacy foreign key。
- `parent_cross_campus_access.mode` 為 `off`、`readonly`、`actions`；建立關聯後預設 `off`。
- 只有同時具備群組內所有分校權限的 `director` 或 `super_admin` 可以建立／解除關聯與切換 pilot state。
- 不依姓名、手機、舊 LINE binding 自動建立身份；dry-run 只輸出遮罩／雜湊電話的人工工作佇列。
- 家長 API 以 token session 的 `identity_group_id` 與後端 member 驗證為準；前端 `campus_id` 只是一個 scope hint。

## API 與畫面契約

- `GET /api/v1/parent/dashboard?scope=all`
- `GET /api/v1/parent/dashboard?scope=campus&campus_id={id}`
- `GET /api/v1/student-identities?q={name}`
- `POST /api/v1/student-identities/link`
- `DELETE /api/v1/student-identities/members/{studentId}`
- `PUT /api/v1/student-identities/{groupId}/access`
- `GET /api/v1/student-identities/{groupId}/audit`

dashboard 的 `classes`、`learning_records`、`attendance_history`、`upcoming_sessions`、`invoices`、`announcements` 每筆都帶 `campus_id`／`campus_name`。帳務可以在總覽呈現，但 Invoice、Payment、收據與對帳仍然只以 legacy 分校資料為單位。

## Migration compatibility / rollback

這是 Expand 階段：只新增 bridge tables 與 nullable `ParentSession.identity_group_id`。不 rename、drop 或重寫 `StudentClass`、`ClassSession`、`StudentSignIn`、`LearningRecord`、`Invoice`、`Payment` 的既有外鍵。所有 backfill／候選盤點都必須是 read-only；正式 deploy 由 protected `main` 的 `deploy.yml` 執行。

若 pilot 發生問題，先把群組切回 `readonly` 或 `off`，不刪資料、不回滾既有 legacy row。migration `down` 只在正式 contract 計畫與相容性審查後使用。

## Rollout gates

1. `parent:identity-candidates --json` 產出人工確認清單，不寫 production。
2. 主任建立關聯，確認 audit log 與兩校權限。
3. 指定群組 `readonly` pilot：觀察登入成功率、空資料率、錯誤分校率與 scope 切換。
4. 驗證 smoke／negative tests 後才切 `actions`；請假與評量回饋依分校 context，付款不跨 Invoice。
5. T+7／T+14 檢視後才逐步擴大。

## Verification

- Backend：`CrossCampusParentPortalTest`、既有 `ParentPortalLoginIsolationTest`、migration `--pretend`／rollback `--pretend`。
- Frontend：既有 Vite build 與 parent portal scope／campus label smoke。
- 上線前 PR 必須附授權威脅模型、rollback、pilot metrics 與 post-deploy smoke evidence；不可在 production Pi 執行 PHPUnit、migration、cache clear 或手動部署。
