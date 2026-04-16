#!/usr/bin/env bash
# FR-004/005: Set up tmpfs for high-frequency operational logs
# Mounts a 128 MB tmpfs at /var/log/alltrue-tmpfs and configures
# a systemd timer to flush logs to persistent storage every 5 minutes.
#
# Usage: sudo bash scripts/infra/setup-log-tmpfs.sh
# Rollback: sudo bash scripts/infra/rollback-log-tmpfs.sh
set -euo pipefail

TMPFS_MOUNT="/var/log/alltrue-tmpfs"
TMPFS_SIZE="128M"
PERSIST_DIR="/home/admin/backend/storage/logs"
FLUSH_INTERVAL="5min"
ALERT_THRESHOLD_PCT=80
SYSTEMD_DIR="/etc/systemd/system"
FLUSH_SCRIPT="/usr/local/bin/alltrue-log-flush.sh"
MONITOR_SCRIPT="/usr/local/bin/alltrue-log-monitor.sh"

echo "=== AllTrue Log Tmpfs Setup ==="
echo "Tmpfs mount: ${TMPFS_MOUNT} (${TMPFS_SIZE})"
echo "Persistent dir: ${PERSIST_DIR}"
echo "Flush interval: ${FLUSH_INTERVAL}"
echo ""

# 1. Create tmpfs mount point
mkdir -p "$TMPFS_MOUNT"

# 2. Add to fstab if not already present
if ! grep -q "$TMPFS_MOUNT" /etc/fstab; then
    echo "tmpfs ${TMPFS_MOUNT} tmpfs defaults,noatime,nosuid,nodev,noexec,size=${TMPFS_SIZE},mode=0770,uid=www-data,gid=adm 0 0" >> /etc/fstab
    echo "[OK] Added tmpfs entry to /etc/fstab"
else
    echo "[SKIP] tmpfs entry already in /etc/fstab"
fi

# 3. Mount if not already mounted
if ! mountpoint -q "$TMPFS_MOUNT"; then
    mount "$TMPFS_MOUNT"
    echo "[OK] Mounted ${TMPFS_MOUNT}"
else
    echo "[SKIP] ${TMPFS_MOUNT} already mounted"
fi

# 4. Create flush script
cat > "$FLUSH_SCRIPT" <<'FLUSHEOF'
#!/usr/bin/env bash
# Flush high-frequency logs from tmpfs to persistent storage.
# Called by systemd timer every 5 minutes.
set -euo pipefail

TMPFS_DIR="/var/log/alltrue-tmpfs"
PERSIST_DIR="/home/admin/backend/storage/logs"
LOCK_FILE="/tmp/alltrue-log-flush.lock"
MAX_RETRIES=3
RETRY_FILE="/tmp/alltrue-log-flush-failures"

# Prevent concurrent flushes
exec 200>"$LOCK_FILE"
flock -n 200 || { echo "flush already running"; exit 0; }

# Check if tmpfs is mounted
if ! mountpoint -q "$TMPFS_DIR"; then
    echo "WARNING: ${TMPFS_DIR} is not mounted, skipping flush"
    exit 0
fi

# Check if there are files to flush
shopt -s nullglob
FILES=("$TMPFS_DIR"/*.log)
if [ ${#FILES[@]} -eq 0 ]; then
    exit 0
fi

# Flush: append tmpfs logs to persistent storage, then truncate
FLUSH_OK=true
for f in "${FILES[@]}"; do
    BASENAME=$(basename "$f")
    TARGET="${PERSIST_DIR}/${BASENAME}"
    if cat "$f" >> "$TARGET" 2>/dev/null; then
        : > "$f"
    else
        FLUSH_OK=false
        echo "ERROR: failed to flush ${f} -> ${TARGET}"
    fi
done

if $FLUSH_OK; then
    # Reset failure counter on success
    rm -f "$RETRY_FILE"
else
    # Increment failure counter
    COUNT=0
    [ -f "$RETRY_FILE" ] && COUNT=$(cat "$RETRY_FILE")
    COUNT=$((COUNT + 1))
    echo "$COUNT" > "$RETRY_FILE"

    if [ "$COUNT" -ge "$MAX_RETRIES" ]; then
        echo "CRITICAL: flush failed ${COUNT} consecutive times, triggering safe mode"
        # Safe mode: stop writing to tmpfs (remove symlinks if any)
        systemctl stop alltrue-log-flush.timer 2>/dev/null || true
        logger -t alltrue-log "CRITICAL: log flush failed ${COUNT} times, timer stopped, manual intervention required"
    fi
fi
FLUSHEOF
chmod +x "$FLUSH_SCRIPT"
echo "[OK] Created flush script: ${FLUSH_SCRIPT}"

# 5. Create monitor/alert script (FR-006)
cat > "$MONITOR_SCRIPT" <<'MONEOF'
#!/usr/bin/env bash
# Monitor tmpfs usage and auto-degrade if threshold exceeded.
# Called by systemd timer every 1 minute.
set -euo pipefail

TMPFS_DIR="/var/log/alltrue-tmpfs"
THRESHOLD_PCT=80
PERSIST_DIR="/home/admin/backend/storage/logs"

if ! mountpoint -q "$TMPFS_DIR"; then
    exit 0
fi

USAGE_PCT=$(df --output=pcent "$TMPFS_DIR" | tail -1 | tr -d ' %')

if [ "$USAGE_PCT" -ge "$THRESHOLD_PCT" ]; then
    logger -t alltrue-log "WARNING: tmpfs usage at ${USAGE_PCT}% (threshold: ${THRESHOLD_PCT}%), forcing emergency flush"

    # Emergency flush
    /usr/local/bin/alltrue-log-flush.sh

    # Recheck after flush
    USAGE_AFTER=$(df --output=pcent "$TMPFS_DIR" | tail -1 | tr -d ' %')
    if [ "$USAGE_AFTER" -ge "$THRESHOLD_PCT" ]; then
        logger -t alltrue-log "CRITICAL: tmpfs still at ${USAGE_AFTER}% after emergency flush, degrading to direct write"
        # Truncate oldest files in tmpfs to free space
        ls -t "$TMPFS_DIR"/*.log 2>/dev/null | tail -n +2 | while read -r f; do
            cat "$f" >> "${PERSIST_DIR}/$(basename "$f")" 2>/dev/null
            : > "$f"
        done
    fi
fi
MONEOF
chmod +x "$MONITOR_SCRIPT"
echo "[OK] Created monitor script: ${MONITOR_SCRIPT}"

# 6. Create systemd flush timer
cat > "${SYSTEMD_DIR}/alltrue-log-flush.service" <<EOF
[Unit]
Description=AllTrue log flush from tmpfs to persistent storage
After=local-fs.target

[Service]
Type=oneshot
ExecStart=${FLUSH_SCRIPT}
User=root
Nice=19
IOSchedulingClass=idle
EOF

cat > "${SYSTEMD_DIR}/alltrue-log-flush.timer" <<EOF
[Unit]
Description=Flush AllTrue logs from tmpfs every ${FLUSH_INTERVAL}

[Timer]
OnBootSec=2min
OnUnitActiveSec=${FLUSH_INTERVAL}
AccuracySec=30s

[Install]
WantedBy=timers.target
EOF

# 7. Create systemd monitor timer
cat > "${SYSTEMD_DIR}/alltrue-log-monitor.service" <<EOF
[Unit]
Description=AllTrue tmpfs usage monitor and auto-degrade
After=local-fs.target

[Service]
Type=oneshot
ExecStart=${MONITOR_SCRIPT}
User=root
EOF

cat > "${SYSTEMD_DIR}/alltrue-log-monitor.timer" <<EOF
[Unit]
Description=Monitor AllTrue tmpfs usage every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s

[Install]
WantedBy=timers.target
EOF

# 8. Enable and start timers
systemctl daemon-reload
systemctl enable --now alltrue-log-flush.timer
systemctl enable --now alltrue-log-monitor.timer

echo "[OK] Systemd timers enabled and started"
echo ""

# 9. Verify
echo "=== Verification ==="
echo "Tmpfs mount:"
df -h "$TMPFS_MOUNT"
echo ""
echo "Timer status:"
systemctl list-timers --no-pager | grep alltrue || echo "(timers may take a moment to appear)"
echo ""
echo "=== Setup complete ==="
echo "Flush script: ${FLUSH_SCRIPT}"
echo "Monitor script: ${MONITOR_SCRIPT}"
echo "Rollback: sudo bash /home/admin/scripts/infra/rollback-log-tmpfs.sh"
