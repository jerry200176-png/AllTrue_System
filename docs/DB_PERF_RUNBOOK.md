# 資料庫效能優化 Runbook

## P0 索引與查詢優化

### 已完成項目
- 17 個索引已套用到生產庫（migration `2026_04_16_100000`）
- `StudentController::activeCourses` N+1 查詢已修復
- 基線快照已歸檔（`docs/DB_PERF_BASELINE_2026-04-16.md`）

### 回滾 P0 索引

```bash
cd /home/admin/backend
php artisan migrate:rollback --step=1 --force
```

驗證回滾成功：

```bash
mysql -u admin -p -h 127.0.0.1 AllTrue -e "SHOW INDEX FROM Student;"
# 應只剩 PRIMARY
```

### 從備份完整還原

```bash
mysql -u admin -p -h 127.0.0.1 AllTrue < /home/admin/backups/alltrue_pre_perf_optimization_2026-04-16.sql
```

## P1 讀寫分離啟用

### 前置條件
1. MySQL replica 已建立且同步正常
2. replica 帳號僅有 SELECT 權限
3. 確認 replica lag < 1 秒

### 啟用步驟

在 `backend/.env` 加入：

```
DB_READ_HOST=<replica-ip>
DB_READ_PORT=3306
DB_PERSISTENT=true
```

清除設定快取：

```bash
cd /home/admin/backend
php artisan config:clear
```

### 驗證

```bash
# 檢查 Laravel 是否辨識 read config
php artisan tinker --execute="dd(config('database.connections.mysql.read'));"
```

### 回滾 P1（關閉讀寫分離）

從 `.env` 移除 `DB_READ_HOST` 與 `DB_READ_PORT`，然後：

```bash
php artisan config:clear
```

所有查詢將恢復走 primary。

## 維護窗口

- 建議時段：平日 22:00 後或週日
- 操作前通知主任，告知預計影響時間（< 5 分鐘）
- migration 前必須先備份：

```bash
mysqldump -u admin -p -h 127.0.0.1 AllTrue --single-transaction > /home/admin/backups/alltrue_$(date +%Y%m%d_%H%M%S).sql
```

## 監控

### Slow Query Log（已開啟）
- 檔案：`Pi5-slow.log`
- 閾值：0.5 秒
- 包含未使用索引的查詢

### API 效能日誌
- 檔案：`backend/storage/logs/perf-*.log`
- SLO 閾值定義在 `LogSlowRequests.php`

### 驗證索引生效

```bash
mysql -u admin -p -h 127.0.0.1 AllTrue -e "
EXPLAIN SELECT * FROM Student WHERE CampusID = 1 ORDER BY name\G
"
# type 應為 ref，不應為 ALL
```
