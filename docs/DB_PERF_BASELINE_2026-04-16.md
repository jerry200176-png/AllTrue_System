# AllTrue 資料庫效能基線快照

**建立日期**: 2026-04-16
**目的**: 作為 PRD「AllTrue Database Optimization」KPI 驗收對照基準

## 1. 硬體環境

| 項目 | 值 |
|------|-----|
| 裝置 | Raspberry Pi 5 (aarch64) |
| 記憶體 | 8 GB（可用 ~4 GB） |
| 儲存 | NVMe SSD 917 GB（已用 34 GB / 4%） |
| 資料庫 | MariaDB 10.11.6 |
| 最大連線數 | 151 |
| 歷史最高同時連線 | 10 |

## 2. 核心表統計

| 表名 | 約略行數 | 資料大小 | 索引大小 | 現有索引數 |
|------|---------|---------|---------|-----------|
| ClassSession | 2,515 | 224 KB | 224 KB | 4 |
| LearningRecord | 1,237 | 224 KB | 352 KB | 7 |
| StudentClass | 337 | 112 KB | 32 KB | 3 (PK + room_id FK + PackageID) |
| Student | 329 | 48 KB | 0 KB | 1 (PK only) |
| StudentSingIn | 262 | 64 KB | 0 KB | 1 (PK only, ClassSessionID unique 未生效) |
| UserCampus | 104 | 16 KB | 0 KB | 0 (無 PK、無任何索引) |
| User | 70 | 16 KB | 32 KB | 2 |
| Subject | 7 | 16 KB | 16 KB | 2 |
| Invoice | 0 | 16 KB | 0 KB | 1 (PK only) |
| Payment | 0 | 16 KB | 0 KB | 1 (PK only) |

## 3. 現有索引覆蓋狀況（EXPLAIN 全表掃描清單）

以下查詢全部走 `type: ALL`（全表掃描），**無任何索引命中**：

| 查詢模式 | 表 | 掃描行數 | Extra |
|----------|-----|---------|-------|
| `Student WHERE CampusID = ? ORDER BY name` | Student | 329 | Using where; Using filesort |
| `StudentClass WHERE StudentID = ?` | StudentClass | 337 | Using where |
| `StudentClass WHERE TeacherID = ? AND Stop = 0` | StudentClass | 337 | Using where |
| `StudentSingIn WHERE StudentID = ?` | StudentSingIn | 262 | Using where |
| `Invoice WHERE StudentID = ?` | Invoice | (all) | Using where |
| `Payment WHERE InvoiceID = ?` | Payment | (all) | Using where |
| `UserCampus WHERE UserID = ?` | UserCampus | 104 | Using where |
| `UserCampus WHERE CampusID = ?` | UserCampus | 104 | Using where |
| `Student WHERE RFID = ?` | Student | 329 | Using where |

**唯一走索引的查詢**:
- `ClassSession WHERE StudentClassID = ? AND SessionDate BETWEEN ? AND ?` → 使用 `cs_scid_sessiondate_idx`

## 4. API 效能基線（perf log 2026-04-15 ~ 2026-04-16，共 20,073 筆）

### 整體

| P50 | P90 | P95 | P99 | Max | 樣本數 |
|-----|-----|-----|-----|-----|--------|
| 12.1ms | 51.6ms | 77.1ms | 270.5ms | 584.3ms | 20,073 |

### 分 API

| API 路徑 | P50 | P90 | P95 | P99 | Max | 樣本數 |
|----------|-----|-----|-----|-----|-----|--------|
| `/api/v1/students` | 7.5ms | 11.1ms | 12.2ms | 14.8ms | 20.9ms | 1,576 |
| `/api/v1/student-classes` | 25.1ms | 62.2ms | 72.2ms | 98.7ms | 146.0ms | 1,547 |
| `/api/v1/class-sessions` | 13.5ms | 46.9ms | 76.2ms | 452.5ms | 584.3ms | 8,033 |
| `/api/v1/learning-records` | 21.7ms | 36.5ms | 42.0ms | 53.4ms | 63.3ms | 793 |
| `/api/v1/notifications/unread-count` | 10.9ms | 38.2ms | 69.6ms | 88.4ms | 168.3ms | 6,698 |

### 高延遲端點

| API 路徑 | 平均 | 最大 | 樣本數 |
|----------|------|------|--------|
| `/api/v1/learning-records/ensure-past` | 200.2ms | 514.8ms | 63 |
| `/api/v1/student-classes/session-dates` | 83.5ms | 316.8ms | 723 |
| `/api/v1/class-sessions` | 30.4ms | 584.3ms | 8,033 |

## 5. MySQL Slow Query Log 設定

已於 2026-04-16 開啟：
- `slow_query_log = ON`
- `long_query_time = 0.5s`
- `log_queries_not_using_indexes = ON`
- 檔案：`Pi5-slow.log`

## 6. 已知 N+1 查詢

- `StudentController::activeCourses`（line 124）：迴圈內逐筆 `DB::table('Subject')->where('id', ...)->value(...)`

## 7. 備註

- 目前 API 延遲受益於 NVMe SSD（非 SD 卡），數值偏低；但隨資料量成長，全表掃描仍為瓶頸。
- `class-sessions` P99 = 452ms 已接近 SLO 閾值 800ms。
- 0 筆 slow request（>800ms），因數據量仍小；但多數核心查詢走全表掃描，成長後將快速劣化。
