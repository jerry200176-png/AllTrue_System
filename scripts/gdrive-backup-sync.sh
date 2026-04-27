#!/usr/bin/env bash
# gdrive-backup-sync.sh — 將 Pi 本機備份同步到 Google Drive
#
# 策略：
#   - 每日 nightly .sql.gz  → g-drive:AllTrue-Backups/db/      （保留 14 份）
#   - 每月 monthly .sql.gz  → g-drive:AllTrue-Backups/monthly/ （保留 12 份）
#   - 最新 2 份 sixhour     → g-drive:AllTrue-Backups/sixhour/ （提供 6h 異地快照）
#   - manifest .txt          → g-drive:AllTrue-Backups/manifests/（檔名/大小/sha256）
#   - 不同步原始碼（GitHub 已是程式碼異地備份）
#   - 不同步 emergency/ 目錄（大且 GitHub 已覆蓋）
#
# 排程：crontab 每天凌晨 03:00
#   - nightly-backup.sh 在 01:00 完成
#   - monthly-restore-drill.sh 在 02:00（每月 1 日）
#   - 03:00 距前兩者各有 ≥1 小時緩衝，避免 API rate limit

set -euo pipefail

RCLONE="/home/admin/bin/rclone"
REMOTE="g-drive:AllTrue-Backups"
BACKUP_DIR="/home/admin/backups"
LOG="/home/admin/backups/gdrive-sync.log"

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

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [gdrive-sync] $*" | tee -a "$LOG"; }

SYNC_ERRORS=0

log "=== Google Drive sync start ==="

# ── 產生 manifest：讓每次異地同步都有可稽核的檔名 / 大小 / checksum ──
MANIFEST_DIR="${BACKUP_DIR}/manifests"
MANIFEST="${MANIFEST_DIR}/backup_manifest_$(date '+%Y%m%d_%H%M%S').txt"
mkdir -p "$MANIFEST_DIR"
{
  echo "AllTrue backup manifest"
  echo "generated_at=$(date -Iseconds)"
  echo "host=$(hostname)"
  echo
  echo "sha256 size_bytes path"
  for pattern in \
    "${BACKUP_DIR}"/alltrue_nightly_*.sql.gz \
    "${BACKUP_DIR}/monthly"/alltrue_monthly_*.sql.gz \
    "${BACKUP_DIR}/sixhour"/alltrue_6h_*.sql.gz
  do
    [ -e "$pattern" ] || continue
    sha256sum "$pattern" | awk -v size="$(stat -c%s "$pattern")" '{print $1, size, $2}'
  done
} > "$MANIFEST"
log "Manifest generated: ${MANIFEST}"

# ── 啟動前退讓：避免與其他凌晨 cron 觸發的 API burst 衝突 ──
sleep 15

# ── 每日備份 → db/ ──
log "Syncing nightly dumps to ${REMOTE}/db/ ..."
"$RCLONE" copy \
  --include "alltrue_nightly_*.sql.gz" \
  --transfers 1 \
  --retries 5 \
  --retries-sleep 30s \
  --low-level-retries 10 \
  --log-level ERROR \
  "$BACKUP_DIR" \
  "${REMOTE}/db/" 2>&1 | tee -a "$LOG" || { log "WARN: nightly sync had errors (non-fatal)"; SYNC_ERRORS=$((SYNC_ERRORS+1)); }

# ── 月份快照 → monthly/ ──
sleep 10
log "Syncing monthly snapshots to ${REMOTE}/monthly/ ..."
"$RCLONE" copy \
  --include "alltrue_monthly_*.sql.gz" \
  --transfers 1 \
  --retries 5 \
  --retries-sleep 30s \
  --log-level ERROR \
  "$BACKUP_DIR/monthly" \
  "${REMOTE}/monthly/" 2>&1 | tee -a "$LOG" || { log "WARN: monthly sync had errors (non-fatal)"; SYNC_ERRORS=$((SYNC_ERRORS+1)); }

# ── 最新 2 份 sixhour → sixhour/（6h 異地快照）──
sleep 10
log "Syncing latest 2 sixhour dumps to ${REMOTE}/sixhour/ ..."
SIXHOUR_FILES=$(ls -t "${BACKUP_DIR}/sixhour"/alltrue_6h_*.sql.gz 2>/dev/null | head -2)
if [ -n "$SIXHOUR_FILES" ]; then
  for f in $SIXHOUR_FILES; do
    "$RCLONE" copy \
      --transfers 1 \
      --retries 3 \
      --retries-sleep 20s \
      --log-level ERROR \
      "$f" \
      "${REMOTE}/sixhour/" 2>&1 | tee -a "$LOG" || log "WARN: sixhour sync had errors (non-fatal)"
  done
  log "Sixhour sync done ($(echo "$SIXHOUR_FILES" | wc -l) files)"
else
  log "WARN: No sixhour backups found to sync"
fi

# ── 同步 manifest → manifests/ ──
sleep 5
log "Syncing manifest to ${REMOTE}/manifests/ ..."
"$RCLONE" copy \
  --transfers 1 \
  --retries 3 \
  --retries-sleep 20s \
  --log-level ERROR \
  "$MANIFEST" \
  "${REMOTE}/manifests/" 2>&1 | tee -a "$LOG" || { log "WARN: manifest sync had errors (non-fatal)"; SYNC_ERRORS=$((SYNC_ERRORS+1)); }

# ── 遠端檔案數確認 ──
sleep 5
DB_COUNT=$("$RCLONE" ls "${REMOTE}/db/" 2>/dev/null | wc -l || echo "?")
MONTHLY_COUNT=$("$RCLONE" ls "${REMOTE}/monthly/" 2>/dev/null | wc -l || echo "?")
SIXHOUR_COUNT=$("$RCLONE" ls "${REMOTE}/sixhour/" 2>/dev/null | wc -l || echo "?")
MANIFEST_COUNT=$("$RCLONE" ls "${REMOTE}/manifests/" 2>/dev/null | wc -l || echo "?")
log "Remote files — db/: ${DB_COUNT}, monthly/: ${MONTHLY_COUNT}, sixhour/: ${SIXHOUR_COUNT}, manifests/: ${MANIFEST_COUNT}"

# ── 遠端舊備份清理（保留最新 14 份 nightly，12 份 monthly，4 份 sixhour）──
log "Pruning old remote backups..."
"$RCLONE" delete \
  --min-age 15d \
  --include "alltrue_nightly_*.sql.gz" \
  "${REMOTE}/db/" 2>/dev/null || true

"$RCLONE" delete \
  --min-age 370d \
  --include "alltrue_monthly_*.sql.gz" \
  "${REMOTE}/monthly/" 2>/dev/null || true

"$RCLONE" delete \
  --min-age 2d \
  --include "alltrue_6h_*.sql.gz" \
  "${REMOTE}/sixhour/" 2>/dev/null || true

"$RCLONE" delete \
  --min-age 370d \
  --include "backup_manifest_*.txt" \
  "${REMOTE}/manifests/" 2>/dev/null || true

# ── Log 檔大小控管（超過 500KB 截頭）──
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 512000 ]; then
  tail -c 256000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
fi

# ── 同步結果通知 ──
if [ "$SYNC_ERRORS" -gt 0 ]; then
  send_telegram "$(printf '⚠️ AllTrue Google Drive 備份異常\n%d 個同步任務失敗，請檢查 gdrive-sync.log\n時間：%s' \
    "$SYNC_ERRORS" "$(date '+%Y-%m-%d %H:%M')")"
  log "WARN: $SYNC_ERRORS sync error(s) — Telegram notified"
else
  log "All syncs completed successfully"
fi

log "=== Google Drive sync done ==="
