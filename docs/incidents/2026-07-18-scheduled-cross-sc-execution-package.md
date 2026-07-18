# Execution Package：scheduled-cross-sc（2026-07-18）

> Draft — **CEO GO 前禁止 `--execute`**

```bash
# Preflight (Pi)
cd /home/admin/backend
DATE=2026-07-18 CAMPUS_ID=9 bash /home/admin/scripts/diagnose-classsession-duplicates.sh \
  || DATE=2026-07-18 CAMPUS_ID=9 bash scripts/diagnose-classsession-duplicates.sh
php artisan fix:orphan-scheduled-sessions --dry-run
php artisan repair:duplicate-sessions --case=scheduled-cross-sc

# Backup then execute (ALLOW_PROD_REPAIR=1 + --force)
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD .env | cut -d= -f2)" \
  --single-transaction AllTrue ClassSession StudentClass \
  | gzip > /home/admin/backups/emergency/db_pre_sched_cross_${TS}.sql.gz
php artisan fix:orphan-scheduled-sessions
ALLOW_PROD_REPAIR=1 php artisan repair:duplicate-sessions \
  --case=scheduled-cross-sc --execute --force

# Verify：黃芝琳出缺勤各一列；dry-run actions=0；health ok
```
