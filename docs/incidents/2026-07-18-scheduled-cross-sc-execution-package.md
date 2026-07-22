# Execution Package：scheduled-cross-sc（2026-07-18／verified 2026-07-22）

> **狀態**：Dry-run **VERIFIED on production**（32 actions）— **CEO GO 前禁止 `--execute`**  
> Evidence：Actions runs 29888205592 / 29888246248

## 1. Preflight（已跑，結果摘要）

```
php artisan fix:orphan-scheduled-sessions --dry-run
→ [TD-016] No orphan sessions found. All clean.

php artisan repair:duplicate-sessions --case=scheduled-cross-sc
→ DRY RUN actions=32
```

本案相關（王品方／黃芝琳）：

| WOULD cancel cs.id | SC (drop) | keep SC | keep cs.id | slot |
|-------------------:|----------:|--------:|-----------:|------|
| 10275 | 1272 | 2382 | 24033 | 2026-08-08 13:00 |
| 14925 | 1272 | 2382 | 19978 | 2026-08-15 13:00 |

全量 32 筆 cancel 清單見 diagnose artifact（同日 dry-run 輸出）。策略：保留 Stop=0 且 SessionCount>0 偏好後的 of-record SC，cancel 另一側 scheduled。

## 2. Backup（execute 前必做）

```bash
cd /home/admin/backend
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD .env | cut -d= -f2)" \
  --single-transaction AllTrue ClassSession StudentClass \
  | gzip > /home/admin/backups/emergency/db_pre_sched_cross_${TS}.sql.gz
```

## 3. Execute（需明示 CEO GO）

```bash
ALLOW_PROD_REPAIR=1 php artisan repair:duplicate-sessions \
  --case=scheduled-cross-sc --execute --force \
  --snapshot=/home/admin/backups/emergency/sched-cross-snap-${TS}.json
```

## 4. Verify

```bash
php artisan repair:duplicate-sessions --case=scheduled-cross-sc   # expect actions=0
# 黃芝琳出缺勤：王品方／陳品承同日同時段各一列
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
```

## 5. Out of scope（需另案）

- 2026-07-18 **陳品承雙 attended**（cs 24112 SC1946 + cs 20205 SC2399）— 非 scheduled case；可能已雙扣堂，需帳務／RemainingSessions 對帳後再修。
- 王品方 07-18 兩筆 leave（10274/24111）— 歷史狀態，scheduled-cross-sc 不改。

## 6. Rollback

從 mysqldump + snapshot JSON 還原受影響 `ClassSession.Status`（勿 migrate:fresh）。
