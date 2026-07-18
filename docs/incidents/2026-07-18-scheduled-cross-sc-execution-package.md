# Execution Package：scheduled-cross-sc + orphan Stop=1（2026-07-18）

> **狀態**：Draft — **禁止在 CEO GO 前於 production `--execute`**  
> **關聯**：[`2026-07-18-xindian-duplicate-attendance-slots.md`](2026-07-18-xindian-duplicate-attendance-slots.md)

---

## 1. Preflight（Pi，唯讀）

```bash
cd /home/admin/backend
php artisan fix:orphan-scheduled-sessions --dry-run | tee /tmp/orphan-dry-$(date +%Y%m%d).txt
php artisan repair:duplicate-sessions --case=scheduled-cross-sc | tee /tmp/sched-cross-dry-$(date +%Y%m%d).txt
php artisan classsession:audit-duplicates --branch_id=9 | tee /tmp/audit-9-$(date +%Y%m%d).txt
```

確認輸出含本案學生（王品方／陳品承）時，把 `cs.id` / `SC.id` 回填 incident §4。

## 2. Backup

```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" \
  --single-transaction AllTrue ClassSession StudentClass \
  | gzip > /home/admin/backups/emergency/db_pre_sched_cross_${TS}.sql.gz
```

## 3. Execute（需 CEO GO + `ALLOW_PROD_REPAIR=1`）

```bash
# 1) Stop=1 orphans first
php artisan fix:orphan-scheduled-sessions

# 2) Dual-active / ghost scheduled overlaps
ALLOW_PROD_REPAIR=1 php artisan repair:duplicate-sessions \
  --case=scheduled-cross-sc --execute --force \
  --snapshot=/home/admin/backups/emergency/sched-cross-snap-${TS}.json
```

## 4. Verify

```bash
php artisan fix:orphan-scheduled-sessions --dry-run   # expect clean / 0
php artisan repair:duplicate-sessions --case=scheduled-cross-sc  # expect actions=0
# 黃芝琳出缺勤：王品方／陳品承各一列
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
```

## 5. Rollback

從 snapshot JSON + mysqldump 還原受影響 `ClassSession.Status` / `StudentClass.Stop`（勿 migrate:fresh）。
