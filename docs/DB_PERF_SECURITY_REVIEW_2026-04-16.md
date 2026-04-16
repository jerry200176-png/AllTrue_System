# 資安審查報告 — DB Performance Optimization

**日期**: 2026-04-16
**審查範圍**: P0 索引 migration + N+1 修復 + P1 讀寫分離/連線池設定

## STRIDE 快評

### Spoofing（偽裝）
- **狀態**: 無新風險
- 索引 migration 不改變認證機制
- P1 讀寫分離設定透過 `.env` 配置，伺服器存取已受 SSH 限制

### Tampering（竄改）
- **狀態**: 無新風險
- Migration 只做 `ADD INDEX` / `DROP INDEX`，不修改資料內容
- `addIfMissing` 方法安全處理重複索引，不會 silent fail 在非預期場景
- `down()` 方法完整，可完全回滾

### Repudiation（否認）
- **狀態**: 已覆蓋
- Migration 執行紀錄保留在 `migrations` 表中
- Laravel log 記錄 migration 時間戳

### Information Disclosure（資訊洩露）
- **狀態**: 無新風險
- `LogSlowRequests` middleware 不記錄 PII（已驗證：不含 Student.name、Phone、password 等）
- 效能日誌僅記錄 path、duration、role、branch_id
- P1 讀寫分離密碼透過 `.env` 管理，不硬編碼

### Denial of Service（阻斷服務）
- **狀態**: 改善
- 新增索引減少全表掃描，降低 DB 鎖競爭與 IO 壓力
- P1 `sticky = true` 確保寫後讀一致性，不造成無窮重試

### Elevation of Privilege（權限提升）
- **狀態**: 無新風險
- 索引不影響 `role:*` / `require_campus` middleware
- P1 replica 帳號配置建議使用 READ-ONLY 權限（待 P1 實施時確認）

## 校區隔離驗證

- `StudentController::index` 仍依 `CampusID` 篩選，索引 `idx_student_campus_name` 加速但不繞過
- `StudentController::activeCourses` N+1 修復後，`whereIn` 批次查詢仍受 `StudentID` 限定
- 測試 `test_student_campus_filter_returns_only_same_campus` 通過

## PII Log 檢查

- `LogSlowRequests.php`: 僅記錄 trace_id, method, path, status, duration_ms, role, branch_id — 無 PII
- `perf-*.log`: 已確認不含學生姓名、電話、密碼

## 結論

- **無阻擋項**
- P0 變更僅涉及索引與查詢優化，不影響存取控制或資料邊界
- P1 實施時需額外審查 replica 帳號權限（建議 GRANT SELECT ONLY）
