## 2026-10-29 — feat(learning-records): 已填未核准的評量換老師 → 保存原作者內容、清空讓新老師重填（#3824 part 2）
<!-- release-notes: staff_update=staff-2026-10-29-lr-author-snapshot -->
- 新表 `learning_record_author_snapshots`（只新增、不更新、不刪除；migration 為 create-only，Founder GO 2026-10-29）。
- 單堂／整批 PATCH 換師、代課、回正班、Undo、批次代課、整張合約改派（reassignContract）遇「已填未核准」：同交易先寫快照、再清空正文，TeacherID 改為新老師。已核准列沿用 #3895 的 409 鎖。
- 附件只存網址（檔案不搬）；老師給家長的話整列複製進快照後從原列移除（皆可由快照查回）。
- 主任／super_admin 以 `GET /learning-records/{id}/author-snapshots` 唯讀查看；老師與家長 403。永久保存。
- 回滾：還原 PR 並 `drop table learning_record_author_snapshots`（先匯出快照）。
