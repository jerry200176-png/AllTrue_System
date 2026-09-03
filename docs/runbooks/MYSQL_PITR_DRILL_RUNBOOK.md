# MySQL PITR drill runbook (#881 / TD-015)

> **Drill-only. Do not run any step here against production `AllTrue`.**
> `docs/OPERATIONS_RUNBOOK.md` §P's 2026-05-09 decision record defers enabling
> production binlog until one of its three trigger conditions is met. This
> runbook exists so that when that trigger fires, the drill SOP the decision
> record calls for already exists — it does not itself decide to enable
> anything. Run every step below only against the staging host from #868
> (`docs/STAGING_ENVIRONMENT.md`) or a disposable local/drill database.

## What this proves

That a "full backup + binlog replay" recovery can restore the database to an
arbitrary point in time between two snapshot backups — the gap the existing
nightly/sixhour snapshot chain cannot close (an incident between two
snapshots loses everything since the last one).

## 1. Enable binlog (drill host only)

In `/etc/mysql/mysql.conf.d/mysqld.cnf` (or the drill host's equivalent):

```ini
[mysqld]
server-id = 1
log_bin = /var/log/mysql/mysql-bin.log
binlog_format = ROW
binlog_expire_logs_seconds = 604800   ; 7-day retention — matches the
                                        ; pre-enable checklist's 3-7 day range
```

```bash
sudo systemctl restart mysql
mysql -u root -p -e "SHOW VARIABLES LIKE 'log_bin';"   # expect ON
mysql -u root -p -e "SHOW BINARY LOGS;"
```

## 2. Take a full backup (the PITR baseline)

```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -u root -p --single-transaction --routines --triggers \
  --master-data=2 AllTrue_staging | gzip > "pitr_baseline_${TS}.sql.gz"
```

`--master-data=2` records the binlog file + position at dump time as a
commented `CHANGE MASTER TO` line inside the dump — this is the anchor the
replay starts from. Confirm it's there:

```bash
zcat "pitr_baseline_${TS}.sql.gz" | grep -m1 "CHANGE MASTER TO"
```

## 3. Simulate an incident + a target recovery point

```bash
mysql -u root -p AllTrue_staging -e "INSERT INTO ClassSession (...) VALUES (...);"
# Ask the drill server for its own wall clock so client/server timezone
# differences cannot move the recovery point across the destructive event.
TARGET_TIME=$(mysql -u root -p -Nse \
  "SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 SECOND), '%Y-%m-%d %H:%i:%s')")
sleep 2
mysql -u root -p AllTrue_staging -e "DELETE FROM ClassSession WHERE id = <the row just inserted>;"
```

`$TARGET_TIME` is the point-in-time the drill will recover to — the
inserted row should exist afterward, the delete should not have replayed.

## 4. Restore: baseline + binlog replay to the target time

```bash
SOURCE_DB=AllTrue_staging
DRILL_DB=AllTrue_pitr_drill
mysql -u root -p -e "CREATE DATABASE \`${DRILL_DB}\`;"

# mysqldump contains a USE for SOURCE_DB. Rewrite that context before loading
# into the disposable database; otherwise the baseline is loaded back into
# the source database instead of the drill database.
zcat "pitr_baseline_${TS}.sql.gz" \
  | sed "s/\\\`${SOURCE_DB}\\\`/\\\`${DRILL_DB}\\\`/g" \
  | mysql -u root -p "${DRILL_DB}"

BINLOG_FILE=$(zcat "pitr_baseline_${TS}.sql.gz" | grep -m1 "CHANGE MASTER TO" \
  | sed -n "s/.*MASTER_LOG_FILE='\([^']*\)'.*/\1/p")
BINLOG_POS=$(zcat "pitr_baseline_${TS}.sql.gz" | grep -m1 "CHANGE MASTER TO" \
  | sed -n "s/.*MASTER_LOG_POS=\([0-9]*\).*/\1/p")

mysqlbinlog --rewrite-db="${SOURCE_DB}->${DRILL_DB}" \
  --start-position="$BINLOG_POS" \
  --stop-datetime="$TARGET_TIME" \
  "/var/log/mysql/${BINLOG_FILE}" \
  | mysql -u root -p "${DRILL_DB}"
```

## 5. Verify

```bash
mysql -u root -p AllTrue_pitr_drill -e \
  "SELECT id, StudentClassID, SessionDate FROM ClassSession WHERE id = <inserted row id>;"
# Expect: 1 row (the insert replayed, the later delete did not)
```

Row-count sanity against the pre-incident baseline, same pattern as
`scripts/backup-audit.sh` uses for snapshot restores:

```bash
mysql -u root -p AllTrue_pitr_drill -e \
  "SELECT COUNT(*) FROM ClassSession; SELECT COUNT(*) FROM StudentClass; SELECT COUNT(*) FROM Invoice;"
```

## 6. Rollback / cleanup

Drill databases are disposable — no production state was ever touched:

```bash
mysql -u root -p -e "DROP DATABASE AllTrue_pitr_drill;"
rm -f "pitr_baseline_${TS}.sql.gz"
```

If this were ever run for a real incident (production, only after the
decision record's trigger condition fires and a Founder approves enabling
binlog there): restore to a **new** database name first, verify row counts
and spot-check rows, and only cut the application over after verification —
never replay binlog directly into the live `AllTrue` database.

## Drill log template

Record every real drill run here (append, do not overwrite):

| Date | Baseline backup | Target recovery time | Binlog file:pos | Result | Notes |
|---|---|---|---|---|---|
| 2026-09-03 | disposable local MariaDB 10.11 synthetic baseline (removed after run) | 2026-09-03 19:28:01 (drill server time) | `mysql-bin.000001:694` | PASS | `PitrMarker` insert restored; post-target delete absent; `--rewrite-db` used for the drill database; temporary datadir/socket removed. Staging/Pi drill remains pending. |

## Refs

Refs #881, TD-015, `docs/OPERATIONS_RUNBOOK.md` §P "PITR / Binlog Decision (2026-05-09)".
