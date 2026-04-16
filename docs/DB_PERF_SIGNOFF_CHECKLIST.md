# 資料庫效能優化 — 簽核確認清單

**PRD**: AllTrue Database Optimization（樹莓派環境）
**日期**: 2026-04-16
**階段**: P0 完成，P1 架構已準備

---

## Definition of Done 逐項確認

| # | DoD 項目 | 狀態 | 佐證 |
|---|---------|------|------|
| 1 | FR-000：效能基線快照已建立並歸檔 | DONE | `docs/DB_PERF_BASELINE_2026-04-16.md` |
| 2 | FR-001~003：索引、分頁、N+1 修復通過 QA | DONE | EXPLAIN 全部從 ALL→ref；N+1 已修復 |
| 3 | FR-004：效能比較報告 | DONE | 基線文件 §3 vs 優化後 EXPLAIN 對比 |
| 4 | FR-005：讀寫分離設定 | DONE (P1 ready) | `config/database.php` 已加入 read/write/sticky |
| 5 | FR-006：連線池/persistent PDO | DONE (P1 ready) | `DB_PERSISTENT` env flag 已加入 |
| 6 | FR-007：降級策略 | DONE (P1 ready) | `DB_READ_HOST` 未設定時全走 primary |
| 7 | FR-008：校區隔離 | DONE | 測試 `test_student_campus_filter` 通過 |
| 8 | 資安審查無阻擋項 | DONE | `docs/DB_PERF_SECURITY_REVIEW_2026-04-16.md` |
| 9 | API health 正常 | DONE | 測試 25/25 pass |
| 10 | migration 前備份 | DONE | `backups/alltrue_pre_perf_optimization_2026-04-16.sql` |
| 11 | 回滾 SOP | DONE | `docs/DB_PERF_RUNBOOK.md` |
| 12 | CHANGELOG 更新 | DONE | `docs/CHANGELOG.md` 2026-04-16 (A) |

---

## 變更摘要

### 新增檔案
- `backend/database/migrations/2026_04_16_100000_add_core_perf_indexes.php`
- `backend/tests/Feature/DatabasePerfTest.php`
- `docs/DB_PERF_BASELINE_2026-04-16.md`
- `docs/DB_PERF_SECURITY_REVIEW_2026-04-16.md`
- `docs/DB_PERF_RUNBOOK.md`
- `docs/DB_PERF_SIGNOFF_CHECKLIST.md`
- `backups/alltrue_pre_perf_optimization_2026-04-16.sql`

### 修改檔案
- `backend/config/database.php`（加入 read/write/sticky + persistent PDO）
- `backend/app/Http/Controllers/StudentController.php`（修復 N+1）
- `docs/CHANGELOG.md`（新增 2026-04-16 (A) 條目）

### 索引新增清單（17 個）
| 表 | 索引名稱 | 欄位 |
|----|---------|------|
| Student | idx_student_campus_name | (CampusID, name) |
| Student | idx_student_campus_status | (CampusID, status) |
| Student | idx_student_rfid | (RFID) |
| StudentClass | idx_sc_student_id | (StudentID) |
| StudentClass | idx_sc_teacher_id | (TeacherID) |
| StudentClass | idx_sc_stop_student | (Stop, StudentID) |
| ClassSession | idx_cs_status | (Status) |
| StudentSingIn | idx_ssi_student_id | (StudentID) |
| StudentSingIn | idx_ssi_sc_id | (StudentClassID) |
| StudentSingIn | idx_ssi_signindt | (SignInDT) |
| Invoice | idx_inv_student_id | (StudentID) |
| Invoice | idx_inv_sc_id | (StudentClassID) |
| Invoice | idx_inv_status | (Status) |
| Payment | idx_pay_invoice_id | (InvoiceID) |
| UserCampus | idx_uc_user_id | (UserID) |
| UserCampus | idx_uc_campus_approved | (CampusID, Approved) |

### 效能改善
- 9 組核心查詢：`type: ALL` → `type: ref`
- 索引額外空間：~200 KB
- 回滾方式：`php artisan migrate:rollback --step=1 --force`

---

## 簽核

| 角色 | 姓名 | 日期 | 簽核 |
|------|------|------|------|
| CTO / 工程 Lead | __________ | ____/____/____ | [ ] 確認 |
| PM | __________ | ____/____/____ | [ ] 確認 |
