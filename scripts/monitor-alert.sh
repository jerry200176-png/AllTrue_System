#!/bin/bash
# AllTrue 系統監控告警腳本
# 用途：
#   1. Pi 本機資源定期自檢（由 cron 呼叫）
#   2. 每日狀態日報
#
# 設定方式：
#   chmod +x /home/admin/scripts/monitor-alert.sh
#   在 /home/admin/.env.monitor 填入 Telegram 或 LINE Messaging API 設定

set -euo pipefail

ENV_FILE="/home/admin/.env.monitor"
if [ -f "$ENV_FILE" ]; then
    # shellcheck source=/dev/null
    source "$ENV_FILE"
fi

TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"
TELEGRAM_CHAT_ID="${TELEGRAM_CHAT_ID:-}"
LINE_CHANNEL_TOKEN="${LINE_CHANNEL_TOKEN:-}"
LINE_GROUP_ID="${LINE_GROUP_ID:-}"
HEALTH_URL="${HEALTH_URL:-https://daan.lifenet.com.tw/api/v1/health}"
DISK_ALERT_PCT="${DISK_ALERT_PCT:-85}"
TEMP_ALERT_C="${TEMP_ALERT_C:-70}"

# ─────────────────────────────────────────────
# 函數：發送通知（自動選擇 Telegram 或 LINE Messaging API）
# ─────────────────────────────────────────────
send_notify() {
    local message="$1"

    if [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ]; then
        curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
            -H "Content-Type: application/json" \
            -d "{\"chat_id\":\"${TELEGRAM_CHAT_ID}\",\"text\":$(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')}" \
            > /dev/null
        echo "[NOTIFY] Telegram 已發送"

    elif [ -n "$LINE_CHANNEL_TOKEN" ] && [ -n "$LINE_GROUP_ID" ]; then
        curl -s -X POST "https://api.line.me/v2/bot/message/push" \
            -H "Authorization: Bearer ${LINE_CHANNEL_TOKEN}" \
            -H "Content-Type: application/json" \
            -d "{\"to\":\"${LINE_GROUP_ID}\",\"messages\":[{\"type\":\"text\",\"text\":$(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')}]}" \
            > /dev/null
        echo "[NOTIFY] LINE Messaging API 已發送"

    else
        echo "[WARN] 未設定通知管道（請填入 TELEGRAM_BOT_TOKEN 或 LINE_CHANNEL_TOKEN）"
    fi
}

# ─────────────────────────────────────────────
# 模式 1：Pi 本機資源自檢（cron 每 30 分鐘呼叫）
# ─────────────────────────────────────────────
check_resources() {
    local alerts=""

    DISK=$(df / | tail -1 | awk '{print $5}' | tr -d '%')
    if [ "$DISK" -gt "$DISK_ALERT_PCT" ]; then
        alerts="${alerts}\n⚠️ 磁碟使用率 ${DISK}%（門檻 ${DISK_ALERT_PCT}%）"
    fi

    TEMP="N/A"
    if command -v vcgencmd &> /dev/null; then
        TEMP=$(vcgencmd measure_temp 2>/dev/null | grep -oP '[0-9]+\.[0-9]+' || echo "N/A")
        if [ "$TEMP" != "N/A" ]; then
            TEMP_INT=${TEMP%.*}
            if [ "$TEMP_INT" -gt "$TEMP_ALERT_C" ]; then
                alerts="${alerts}\n🌡️ CPU 溫度 ${TEMP}°C（門檻 ${TEMP_ALERT_C}°C）"
            fi
        fi
    fi

    RAM_PCT=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
    if [ "$RAM_PCT" -gt 90 ]; then
        alerts="${alerts}\n💾 RAM 使用率 ${RAM_PCT}%"
    fi

    HTTP_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$HEALTH_URL" --max-time 10 || echo "000")
    if [ "$HTTP_STATUS" != "200" ]; then
        alerts="${alerts}\n🔴 Health API 回傳 ${HTTP_STATUS}（預期 200）"
    fi

    if [ -n "$alerts" ]; then
        send_notify "$(printf '🖥️ AllTrue Pi 資源告警\n%s' "$alerts")"
    else
        echo "[OK] 所有資源正常（磁碟 ${DISK}%，溫度 ${TEMP}°C，RAM ${RAM_PCT}%，API ${HTTP_STATUS}）"
    fi
}

# ─────────────────────────────────────────────
# 模式 2：每日狀態日報（cron 每天早上 8 點呼叫）
# ─────────────────────────────────────────────
daily_report() {
    DISK=$(df / | tail -1 | awk '{print $5}')
    RAM_PCT=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
    TEMP="N/A"
    if command -v vcgencmd &> /dev/null; then
        TEMP=$(vcgencmd measure_temp 2>/dev/null | grep -oP '[0-9]+\.[0-9]+' || echo "N/A")
    fi
    HTTP_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$HEALTH_URL" --max-time 10 || echo "000")

    STATUS_ICON="✅"
    if [ "$HTTP_STATUS" != "200" ]; then STATUS_ICON="🔴"; fi

    send_notify "$(printf '📊 AllTrue 每日狀態報告\n%s API：%s\n💿 磁碟：%s\n💾 RAM：%s%%\n🌡️ 溫度：%s°C' \
        "$STATUS_ICON" "$HTTP_STATUS" "$DISK" "$RAM_PCT" "$TEMP")"
}

# ─────────────────────────────────────────────
# 入口
# ─────────────────────────────────────────────
MODE="${1:-check}"

case "$MODE" in
    check)   check_resources ;;
    report)  daily_report ;;
    test)    send_notify "✅ AllTrue 監控腳本測試成功！通知管道正常運作。$(date '+%Y-%m-%d %H:%M')" ;;
    *)       echo "用法：$0 [check|report|test]" && exit 1 ;;
esac
