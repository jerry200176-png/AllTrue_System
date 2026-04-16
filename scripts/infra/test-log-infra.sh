#!/usr/bin/env bash
# FR-000~FR-008 Infrastructure Test Suite
# Validates baseline, log rotation, tmpfs, flush, monitoring, rollback, and health.
#
# Usage: bash scripts/infra/test-log-infra.sh
# Run as root for tmpfs tests, or with sudo.
set -uo pipefail

PASS=0
FAIL=0
SKIP=0

ok()   { PASS=$((PASS+1)); echo "  [PASS] $1"; }
fail() { FAIL=$((FAIL+1)); echo "  [FAIL] $1"; }
skip() { SKIP=$((SKIP+1)); echo "  [SKIP] $1"; }

echo "=== AllTrue Log Infrastructure Test Suite ==="
echo "Date: $(date)"
echo ""

# ─── FR-000: Baseline Snapshot ────────────────────────────────────────────────
echo "## FR-000: Baseline Snapshot"

if ls /home/admin/docs/baselines/baseline_*.md &>/dev/null; then
    ok "Baseline snapshot file exists"
else
    fail "No baseline snapshot found in docs/baselines/"
fi

if [ -x /home/admin/scripts/infra/baseline-capture.sh ]; then
    ok "Baseline capture script is executable"
else
    fail "Baseline capture script missing or not executable"
fi

echo ""

# ─── FR-001: Log Rotation ────────────────────────────────────────────────────
echo "## FR-001: Log Rotation (single → daily)"

LOGGING_CFG="/home/admin/backend/config/logging.php"

if grep -q "'channels' => \['daily'\]" "$LOGGING_CFG" 2>/dev/null; then
    ok "Stack channel routes to 'daily' driver"
else
    fail "Stack channel not routing to 'daily' — check logging.php"
fi

if grep -q "'driver'.*=>.*'daily'" "$LOGGING_CFG" 2>/dev/null && grep -q "'days'.*=>.*14" "$LOGGING_CFG" 2>/dev/null; then
    ok "Daily rotation configured with 14-day retention"
else
    fail "Daily rotation or 14-day retention missing in logging.php"
fi

# Check no code references 'single' channel
SINGLE_REFS=$(grep -r "channel('single')" /home/admin/backend/app/ 2>/dev/null | wc -l)
if [ "$SINGLE_REFS" -eq 0 ]; then
    ok "No code references to deprecated 'single' channel"
else
    fail "${SINGLE_REFS} file(s) still reference channel('single')"
fi

echo ""

# ─── FR-002: Storage Inventory Script ────────────────────────────────────────
echo "## FR-002: Storage Inventory"

if [ -x /home/admin/scripts/infra/storage-inventory.sh ]; then
    ok "Storage inventory script exists and is executable"
else
    fail "Storage inventory script missing"
fi

ROOT_SRC=$(findmnt -T / -o SOURCE -n 2>/dev/null | sed 's/\[.*\]//')
if echo "$ROOT_SRC" | grep -qE "nvme|sd[a-z]" && ! echo "$ROOT_SRC" | grep -q "mmcblk"; then
    ok "Root filesystem is on SSD/NVMe (${ROOT_SRC}), not SD card"
else
    fail "Root filesystem on unexpected device: ${ROOT_SRC}"
fi

echo ""

# ─── FR-004/005: Tmpfs Setup Scripts ─────────────────────────────────────────
echo "## FR-004/005: Tmpfs Setup & Flush"

if [ -x /home/admin/scripts/infra/setup-log-tmpfs.sh ]; then
    ok "Tmpfs setup script exists and is executable"
else
    fail "Tmpfs setup script missing"
fi

if [ -f /usr/local/bin/alltrue-log-flush.sh ] && [ -x /usr/local/bin/alltrue-log-flush.sh ]; then
    ok "Flush script installed at /usr/local/bin/alltrue-log-flush.sh"
else
    skip "Flush script not yet installed (run setup-log-tmpfs.sh first)"
fi

if [ -f /usr/local/bin/alltrue-log-monitor.sh ] && [ -x /usr/local/bin/alltrue-log-monitor.sh ]; then
    ok "Monitor script installed at /usr/local/bin/alltrue-log-monitor.sh"
else
    skip "Monitor script not yet installed (run setup-log-tmpfs.sh first)"
fi

TMPFS_MOUNT="/var/log/alltrue-tmpfs"
if mountpoint -q "$TMPFS_MOUNT" 2>/dev/null; then
    ok "Tmpfs is mounted at ${TMPFS_MOUNT}"
    USAGE=$(df --output=pcent "$TMPFS_MOUNT" 2>/dev/null | tail -1 | tr -d ' %')
    if [ "$USAGE" -lt 80 ]; then
        ok "Tmpfs usage at ${USAGE}% (< 80% threshold)"
    else
        fail "Tmpfs usage at ${USAGE}% (>= 80% threshold)"
    fi
else
    skip "Tmpfs not yet mounted (run setup-log-tmpfs.sh first)"
fi

if systemctl is-active alltrue-log-flush.timer &>/dev/null; then
    ok "Flush timer is active"
else
    skip "Flush timer not yet active (run setup-log-tmpfs.sh first)"
fi

if systemctl is-active alltrue-log-monitor.timer &>/dev/null; then
    ok "Monitor timer is active"
else
    skip "Monitor timer not yet active (run setup-log-tmpfs.sh first)"
fi

echo ""

# ─── FR-006: Health Endpoint ─────────────────────────────────────────────────
echo "## FR-006: Health Endpoint Log Pipeline"

HEALTH=$(curl -sk https://localhost/api/v1/health 2>/dev/null)
if echo "$HEALTH" | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['status']=='ok'" 2>/dev/null; then
    ok "Health endpoint returns status=ok"
else
    fail "Health endpoint unhealthy or unreachable"
fi

if echo "$HEALTH" | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'log_pipeline' in d" 2>/dev/null; then
    ok "Health endpoint includes log_pipeline section"
else
    fail "Health endpoint missing log_pipeline section"
fi

if echo "$HEALTH" | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['log_pipeline']['logs_dir_writable']==True" 2>/dev/null; then
    ok "logs directory is writable"
else
    fail "logs directory not writable"
fi

echo ""

# ─── FR-007: Rollback Script ────────────────────────────────────────────────
echo "## FR-007: Rollback"

if [ -x /home/admin/scripts/infra/rollback-log-tmpfs.sh ]; then
    ok "Rollback script exists and is executable"
else
    fail "Rollback script missing"
fi

echo ""

# ─── FR-008: Documentation ──────────────────────────────────────────────────
echo "## FR-008: Documentation"

for doc in "docs/OPERATIONS_RUNBOOK.md" "docs/deploy-raspberry-pi.md" "docs/CHANGELOG.md"; do
    if [ -f "/home/admin/${doc}" ]; then
        ok "${doc} exists"
    else
        fail "${doc} missing"
    fi
done

echo ""

# ─── Regression: API health ─────────────────────────────────────────────────
echo "## Regression: Core API"

for ep in "/api/v1/health" "/api/v1/branches"; do
    CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://localhost${ep}" 2>/dev/null)
    if [ "$CODE" = "200" ]; then
        ok "${ep} returns 200"
    else
        fail "${ep} returns ${CODE}"
    fi
done

echo ""

# ─── Summary ────────────────────────────────────────────────────────────────
echo "========================================"
echo "  PASS: ${PASS}  |  FAIL: ${FAIL}  |  SKIP: ${SKIP}"
echo "========================================"

if [ "$FAIL" -gt 0 ]; then
    echo "Some tests FAILED. Review above output."
    exit 1
else
    echo "All executed tests passed."
    exit 0
fi
