#!/usr/bin/env bash
# nightly-backup.sh — MySQL dump (+ monthly full archive)
# Runs at 01:00 daily via cron.
#
# 保留策略：
#   - 每日夜備 SQL：保留 KEEP_DAYS 天（預設 14）
#   - 每月 1 號額外產生「月首全備」：保留 KEEP_MONTHS 份（預設 12 個月）
#   - 程式碼真實來源為 GitHub protected main + PR history；本腳本不再從 Pi 嘗試 git push/tag。

set -euo pipefail

REPO_ROOT="/home/admin"
BACKUP_DIR="$REPO_ROOT/backups"
MONTHLY_DIR="$BACKUP_DIR/monthly"
ENV_FILE="$REPO_ROOT/backend/.env"
LOG_FILE="$REPO_ROOT/backups/nightly-backup.log"
KEEP_DAYS=14
KEEP_MONTHS=12

mkdir -p "$MONTHLY_DIR"

# ── Telegram 通知（讀取統一設定檔）──
MONITOR_ENV="/home/admin/.env.monitor"
TELEGRAM_BOT_TOKEN=""
TELEGRAM_CHAT_ID=""
if [ -f "$MONITOR_ENV" ]; then
    # shellcheck source=/dev/null
    source "$MONITOR_ENV"
fi

send_telegram() {
    local msg="$1"
    [ -z "$TELEGRAM_BOT_TOKEN" ] || [ -z "$TELEGRAM_CHAT_ID" ] && return 0
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
        -H "Content-Type: application/json" \
        -d "{\"chat_id\":\"${TELEGRAM_CHAT_ID}\",\"text\":$(printf '%s' "$msg" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')}" \
        > /dev/null 2>&1 || true
}

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
TODAY_DOM=$(date '+%d')            # 當月第幾日（01..31）
TODAY_YM=$(date '+%Y-%m')
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

# --- Step 1c: 備份完整性驗證 ---
BACKUP_INSERT_COUNT=$(zcat "$DUMP_FILE" 2>/dev/null | grep -c "^INSERT INTO" || echo "0")
log "Backup integrity: INSERT INTO count = ${BACKUP_INSERT_COUNT}"
if [ "${BACKUP_INSERT_COUNT}" -lt 20 ]; then
  log "CRITICAL: backup may be empty or corrupt! INSERT count=${BACKUP_INSERT_COUNT} < threshold 20"
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [db-alert] CRITICAL: nightly dump INSERT count=${BACKUP_INSERT_COUNT} (expected >=20). File: $(basename "$DUMP_FILE")" >> "$REPO_ROOT/backups/db-alert.log"
  send_telegram "$(printf '🚨 AllTrue 夜備告警\n備份檔可能為空或損壞！\nINSERT 行數：%s（門檻 20）\n檔案：%s\n時間：%s' \
    "$BACKUP_INSERT_COUNT" "$(basename "$DUMP_FILE")" "$(date '+%Y-%m-%d %H:%M')")"
else
  log "Backup integrity OK (INSERT count=${BACKUP_INSERT_COUNT})"
fi

# --- Step 1b: 月首（每月 1 號）產生月備，或在當月沒有月備時補一份 ---
MONTHLY_FILE="$MONTHLY_DIR/alltrue_monthly_${TODAY_YM}.sql.gz"
if [ "$TODAY_DOM" = "01" ] || [ ! -f "$MONTHLY_FILE" ]; then
  log "Creating monthly snapshot $MONTHLY_FILE (hardlink from daily) ..."
  ln -f "$DUMP_FILE" "$MONTHLY_FILE" 2>/dev/null || cp "$DUMP_FILE" "$MONTHLY_FILE"
fi

# --- Step 2a: 清掉 KEEP_DAYS 天前的日備 ---
log "Removing daily dumps older than $KEEP_DAYS days ..."
find "$BACKUP_DIR" -maxdepth 1 -name "alltrue_nightly_*.sql.gz" -mtime +${KEEP_DAYS} -delete && \
  log "Old daily dumps cleaned." || log "No old daily dumps to remove."

# --- Step 2b: 月備僅保留最新 KEEP_MONTHS 份 ---
log "Pruning monthly snapshots (keep latest $KEEP_MONTHS) ..."
ls -1t "$MONTHLY_DIR"/alltrue_monthly_*.sql.gz 2>/dev/null | tail -n +$((KEEP_MONTHS + 1)) | while read -r old; do
  [ -n "$old" ] && rm -f "$old" && log "Removed monthly: $(basename "$old")"
done

# --- Step 3: Cursor plans 主題索引（失敗不阻斷備份）---
log "Refreshing plan topic index ..."
if python3 "$REPO_ROOT/.cursor/plans/list-plans-by-topic.py" --write-index -q; then
  log "Topic index OK."
else
  log "WARN: topic index refresh failed (continuing)."
fi

log "=== Nightly backup done ==="

# --- 最終通知：備份完成 ---
DUMP_SIZE=$(du -sh "$DUMP_FILE" 2>/dev/null | cut -f1 || echo "?")
send_telegram "$(printf '✅ AllTrue 夜備完成\n檔案：%s\n大小：%s\nINSERT 行數：%s\n時間：%s' \
  "$(basename "$DUMP_FILE")" "$DUMP_SIZE" "$BACKUP_INSERT_COUNT" "$(date '+%Y-%m-%d %H:%M')")"
