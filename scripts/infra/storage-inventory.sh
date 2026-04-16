#!/usr/bin/env bash
# FR-002: Node storage inventory script.
# Outputs root filesystem source, device model, and health status.
#
# Usage: bash scripts/infra/storage-inventory.sh
set -euo pipefail

echo "=== AllTrue Node Storage Inventory ==="
echo "Host: $(hostname)"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo ""

echo "## Block Devices"
lsblk -o NAME,TYPE,SIZE,FSTYPE,MOUNTPOINT,MODEL
echo ""

echo "## Root Filesystem"
findmnt -T / -o TARGET,SOURCE,FSTYPE,OPTIONS
echo ""

ROOT_DEV=$(findmnt -T / -o SOURCE -n | sed 's/\[.*\]//')
echo "## Root Device: ${ROOT_DEV}"

IS_SD=false
if echo "$ROOT_DEV" | grep -qE "mmcblk|sd[a-z]"; then
    IS_SD=true
fi

if echo "$ROOT_DEV" | grep -qE "nvme"; then
    echo "Type: NVMe SSD"
elif echo "$ROOT_DEV" | grep -qE "sd[a-z]"; then
    echo "Type: SATA/USB (could be SSD or HDD — verify model)"
elif echo "$ROOT_DEV" | grep -qE "mmcblk"; then
    echo "Type: SD Card / eMMC"
    echo "WARNING: SD card detected as root filesystem. Consider migrating to SSD/NVMe."
else
    echo "Type: Unknown (${ROOT_DEV})"
fi
echo ""

echo "## Disk Usage"
df -hT /
echo ""

echo "## NVMe Health"
if command -v smartctl &>/dev/null; then
    PARENT_DEV=$(echo "$ROOT_DEV" | sed 's/p[0-9]*$//')
    sudo smartctl -H "$PARENT_DEV" 2>/dev/null || echo "smartctl: no health data available"
else
    echo "smartctl not installed. Install: sudo apt install smartmontools"
fi
echo ""

echo "## Memory (for tmpfs capacity planning)"
free -h
echo ""

echo "## Assessment"
if $IS_SD; then
    echo "FAIL: Root filesystem is on SD card. Migration to SSD/NVMe recommended."
    exit 1
else
    echo "PASS: Root filesystem is NOT on SD card."
    exit 0
fi
