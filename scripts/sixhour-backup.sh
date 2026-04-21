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
KEEP=8   # 保留最新 8 份 = 2 天

# --- Telegram EXIT notification -----------------------------------------------
# 備份結束（成功或失敗）時發送 Telegram 告警。
# .env.monitor 不存在或 Token/Chat ID 未填寫時靜默降級，不影響備份主流程。
on_exit() {
  local exit_code="$1"
  local script_id="6h-backup"
  local env_file="/home/admin/.env.monitor"
  local token=""
  local chat_id=""

  if [ -f "$env_file" ]; then
    local TELEGRAM_BOT_TOKEN=""
    local TELEGRAM_CHAT_ID=""
    # shellcheck source=/dev/null
    source "$env_file" 2>/dev/null || true
    token="${TELEGRAM_BOT_TOKEN:-}"
    chat_id="${TELEGRAM_CHAT_ID:-}"
  fi

  if [ -z "$token" ] || [ -z "$chat_id" ]; then
    return 0
  fi

  local timestamp
  timestamp="$(date '+%Y-%m-%d %H:%M')"
  local message
  if [ "$exit_code" = "0" ]; then
    message="✅ ${script_id} 備份成功 @ ${timestamp}"
  else
    message="🚨 ${script_id} 備份失敗 (exit=${exit_code}) @ ${timestamp}"
  fi

  local payload
  payload="$(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')"

  curl -s -X POST "https://api.telegram.org/bot${token}/sendMessage" \
    -H "Content-Type: application/json" \
    -d "{\"chat_id\":\"${chat_id}\",\"text\":${payload}}" \
    > /dev/null 2>&1 || true
}
trap 'on_exit $?' EXIT

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
