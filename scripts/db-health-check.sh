#!/usr/bin/env bash
# db-health-check.sh — 監控關鍵資料表筆數，異常時寫 alert log 並發 LINE 通知
# 建議由 cron 每 15 分鐘執行一次

set -euo pipefail

REPO_ROOT="/home/admin"
ENV_FILE="$REPO_ROOT/backend/.env"
ALERT_LOG="$REPO_ROOT/backups/db-alert.log"
STATE_FILE="$REPO_ROOT/backups/.db-health-state"

# 低水位警戒線（低於此值觸發警報）
declare -A THRESHOLDS=(
    [User]=5
    [Student]=10
    [StudentClass]=10
    [Teacher]=3
    [ClassSession]=50
    [Campus]=3
)

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$ALERT_LOG"; }

get_env() { grep -m1 "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r'; }

DB_HOST=$(get_env DB_HOST)
DB_PORT=$(get_env DB_PORT)
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)
# ── 讀取 Telegram 設定（統一從 .env.monitor 取）──
MONITOR_ENV="$REPO_ROOT/.env.monitor"
TELEGRAM_BOT_TOKEN=""
TELEGRAM_CHAT_ID=""
if [ -f "$MONITOR_ENV" ]; then
    # shellcheck source=/dev/null
    source "$MONITOR_ENV"
fi

send_telegram_alert() {
    local msg="$1"
    # Telegram
    if [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ]; then
        curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
            -H "Content-Type: application/json" \
            -d "{\"chat_id\":\"${TELEGRAM_CHAT_ID}\",\"text\":$(printf '%s' "$msg" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')}" \
            > /dev/null 2>&1 || true
        echo "[NOTIFY] Telegram 已發送"
    else
        echo "[WARN] Telegram 未設定（請確認 $MONITOR_ENV 中有 TELEGRAM_BOT_TOKEN 與 TELEGRAM_CHAT_ID）"
    fi
    # 也寫到 Laravel log
    local laravel_log="$REPO_ROOT/backend/storage/logs/laravel-$(date '+%Y-%m-%d').log"
    echo "[$(date '+Y-m-d H:i:s')] production.CRITICAL: [db-alert] $msg" >> "$laravel_log" 2>/dev/null || true
}

ALERTS=()
CURRENT_STATE=""

for TABLE in "${!THRESHOLDS[@]}"; do
    COUNT=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
        -N -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.\`$TABLE\`;" 2>/dev/null || echo "-1")

    THRESHOLD="${THRESHOLDS[$TABLE]}"
    CURRENT_STATE+="${TABLE}=${COUNT};"

    if [ "$COUNT" -lt "$THRESHOLD" ] 2>/dev/null; then
        ALERTS+=("⚠️ ${TABLE} 剩 ${COUNT} 筆（警戒線 ${THRESHOLD}）")
        log "ALERT: ${TABLE}=${COUNT} < threshold ${THRESHOLD}"
    fi
done

# 比對上次狀態，檢測暴跌
if [ -f "$STATE_FILE" ]; then
    PREV_STATE=$(cat "$STATE_FILE")
    for TABLE in "${!THRESHOLDS[@]}"; do
        PREV_COUNT=$(echo "$PREV_STATE" | grep -o "${TABLE}=[0-9]*" | cut -d= -f2 || echo "0")
        CURR_COUNT=$(echo "$CURRENT_STATE" | grep -o "${TABLE}=[0-9]*" | cut -d= -f2 || echo "0")
        if [ -n "$PREV_COUNT" ] && [ -n "$CURR_COUNT" ] && \
           [ "$PREV_COUNT" -gt 100 ] 2>/dev/null && [ "$CURR_COUNT" -lt 10 ] 2>/dev/null; then
            ALERTS+=("🚨 ${TABLE} 暴跌：${PREV_COUNT} → ${CURR_COUNT}（可能遭清空！）")
            log "CRITICAL DROP: ${TABLE} ${PREV_COUNT} -> ${CURR_COUNT}"
        fi
    done
fi

echo "$CURRENT_STATE" > "$STATE_FILE"

if [ ${#ALERTS[@]} -gt 0 ]; then
    MSG=$'\n[AllTrue DB Alert]\n'"$(printf '%s\n' "${ALERTS[@]}")"$'\n'"時間: $(date '+%Y-%m-%d %H:%M:%S')"
    log "Sending alert: $MSG"
    send_telegram_alert "$MSG"
else
    log "OK — all tables above threshold"
fi
