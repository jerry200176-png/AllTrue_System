#!/usr/bin/env bash
# monthly-restore-drill.sh — 每月 1 日 02:00 還原最新 dump 至 AllTrue_test 並比對 row count
# Bug Fix Plan: .cursor/plans/備份還原驗證_b9ac261d.plan.md
#
# 流程：
#   1. 讀取 backend/.env 取 DB 憑證；讀取 .env.monitor 取 Telegram 憑證
#   2. 選最新月備；若無 fallback 最新日備
#   3. gzip -t 驗證完整性（失敗 → Telegram + exit 1）
#   4. DROP + CREATE AllTrue_test，還原 dump
#   5. 比對 Student / User / Campus / ClassSession row count
#   6. 結果透過 Telegram 推送（成功 ✅ 或差異 ⚠️）

set -euo pipefail

REPO_ROOT="/home/admin"
BACKUP_DIR="$REPO_ROOT/backups"
MONTHLY_DIR="$BACKUP_DIR/monthly"
ENV_FILE="$REPO_ROOT/backend/.env"
MONITOR_ENV="$REPO_ROOT/.env.monitor"
LOG_FILE="$BACKUP_DIR/restore-drill.log"
TEST_DB="AllTrue_test"
TABLES=(Student User Campus ClassSession)

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

get_env() {
  grep -m1 "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r'
}

DB_HOST=$(get_env DB_HOST)
DB_PORT=$(get_env DB_PORT)
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)

TELEGRAM_BOT_TOKEN=""
TELEGRAM_CHAT_ID=""
if [ -f "$MONITOR_ENV" ]; then
  # shellcheck source=/dev/null
  source "$MONITOR_ENV"
fi
TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"
TELEGRAM_CHAT_ID="${TELEGRAM_CHAT_ID:-}"

send_telegram() {
  local message="$1"
  if [ -z "$TELEGRAM_BOT_TOKEN" ] || [ -z "$TELEGRAM_CHAT_ID" ]; then
    log "WARN: Telegram 憑證未設定，略過通知"
    return 0
  fi
  local payload
  payload=$(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps({"chat_id":"'"$TELEGRAM_CHAT_ID"'","text":sys.stdin.read()}))')
  if curl -s --max-time 15 -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
      -H "Content-Type: application/json" \
      -d "$payload" > /dev/null; then
    log "Telegram 已發送"
  else
    log "WARN: Telegram 發送失敗"
  fi
}

mysql_prod()  { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -B "$@"; }
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$@"; }

fail() {
  local reason="$1"
  log "ERROR: $reason"
  send_telegram "❌ AllTrue 備份還原演練失敗
主機: $(hostname)
時間: $(date '+%Y-%m-%d %H:%M:%S')
原因: $reason
Log: $LOG_FILE"
  exit 1
}

log "=== Restore drill start ==="

# Step 1: 選最新 dump（月備優先，fallback 日備）
LATEST_DUMP=$(ls -1t "$MONTHLY_DIR"/alltrue_monthly_*.sql.gz 2>/dev/null | head -1 || true)
DUMP_SOURCE="monthly"
if [ -z "$LATEST_DUMP" ]; then
  LATEST_DUMP=$(ls -1t "$BACKUP_DIR"/alltrue_nightly_*.sql.gz 2>/dev/null | head -1 || true)
  DUMP_SOURCE="nightly (fallback)"
fi
[ -n "$LATEST_DUMP" ] || fail "找不到任何 dump 檔案（monthly/ 及 nightly 皆為空）"
log "選定 dump: $LATEST_DUMP (來源: $DUMP_SOURCE)"

# Step 2: 完整性驗證
log "驗證 gzip 完整性 ..."
gzip -t "$LATEST_DUMP" 2>/dev/null || fail "dump 檔案 gzip 完整性驗證失敗: $LATEST_DUMP"
log "gzip -t OK"

# Step 3: DROP + 重建 AllTrue_test
log "DROP + CREATE $TEST_DB ..."
mysql_admin -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
  || fail "DROP/CREATE $TEST_DB 失敗"

# Step 4: 還原 dump
log "還原 dump 至 $TEST_DB ..."
if ! gunzip -c "$LATEST_DUMP" | mysql_admin "$TEST_DB" 2>>"$LOG_FILE"; then
  fail "還原 dump 失敗（見 log）"
fi
log "還原完成"

# Step 5: row count 比對
log "比對 row count ..."
mismatches=""
detail_lines=""
for t in "${TABLES[@]}"; do
  prod_count=$(mysql_prod -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.\`$t\`;" 2>/dev/null || echo "ERR")
  test_count=$(mysql_prod -e "SELECT COUNT(*) FROM \`$TEST_DB\`.\`$t\`;" 2>/dev/null || echo "ERR")
  if [ "$prod_count" = "ERR" ] || [ "$test_count" = "ERR" ]; then
    mismatches="${mismatches}${t} (查詢失敗)\n"
    detail_lines="${detail_lines}- ${t}: 查詢失敗\n"
    continue
  fi
  diff=$((prod_count - test_count))
  if [ "$prod_count" = "$test_count" ]; then
    detail_lines="${detail_lines}- ${t}: ${prod_count}\n"
    log "  $t: prod=$prod_count  test=$test_count  ✓"
  else
    mismatches="${mismatches}${t} prod=${prod_count} test=${test_count} diff=${diff}\n"
    detail_lines="${detail_lines}- ${t}: prod=${prod_count} test=${test_count} (diff=${diff})\n"
    log "  $t: prod=$prod_count  test=$test_count  DIFF=$diff"
  fi
done

DUMP_NAME=$(basename "$LATEST_DUMP")
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

if [ -z "$mismatches" ]; then
  msg="✅ AllTrue 備份還原演練成功
主機: $(hostname)
時間: $TIMESTAMP
來源: $DUMP_NAME ($DUMP_SOURCE)
Row count 全數一致:
$(printf '%b' "$detail_lines")"
  send_telegram "$msg"
  log "=== Restore drill done (OK) ==="
  exit 0
else
  msg="⚠️ AllTrue 備份還原演練發現 row count 差異
主機: $(hostname)
時間: $TIMESTAMP
來源: $DUMP_NAME ($DUMP_SOURCE)
各表比對:
$(printf '%b' "$detail_lines")
Log: $LOG_FILE"
  send_telegram "$msg"
  log "=== Restore drill done (MISMATCH) ==="
  exit 1
fi
