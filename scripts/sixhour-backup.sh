#!/usr/bin/env bash
# sixhour-backup.sh — 每 6 小時輕量備份（僅 SQL dump，無 git push）
# 由 cron 每天 05:00 / 11:00 / 17:00 / 23:00 呼叫（01:00 由 nightly-backup.sh 負責完整備份+git）
#
# 保留策略：最多保留最新 8 份（2 天）

set -euo pipefail

REPO_ROOT="/home/admin"
BACKUP_DIR="$REPO_ROOT/backups/sixhour"
ENV_FILE="$REPO_ROOT/backend/.env"
LOG_FILE="$REPO_ROOT/backups/nightly-backup.log"
KEEP=12  # 保留最新 12 份 = 3 天（RPO 視窗降至最差 3 天可回溯）

mkdir -p "$BACKUP_DIR"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [6h] $*" | tee -a "$LOG_FILE"; }

get_env() { grep -m1 "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r'; }

DB_HOST=$(get_env DB_HOST)
DB_PORT=$(get_env DB_PORT)
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)

TIMESTAMP=$(date '+%Y-%m-%d_%H%M')
DUMP_FILE="$BACKUP_DIR/alltrue_6h_${TIMESTAMP}.sql.gz"

log "=== 6-hour backup start ==="
log "Dumping '$DB_DATABASE' → $DUMP_FILE ..."

mysqldump \
  -h "$DB_HOST" \
  -P "$DB_PORT" \
  -u "$DB_USERNAME" \
  -p"$DB_PASSWORD" \
  --single-transaction \
  --quick \
  --no-tablespaces \
  "$DB_DATABASE" | gzip > "$DUMP_FILE"

log "Dump complete: $(du -sh "$DUMP_FILE" | cut -f1)"

# 清理超過 KEEP 份的舊備份
log "Pruning old 6h dumps (keep latest $KEEP) ..."
ls -1t "$BACKUP_DIR"/alltrue_6h_*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
  [ -n "$old" ] && rm -f "$old" && log "Removed: $(basename "$old")"
done

log "=== 6-hour backup done ==="
