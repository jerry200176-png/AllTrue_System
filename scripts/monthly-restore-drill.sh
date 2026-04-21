#!/usr/bin/env bash
# monthly-restore-drill.sh — 每月 1 日自動驗證最新備份可正常還原
# 策略：還原到 AllTrue_drill 暫時資料庫 → 查 table count → 刪除
# 不觸碰正式 AllTrue 資料庫
set -euo pipefail

LOG_PREFIX="[restore-drill]"
DRILL_DB="AllTrue_drill"
BACKUP_DIR="/home/admin/backups"
ENV_FILE="/home/admin/backend/.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] ${LOG_PREFIX} $*"; }

# ── 讀取 DB 憑證 ──
DB_HOST=$(grep '^DB_HOST=' "$ENV_FILE" | cut -d= -f2)
DB_PORT=$(grep '^DB_PORT=' "$ENV_FILE" | cut -d= -f2)
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2)
MYSQL_OPTS="-h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER} -p${DB_PASS}"

log "=== Monthly restore drill start ==="

# ── 找最新的 nightly 備份 ──
LATEST=$(ls -t "${BACKUP_DIR}"/alltrue_nightly_*.sql.gz 2>/dev/null | head -1)
if [ -z "$LATEST" ]; then
  log "ERROR: No nightly backup found in ${BACKUP_DIR}"
  exit 1
fi
log "Using backup: $(basename "$LATEST")"

# ── 建立 drill 資料庫 ──
mysql ${MYSQL_OPTS} -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`;" 2>/dev/null || true
mysql ${MYSQL_OPTS} -e "CREATE DATABASE \`${DRILL_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
log "Created drill DB: ${DRILL_DB}"

# ── 還原 ──
log "Restoring..."
zcat "$LATEST" | mysql ${MYSQL_OPTS} "${DRILL_DB}"
log "Restore complete."

# ── 驗證 table count ──
TABLE_COUNT=$(mysql ${MYSQL_OPTS} -se "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DRILL_DB}';")
STUDENT_COUNT=$(mysql ${MYSQL_OPTS} -se "SELECT COUNT(*) FROM \`${DRILL_DB}\`.Student;" 2>/dev/null || echo "N/A")
log "Tables: ${TABLE_COUNT}, Students: ${STUDENT_COUNT}"

if [ "${TABLE_COUNT}" -lt 10 ]; then
  log "ERROR: Too few tables (${TABLE_COUNT}) — backup may be corrupt!"
  mysql ${MYSQL_OPTS} -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`;"
  exit 1
fi

# ── 清理 ──
mysql ${MYSQL_OPTS} -e "DROP DATABASE IF EXISTS \`${DRILL_DB}\`;"
log "Drill DB dropped."

log "=== Restore drill PASSED: ${TABLE_COUNT} tables, ${STUDENT_COUNT} students ==="
