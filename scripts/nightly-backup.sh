#!/usr/bin/env bash
# nightly-backup.sh — MySQL dump + git push
# Runs at 01:00 daily via cron.
# Keeps last 7 SQL dumps to avoid filling disk.

set -euo pipefail

REPO_ROOT="/home/admin"
BACKUP_DIR="$REPO_ROOT/backups"
ENV_FILE="$REPO_ROOT/backend/.env"
LOG_FILE="$REPO_ROOT/backups/nightly-backup.log"
KEEP_DAYS=7

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# --- Parse .env for DB credentials ---
get_env() {
  grep -m1 "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r'
}

DB_HOST=$(get_env DB_HOST)
DB_PORT=$(get_env DB_PORT)
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)

log "=== Nightly backup start ==="

# --- Step 1: MySQL dump ---
TIMESTAMP=$(date '+%Y-%m-%d_%H%M')
DUMP_FILE="$BACKUP_DIR/alltrue_nightly_${TIMESTAMP}.sql.gz"

log "Dumping MySQL database '$DB_DATABASE' to $DUMP_FILE ..."
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

# --- Step 2: Remove old dumps (keep last KEEP_DAYS) ---
log "Removing dumps older than $KEEP_DAYS days ..."
find "$BACKUP_DIR" -name "alltrue_nightly_*.sql.gz" -mtime +${KEEP_DAYS} -delete && \
  log "Old dumps cleaned." || log "No old dumps to remove."

# --- Step 3: Cursor plans 主題索引（失敗不阻斷備份）---
log "Refreshing plan topic index ..."
if python3 "$REPO_ROOT/.cursor/plans/list-plans-by-topic.py" --write-index -q; then
  log "Topic index OK."
else
  log "WARN: topic index refresh failed (continuing)."
fi

# --- Step 4: Git commit + push ---
cd "$REPO_ROOT"

log "Running git-sync.sh ..."
./scripts/git-sync.sh "chore(nightly): auto backup ${TIMESTAMP}" 2>&1 | tee -a "$LOG_FILE"

log "=== Nightly backup done ==="
