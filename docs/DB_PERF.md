# 資料庫效能優化

**日期**：2026-04-16 | **階段**：P0 完成，P1 架構已準備

---

## 1. 變更摘要

- 17 個索引已套用（migration `2026_04_16_100000_add_core_perf_indexes.php`）
- `StudentController::activeCourses` N+1 查詢已修復
- 9 組核心查詢：`type: ALL` → `type: ref`，索引額外空間 ~200 KB
- 讀寫分離設定已準備（`config/database.php` read/write/sticky + persistent PDO）

### 索引清單

| 表 | 索引 | 欄位 |
|----|------|------|
| Student | idx_student_campus_name | (CampusID, name) |
| Student | idx_student_campus_status | (CampusID, status) |
| Student | idx_student_rfid | (RFID) |
| StudentClass | idx_sc_student_id / teacher_id / stop_student | (StudentID), (TeacherID), (Stop, StudentID) |
| ClassSession | idx_cs_status | (Status) |
| StudentSingIn | idx_ssi_student_id / sc_id / signindt | (StudentID), (StudentClassID), (SignInDT) |
| Invoice | idx_inv_student_id / sc_id / status | (StudentID), (StudentClassID), (Status) |
| Payment | idx_pay_invoice_id | (InvoiceID) |
| UserCampus | idx_uc_user_id / campus_approved | (UserID), (CampusID, Approved) |

---

## 2. 回滾 P0 索引

```bash
cd /home/admin/backend
php artisan migrate:rollback --step=1 --force
# 驗證：
mysql -u admin -p -h 127.0.0.1 AllTrue -e "SHOW INDEX FROM Student;"
```

從備份完整還原：
```bash
mysql -u admin -p -h 127.0.0.1 AllTrue < /home/admin/backups/alltrue_pre_perf_optimization_2026-04-16.sql
```

---

## 3. P1 讀寫分離

### 啟用
在 `backend/.env` 加入：
```
DB_READ_HOST=<replica-ip>
DB_READ_PORT=3306
DB_PERSISTENT=true
```
然後 `php artisan config:clear`。

### 回滾
移除 `DB_READ_HOST` 與 `DB_READ_PORT`，然後 `php artisan config:clear`。

---

## 4. 維護窗口

- 建議時段：平日 22:00 後或週日
- migration 前必須備份：
```bash
mysqldump -u admin -p -h 127.0.0.1 AllTrue --single-transaction > /home/admin/backups/alltrue_$(date +%Y%m%d_%H%M%S).sql
```

---

## 5. 監控

| 項目 | 位置/指令 |
|------|-----------|
| Slow Query Log | `Pi5-slow.log`（閾值 0.5s，含未用索引查詢） |
| API 效能日誌 | `backend/storage/logs/perf-*.log` |
| 驗證索引 | `EXPLAIN SELECT * FROM Student WHERE CampusID = 1 ORDER BY name\G`（type 應為 ref） |

---

## 6. 基線快照摘要（2026-04-16）

- 硬體：Raspberry Pi 5, 8GB RAM, NVMe SSD 917GB
- DB：MariaDB 10.11.6，最大連線 151，歷史最高同時 10
- API 整體：P50=12.1ms, P90=51.6ms, P95=77.1ms, P99=270.5ms（20,073 筆樣本）
- 高延遲端點：`learning-records/ensure-past` 平均 200ms，`class-sessions` P99=452ms

---

## 7. 資安審查結論

STRIDE 快評全部 PASS，無阻擋項：
- 索引 migration 不改變認證/資料邊界
- `LogSlowRequests` 不記錄 PII（僅 path、duration、role、branch_id）
- P1 replica 帳號建議 GRANT SELECT ONLY

---

## 8. 簽核

| 角色 | 姓名 | 日期 | 簽核 |
|------|------|------|------|
| CTO / 工程 Lead | __________ | ____/____/____ | [ ] 確認 |
| PM | __________ | ____/____/____ | [ ] 確認 |
