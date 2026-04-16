#!/usr/bin/env bash
# FR-007: One-click rollback — revert to direct-write logging.
# Idempotent: safe to run multiple times.
#
# Usage: sudo bash scripts/infra/rollback-log-tmpfs.sh
set -euo pipefail

TMPFS_MOUNT="/var/log/alltrue-tmpfs"
PERSIST_DIR="/home/admin/backend/storage/logs"
SYSTEMD_DIR="/etc/systemd/system"

echo "=== AllTrue Log Tmpfs Rollback ==="
echo ""

# 1. Final flush: save any remaining tmpfs logs
if mountpoint -q "$TMPFS_MOUNT" 2>/dev/null; then
    echo "[1/6] Final flush from tmpfs..."
    shopt -s nullglob
    for f in "$TMPFS_MOUNT"/*.log; do
        BASENAME=$(basename "$f")
        cat "$f" >> "${PERSIST_DIR}/${BASENAME}" 2>/dev/null || true
        echo "  Flushed: ${BASENAME}"
    done
else
    echo "[1/6] Tmpfs not mounted, skipping flush"
fi

# 2. Stop and disable timers
echo "[2/6] Stopping systemd timers..."
systemctl stop alltrue-log-flush.timer 2>/dev/null || true
systemctl stop alltrue-log-monitor.timer 2>/dev/null || true
systemctl disable alltrue-log-flush.timer 2>/dev/null || true
systemctl disable alltrue-log-monitor.timer 2>/dev/null || true

# 3. Remove systemd units
echo "[3/6] Removing systemd units..."
rm -f "${SYSTEMD_DIR}/alltrue-log-flush.service"
rm -f "${SYSTEMD_DIR}/alltrue-log-flush.timer"
rm -f "${SYSTEMD_DIR}/alltrue-log-monitor.service"
rm -f "${SYSTEMD_DIR}/alltrue-log-monitor.timer"
systemctl daemon-reload

# 4. Unmount tmpfs
echo "[4/6] Unmounting tmpfs..."
if mountpoint -q "$TMPFS_MOUNT" 2>/dev/null; then
    umount "$TMPFS_MOUNT"
    echo "  Unmounted ${TMPFS_MOUNT}"
else
    echo "  Not mounted, skipping"
fi

# 5. Remove fstab entry
echo "[5/6] Cleaning fstab..."
if grep -q "$TMPFS_MOUNT" /etc/fstab 2>/dev/null; then
    sed -i "\|${TMPFS_MOUNT}|d" /etc/fstab
    echo "  Removed fstab entry"
else
    echo "  No fstab entry found"
fi

# 6. Clean up scripts
echo "[6/6] Removing helper scripts..."
rm -f /usr/local/bin/alltrue-log-flush.sh
rm -f /usr/local/bin/alltrue-log-monitor.sh
rm -f /tmp/alltrue-log-flush.lock
rm -f /tmp/alltrue-log-flush-failures

echo ""
echo "=== Rollback complete ==="
echo "Logging is now direct-write to ${PERSIST_DIR}"
echo "Verify: curl -sk https://localhost/api/v1/health"
