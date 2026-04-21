#!/usr/bin/env bash
# nightly-backup.sh — MySQL dump + git push (+ monthly full archive + daily git tag)
# Runs at 01:00 daily via cron.
#
# 保留策略：
#   - 每日夜備 SQL：保留 KEEP_DAYS 天（預設 14）
#   - 每月 1 號額外產生「月首全備」：保留 KEEP_MONTHS 份（預設 12 個月）
#   - 每次 nightly 會打一個 git tag `nightly-YYYYMMDD-HHMM`（避免版本回溯時找不到錨點）；
#     tag 只保留 KEEP_TAGS 個最新（預設 60），舊的自動刪除（本地 + origin）。

set -euo pipefail

REPO_ROOT="/home/admin"
BACKUP_DIR="$REPO_ROOT/backups"
MONTHLY_DIR="$BACKUP_DIR/monthly"
ENV_FILE="$REPO_ROOT/backend/.env"
LOG_FILE="$REPO_ROOT/backups/nightly-backup.log"
KEEP_DAYS=14
KEEP_MONTHS=12
KEEP_TAGS=60

# --- Telegram EXIT notification -----------------------------------------------
# 備份結束（成功或失敗）時發送 Telegram 告警。
# .env.monitor 不存在或 Token/Chat ID 未填寫時靜默降級，不影響備份主流程。
on_exit() {
  local exit_code="$1"
  local script_id="nightly-backup"
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

mkdir -p "$MONTHLY_DIR"

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

# --- Step 4: Git commit + push ---
cd "$REPO_ROOT"

log "Running git-sync.sh ..."
./scripts/git-sync.sh "chore(nightly): auto backup ${TIMESTAMP}" 2>&1 | tee -a "$LOG_FILE"

# --- Step 5: 打 nightly tag（防版本回溯遺失錨點）---
TAG_NAME="nightly-${TIMESTAMP}"
log "Tagging commit as $TAG_NAME ..."
if git tag -a "$TAG_NAME" -m "nightly backup ${TIMESTAMP}" 2>&1 | tee -a "$LOG_FILE"; then
  if git push origin "$TAG_NAME" 2>&1 | tee -a "$LOG_FILE"; then
    log "Tag pushed."
  else
    log "WARN: tag push failed (continuing)."
  fi
else
  log "WARN: tag creation failed or already exists (continuing)."
fi

# --- Step 5b: Prune 舊 nightly tag，只保留 KEEP_TAGS 個最新 ---
log "Pruning nightly tags (keep latest $KEEP_TAGS) ..."
mapfile -t OLD_TAGS < <(git tag --list 'nightly-*' --sort=-creatordate | tail -n +$((KEEP_TAGS + 1)))
for t in "${OLD_TAGS[@]}"; do
  [ -z "$t" ] && continue
  git tag -d "$t" >/dev/null 2>&1 || true
  git push origin ":refs/tags/$t" >/dev/null 2>&1 || true
  log "Removed old tag: $t"
done

log "=== Nightly backup done ==="
