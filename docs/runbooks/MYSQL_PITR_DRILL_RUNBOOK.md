# MySQL PITR drill runbook (#881 / TD-015)

> **Never restore into production `AllTrue`.** Production binlog is enabled by
> `scripts/pi-enable-binlog.sh` (Founder decision 2026-11-07, `docs/OPERATIONS_RUNBOOK.md` §P).
> For a real incident, run steps 4–5 below into a new scratch DB on the Pi, verify, then decide
> the minimal repair through the normal reviewed data-change path.

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
# 7-day retention — matches the pre-enable checklist's 3-7 day range.
# (Own line: inline `;` comments are not valid in option files.)
binlog_expire_logs_seconds = 604800
```

Production runs MariaDB (the disposable CI drill and this log use 10.11). On
MariaDB the client tools are `mariadb`, `mariadb-dump`, `mariadb-binlog`
(`mysql*` names remain as aliases). The binlog directory defaults to the
datadir (`/var/lib/mysql/` in the Docker image) unless `log_bin` sets a path;
substitute that path wherever step 4 reads `/var/log/mysql/`.

```bash
sudo systemctl restart mysql
mysql -u root -p -e "SHOW VARIABLES LIKE 'log_bin';"   # expect ON
mysql -u root -p -e "SHOW BINARY LOGS;"
```

## 2. Take a full backup (the PITR baseline)

The production backup scripts (`scripts/nightly-backup.sh`,
`scripts/sixhour-backup.sh`) add `--master-data=2` automatically once binlog is
on (a schema-only probe decides), so every dump carries the replay anchor.

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
# `--stop-datetime` has 1-second granularity: keep >=1s between the last
# wanted write, TARGET_TIME and the destructive write (the sleeps below).
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

# Do NOT add --database=${DRILL_DB} here: the filter matches the *original*
# (source) name, so every event is skipped and the replay silently applies
# nothing (drill 2026-11-06: baseline-only rows came back, exit status 0).
# Replay every binlog file from the anchor file onward. Restarts and 100M rotation split the binlog, and
# --start-position applies to the first file only. Production keeps binlogs in the datadir
# (log_bin = mysql-bin); the drill cnf in step 1 uses /var/log/mysql/.
BINLOG_DIR=$(mysql -N -u root -p -e 'SELECT @@datadir')   # drill host: BINLOG_DIR=/var/log/mysql/
BINLOG_FILES=$(ls "${BINLOG_DIR}"mysql-bin.[0-9]* | sort | awk -v f="${BINLOG_FILE}" '$0 ~ "/" f "$" {on=1} on')
[ -n "$BINLOG_FILES" ] || { echo "anchor ${BINLOG_FILE} not found in ${BINLOG_DIR}"; exit 1; }
mysqlbinlog --rewrite-db="${SOURCE_DB}->${DRILL_DB}" \
  --start-position="$BINLOG_POS" \
  --stop-datetime="$TARGET_TIME" \
  $BINLOG_FILES \
  | mysql -u root -p "${DRILL_DB}"
```

## 5. Verify

A replay that applied nothing exits 0, so always prove the post-baseline writes
arrived: compare row counts and `CHECKSUM TABLE` of the drill DB against the
expected pre-incident state, and check the bad write is absent (e.g. the
deleted rows are present again).

```bash
mysql -u root -p AllTrue_pitr_drill -e \
  "SELECT id, StudentClassID, SessionDate FROM ClassSession WHERE id = <inserted row id>;"
# Expect: 1 row (the insert replayed, the later delete did not)
```

Row-count sanity against the pre-incident baseline, same pattern as
`scripts/backup-audit.sh` uses for snapshot restores:

```bash
mysql -u root -p AllTrue_pitr_drill -e \
  "SELECT COUNT(*) FROM ClassSession; SELECT COUNT(*) FROM StudentClass; SELECT COUNT(*) FROM Invoice;
   CHECKSUM TABLE ClassSession, StudentClass, Invoice;"
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

## Drill log 2026-11-06

- **Engine**: disposable Docker `mariadb:10.11` (10.11.19), `--log-bin
  --binlog-format=ROW`; same engine family as production. Synthetic rows only;
  no production or remote host contacted. Container and volume removed after
  the run.
- **Data**: `Student` (20), `ClassSession` (50), `payment_records` (30), all
  fake.
- **Steps**: seed -> full dump (`--single-transaction --quick --no-tablespaces
  --routines --triggers --master-data=2`, the nightly flags plus the anchor) ->
  5 more writes (insert x3, update, insert) -> record T -> `DELETE FROM
  payment_records` + 2 more writes -> restore dump into new scratch DB ->
  `mariadb-binlog --rewrite-db --start-position --stop-datetime=T` replay.
- **Timings**: server ready 6.7s (29s on first image pull); seed 0.2s; dump
  0.1s; writes + bad delete 4.7s (includes two deliberate 2s sleeps); baseline
  restore 0.4s; binlog replay 0.1s; verify 0.2s. Total restore path under 1s
  at this size; real timing scales with dump size and binlog volume.
- **Result**: PASS. Restored counts Student 21 / ClassSession 51 /
  payment_records 31. Every pre-T write present (inserts and the UPDATE),
  the bad delete and both post-delete writes absent. Source DB afterwards held
  22 / 51 / 1, with different checksums for Student and payment_records.
- **Found**: (1) adding `--database=<drill db>` next to `--rewrite-db` replays
  nothing silently (step 4 note). (2) Inline `;` comments in the cnf snippet
  are invalid. (3) Production dumps lack `--master-data=2` (step 2 note).
  (4) Binlog path is the datadir by default.
- **Production still lacks** (per `docs/OPERATIONS_RUNBOOK.md` §P, binlog
  deferred): `log_bin` enabled with `binlog_format=ROW` and retention; binlog
  disk and offsite-sync capacity for 3-7 days; backup scripts emitting the
  `--master-data=2` anchor; a staging/Pi drill (this was a local container
  only); and a Founder decision to enable it.

## Refs

Refs #881, TD-015, `docs/OPERATIONS_RUNBOOK.md` §P "PITR / Binlog Decision (2026-05-09)".
