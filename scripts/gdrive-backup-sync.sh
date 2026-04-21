#!/usr/bin/env bash
# gdrive-backup-sync.sh — 將 Pi 本機備份同步到 Google Drive
#
# 策略：
#   - 每日 nightly .sql.gz → g-drive:AllTrue-Backups/db/  （保留 14 份）
#   - 每月 monthly .sql.gz → g-drive:AllTrue-Backups/monthly/ （保留 12 份）
#   - 不同步原始碼（GitHub 已是程式碼異地備份）
#   - 不同步 emergency/ 目錄（包含程式碼 tar.gz，大且 GitHub 已覆蓋）
#
# 排程：crontab 每天凌晨 02:30（nightly 在 01:00 完成後）

set -euo pipefail

RCLONE="/home/admin/bin/rclone"
REMOTE="g-drive:AllTrue-Backups"
BACKUP_DIR="/home/admin/backups"
LOG="/home/admin/backups/gdrive-sync.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [gdrive-sync] $*" | tee -a "$LOG"; }

log "=== Google Drive sync start ==="

# ── 每日備份 → db/ ──
log "Syncing nightly dumps to ${REMOTE}/db/ ..."
"$RCLONE" copy \
  --include "alltrue_nightly_*.sql.gz" \
  --transfers 2 \
  --retries 3 \
  --low-level-retries 5 \
  --log-level ERROR \
  "$BACKUP_DIR" \
  "${REMOTE}/db/" 2>&1 | tee -a "$LOG" || log "WARN: nightly sync had errors (non-fatal)"

# ── 月份快照 → monthly/ ──
log "Syncing monthly snapshots to ${REMOTE}/monthly/ ..."
"$RCLONE" copy \
  --include "alltrue_monthly_*.sql.gz" \
  --transfers 2 \
  --retries 3 \
  --log-level ERROR \
  "$BACKUP_DIR/monthly" \
  "${REMOTE}/monthly/" 2>&1 | tee -a "$LOG" || log "WARN: monthly sync had errors (non-fatal)"

# ── 遠端檔案數確認 ──
DB_COUNT=$("$RCLONE" ls "${REMOTE}/db/" 2>/dev/null | wc -l || echo "?")
MONTHLY_COUNT=$("$RCLONE" ls "${REMOTE}/monthly/" 2>/dev/null | wc -l || echo "?")
log "Remote db/ files: ${DB_COUNT}, monthly/ files: ${MONTHLY_COUNT}"

# ── 遠端舊備份清理（保留最新 14 份 nightly，12 份 monthly）──
log "Pruning old remote backups..."
"$RCLONE" delete \
  --min-age 15d \
  --include "alltrue_nightly_*.sql.gz" \
  "${REMOTE}/db/" 2>/dev/null || true

"$RCLONE" delete \
  --min-age 370d \
  --include "alltrue_monthly_*.sql.gz" \
  "${REMOTE}/monthly/" 2>/dev/null || true

# ── Log 檔大小控管（超過 500KB 截頭）──
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 512000 ]; then
  tail -c 256000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
fi

log "=== Google Drive sync done ==="
