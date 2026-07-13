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
TEMP_SENSOR_FAULT_C="${TEMP_SENSOR_FAULT_C:-100}"
TEMP_EMERGENCY_C="${TEMP_EMERGENCY_C:-85}"

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
# 函數：解析 vcgencmd get_throttled bitmask
# 回傳格式：throttled=<hex>|<dec>|flag1,flag2,...
# 參考：https://github.com/raspberrypi/documentation/blob/develop/documentation/asciidoc/computers/os/legacy-bcm2835-vcgencmd.adoc
# ─────────────────────────────────────────────
get_throttled_status() {
    if ! command -v vcgencmd &>/dev/null; then
        echo "throttled=N/A"
        return 1
    fi

    local hex_val
    hex_val=$(vcgencmd get_throttled 2>/dev/null | grep -oP '0x[0-9a-fA-F]+' || echo "")

    if [ -z "$hex_val" ]; then
        echo "throttled=N/A"
        return 1
    fi

    local dec_val=$((hex_val))
    local flags=""

    # Bit 0: Under-voltage detected
    [ $((dec_val & (1<<0))) -ne 0 ] && flags="${flags}undervoltage,"
    # Bit 1: Arm frequency capped
    [ $((dec_val & (1<<1))) -ne 0 ] && flags="${flags}freq_capped,"
    # Bit 2: Currently throttled
    [ $((dec_val & (1<<2))) -ne 0 ] && flags="${flags}throttled,"
    # Bit 3: Soft temperature limit active
    [ $((dec_val & (1<<3))) -ne 0 ] && flags="${flags}soft_temp_limit,"
    # Bit 16: Under-voltage has occurred
    [ $((dec_val & (1<<16))) -ne 0 ] && flags="${flags}undervoltage_occurred,"
    # Bit 17: Arm frequency capping has occurred
    [ $((dec_val & (1<<17))) -ne 0 ] && flags="${flags}freq_cap_occurred,"
    # Bit 18: Throttling has occurred
    [ $((dec_val & (1<<18))) -ne 0 ] && flags="${flags}throttle_occurred,"
    # Bit 19: Soft temperature limit has occurred
    [ $((dec_val & (1<<19))) -ne 0 ] && flags="${flags}soft_temp_occurred,"

    flags="${flags%,}"
    [ -z "$flags" ] && flags="normal"

    echo "throttled=${hex_val}|${dec_val}|${flags}"
    return 0
}

# ─────────────────────────────────────────────
# 函數：雙來源交叉驗證讀取 CPU 溫度
# 回傳格式：temp=<value>|source=<source>|flags=<flags>
# 優先使用 vcgencmd，fallback 到 thermal_zone0
# ─────────────────────────────────────────────
read_cpu_temp() {
    local temp_vcg="N/A"
    local temp_tz="N/A"
    local source="none"
    local flags=""

    # 來源 1：vcgencmd（Raspberry Pi 專用）
    if command -v vcgencmd &>/dev/null; then
        temp_vcg=$(vcgencmd measure_temp 2>/dev/null | grep -oP '[0-9]+(\.[0-9]+)?' || echo "N/A")
    fi

    # 來源 2：thermal_zone0（Linux 通用）
    if [ -f /sys/class/thermal/thermal_zone0/temp ]; then
        local raw
        raw=$(cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null)
        if [ -n "$raw" ] && [ "$raw" -gt 0 ] 2>/dev/null; then
            temp_tz=$(awk "BEGIN {printf \"%.1f\", ${raw}/1000}")
        fi
    fi

    # 決策：選用哪個來源
    if [ "$temp_vcg" != "N/A" ]; then
        source="vcgencmd"
        TEMP="$temp_vcg"

        # 交叉驗證：若兩個來源都有，檢查差異
        if [ "$temp_tz" != "N/A" ]; then
            local diff
            diff=$(awk "BEGIN {printf \"%.1f\", ${temp_vcg} - ${temp_tz}")
            local abs_diff=${diff#-}
            if [ "$(awk "BEGIN {print (${abs_diff} > 15.0)}")" = "1" ]; then
                flags="sensor_divergence_${diff}C"
                # 差異過大時改用 thermal_zone0（較可靠）
                TEMP="$temp_tz"
                source="thermal_zone0(fallback)"
            fi
        fi
    elif [ "$temp_tz" != "N/A" ]; then
        source="thermal_zone0"
        TEMP="$temp_tz"
    else
        echo "temp=N/A|source=none|flags=no_sensor"
        return 1
    fi

    # 合理性檢查
    local temp_int=${TEMP%.*}
    if [ "$temp_int" -gt 120 ] 2>/dev/null; then
        flags="${flags:+${flags},}invalid_reading"
        echo "temp=${TEMP}|source=${source}|flags=${flags}"
        return 1
    fi

    # 感測器故障檢測：溫度 ≥100°C 但無降頻 = 感測器異常
    if [ "$temp_int" -ge "$TEMP_SENSOR_FAULT_C" ] 2>/dev/null; then
        local throttled_info
        throttled_info=$(get_throttled_status 2>/dev/null || echo "throttled=N/A")
        if echo "$throttled_info" | grep -qE 'throttled,[^N]|throttle_occurred'; then
            flags="${flags:+${flags},}emergency_overheat"
        elif echo "$throttled_info" | grep -q 'normal'; then
            flags="${flags:+${flags},}sensor_fault"
        else
            flags="${flags:+${flags},}sensor_unverified"
        fi
    fi

    echo "temp=${TEMP}|source=${source}|flags=${flags}"
    return 0
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

    # 使用雙來源交叉驗證讀取 CPU 溫度
    TEMP_INFO=$(read_cpu_temp 2>/dev/null || echo "temp=N/A|source=none|flags=no_sensor")
    TEMP=$(echo "$TEMP_INFO" | grep -oP '(?<=temp=)[^|]+')
    TEMP_SOURCE=$(echo "$TEMP_INFO" | grep -oP '(?<=source=)[^|]+')
    TEMP_FLAGS=$(echo "$TEMP_INFO" | grep -oP '(?<=flags=).*')

    if [ "$TEMP" != "N/A" ]; then
        TEMP_INT=${TEMP%.*}
        # 緊急過熱：溫度 ≥85°C 且有降頻確認
        if echo "$TEMP_FLAGS" | grep -q 'emergency_overheat' 2>/dev/null; then
            alerts="${alerts}\n🚨 緊急過熱！CPU ${TEMP}°C（降頻已觸發，來源：${TEMP_SOURCE}）"
        # 感測器異常：溫度 ≥100°C 但無降頻
        elif echo "$TEMP_FLAGS" | grep -q 'sensor_fault' 2>/dev/null; then
            alerts="${alerts}\n⚠️ CPU 感測器疑似異常（回報 ${TEMP}°C 但無降頻，來源：${TEMP_SOURCE}）"
        # 讀數無效
        elif echo "$TEMP_FLAGS" | grep -q 'invalid_reading' 2>/dev/null; then
            alerts="${alerts}\n⚠️ CPU 溫度讀數無效：${TEMP}°C（超過物理極限）"
        # 感測器分歧
        elif echo "$TEMP_FLAGS" | grep -q 'sensor_divergence' 2>/dev/null; then
            alerts="${alerts}\n⚠️ CPU 雙感測器分歧，已切換至 ${TEMP_SOURCE}（${TEMP}°C）"
        # 一般高溫
        elif [ "$TEMP_INT" -gt "$TEMP_ALERT_C" ] 2>/dev/null; then
            alerts="${alerts}\n🌡️ CPU 溫度 ${TEMP}°C（門檻 ${TEMP_ALERT_C}°C，來源：${TEMP_SOURCE}）"
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
        echo "[ALERT] $(date '+%Y-%m-%d %H:%M') 資源異常，發送通知"
        send_notify "$(printf '🖥️ AllTrue Pi 資源告警\n%s' "$alerts")"
    fi
}

# ─────────────────────────────────────────────
# 模式 2：每日狀態日報（cron 每天早上 8 點呼叫）
# ─────────────────────────────────────────────
daily_report() {
    DISK=$(df / | tail -1 | awk '{print $5}')
    RAM_PCT=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
    TEMP_INFO=$(read_cpu_temp 2>/dev/null || echo "temp=N/A|source=none|flags=no_sensor")
    TEMP=$(echo "$TEMP_INFO" | grep -oP '(?<=temp=)[^|]+')
    TEMP_SOURCE=$(echo "$TEMP_INFO" | grep -oP '(?<=source=)[^|]+')
    TEMP_FLAGS=$(echo "$TEMP_INFO" | grep -oP '(?<=flags=).*')
    THROTTLE_INFO=$(get_throttled_status 2>/dev/null | grep -oP '[^|]+$' || echo "N/A")

    HTTP_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$HEALTH_URL" --max-time 10 || echo "000")

    STATUS_ICON="✅"
    if [ "$HTTP_STATUS" != "200" ]; then STATUS_ICON="🔴"; fi

    TEMP_DISPLAY="${TEMP}°C"
    if echo "$TEMP_FLAGS" | grep -q 'emergency_overheat' 2>/dev/null; then
        TEMP_DISPLAY="🚨${TEMP}°C(過熱!)"
    elif echo "$TEMP_FLAGS" | grep -q 'sensor_fault' 2>/dev/null; then
        TEMP_DISPLAY="⚠️${TEMP}°C(感測器?)"
    fi

    send_notify "$(printf '📊 AllTrue 每日狀態報告\n%s API：%s\n💿 磁碟：%s\n💾 RAM：%s%%\n🌡️ 溫度：%s（來源：%s）\n⚡ 降頻：%s' \
        "$STATUS_ICON" "$HTTP_STATUS" "$DISK" "$RAM_PCT" "$TEMP_DISPLAY" "$TEMP_SOURCE" "$THROTTLE_INFO")"
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
