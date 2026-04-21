#!/usr/bin/env bash
# gdrive-backup-sync.sh — 將 Pi 本機備份同步到 Google Drive
#
# 策略：
#   - 每日 nightly .sql.gz  → g-drive:AllTrue-Backups/db/      （保留 14 份）
#   - 每月 monthly .sql.gz  → g-drive:AllTrue-Backups/monthly/ （保留 12 份）
#   - 最新 2 份 sixhour     → g-drive:AllTrue-Backups/sixhour/ （提供 6h 異地快照）
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

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [gdrive-sync] $*" | tee -a "$LOG"; }

log "=== Google Drive sync start ==="

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
  "${REMOTE}/db/" 2>&1 | tee -a "$LOG" || log "WARN: nightly sync had errors (non-fatal)"

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
  "${REMOTE}/monthly/" 2>&1 | tee -a "$LOG" || log "WARN: monthly sync had errors (non-fatal)"

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

# ── 遠端檔案數確認 ──
sleep 5
DB_COUNT=$("$RCLONE" ls "${REMOTE}/db/" 2>/dev/null | wc -l || echo "?")
MONTHLY_COUNT=$("$RCLONE" ls "${REMOTE}/monthly/" 2>/dev/null | wc -l || echo "?")
SIXHOUR_COUNT=$("$RCLONE" ls "${REMOTE}/sixhour/" 2>/dev/null | wc -l || echo "?")
log "Remote files — db/: ${DB_COUNT}, monthly/: ${MONTHLY_COUNT}, sixhour/: ${SIXHOUR_COUNT}"

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

# ── Log 檔大小控管（超過 500KB 截頭）──
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 512000 ]; then
  tail -c 256000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
fi

log "=== Google Drive sync done ==="
