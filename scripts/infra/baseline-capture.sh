#!/usr/bin/env bash
# FR-000: Baseline Capture Script
# Captures storage, log, and API latency metrics for KPI comparison.
# Usage: bash scripts/infra/baseline-capture.sh [output_dir]
set -euo pipefail

OUTPUT_DIR="${1:-/home/admin/docs/baselines}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT="${OUTPUT_DIR}/baseline_${TIMESTAMP}.md"
LOGS_DIR="/home/admin/backend/storage/logs"
API_BASE="https://localhost"

mkdir -p "$OUTPUT_DIR"

cat > "$REPORT" <<HEADER
# Baseline Snapshot — ${TIMESTAMP}

Captured: $(date '+%Y-%m-%d %H:%M:%S %Z')
Host: $(hostname)

## 1. Storage Device

\`\`\`
$(lsblk -o NAME,TYPE,SIZE,FSTYPE,MOUNTPOINT,MODEL 2>/dev/null)
\`\`\`

Root filesystem:
\`\`\`
$(findmnt -T / 2>/dev/null)
\`\`\`

## 2. Memory

\`\`\`
$(free -h)
\`\`\`

## 3. Disk Usage

\`\`\`
$(df -hT / /home/admin/backend/storage/logs 2>/dev/null || df -hT /)
\`\`\`

## 4. Current tmpfs Mounts

\`\`\`
$(mount | grep tmpfs || echo "none")
\`\`\`

## 5. Log File Inventory

\`\`\`
$(ls -lh "$LOGS_DIR"/ 2>/dev/null || echo "logs dir not found")
\`\`\`

## 6. Log Write Rates

HEADER

# laravel.log stats
if [ -f "$LOGS_DIR/laravel.log" ]; then
  SIZE=$(wc -c < "$LOGS_DIR/laravel.log")
  LINES=$(wc -l < "$LOGS_DIR/laravel.log")
  MTIME=$(stat -c "%Y" "$LOGS_DIR/laravel.log")
  BTIME=$(stat -c "%W" "$LOGS_DIR/laravel.log" 2>/dev/null || echo "$MTIME")
  if [ "$BTIME" = "0" ] || [ "$BTIME" = "-" ]; then BTIME=$MTIME; fi
  NOW=$(date +%s)
  AGE_DAYS=$(( (NOW - BTIME) / 86400 ))
  [ "$AGE_DAYS" -lt 1 ] && AGE_DAYS=1
  DAILY_BYTES=$(( SIZE / AGE_DAYS ))
  cat >> "$REPORT" <<EOF
| Metric | Value |
|---|---|
| laravel.log size | ${SIZE} bytes ($(numfmt --to=iec "$SIZE" 2>/dev/null || echo "${SIZE}B")) |
| laravel.log lines | ${LINES} |
| laravel.log age | ~${AGE_DAYS} days |
| laravel.log avg daily write | ${DAILY_BYTES} bytes/day (~$(numfmt --to=iec "$DAILY_BYTES" 2>/dev/null || echo "${DAILY_BYTES}B")/day) |
| laravel.log driver | single (no rotation) |

EOF
else
  echo "laravel.log not found" >> "$REPORT"
fi

# perf.log stats (latest full day)
YESTERDAY=$(date -d "yesterday" +%Y-%m-%d 2>/dev/null || date -v-1d +%Y-%m-%d 2>/dev/null || echo "")
if [ -n "$YESTERDAY" ] && [ -f "$LOGS_DIR/perf-${YESTERDAY}.log" ]; then
  PERF_SIZE=$(wc -c < "$LOGS_DIR/perf-${YESTERDAY}.log")
  cat >> "$REPORT" <<EOF
| perf.log ($YESTERDAY) size | ${PERF_SIZE} bytes (~$(numfmt --to=iec "$PERF_SIZE" 2>/dev/null || echo "${PERF_SIZE}B")) |
| perf.log driver | daily (14 days) |

EOF
fi

# Total daily log write estimate
if [ -n "${DAILY_BYTES:-}" ] && [ -n "${PERF_SIZE:-}" ]; then
  TOTAL_DAILY=$(( DAILY_BYTES + PERF_SIZE ))
  cat >> "$REPORT" <<EOF
| **Estimated total daily log write** | **${TOTAL_DAILY} bytes (~$(numfmt --to=iec "$TOTAL_DAILY" 2>/dev/null || echo "${TOTAL_DAILY}B")/day)** |

EOF
fi

cat >> "$REPORT" <<'SECTION7'

## 7. API Latency Samples (seconds)

| Endpoint | Sample 1 | Sample 2 | Sample 3 | Sample 4 | Sample 5 |
|---|---|---|---|---|---|
SECTION7

for ep in "/api/v1/health" "/api/v1/branches"; do
  ROW="| \`${ep}\` |"
  for i in 1 2 3 4 5; do
    T=$(curl -sk -o /dev/null -w "%{time_total}" "${API_BASE}${ep}" 2>/dev/null || echo "ERR")
    ROW="${ROW} ${T} |"
  done
  echo "$ROW" >> "$REPORT"
done

cat >> "$REPORT" <<'SECTION8'

## 8. NVMe Health (smartctl)

SECTION8

if command -v smartctl &>/dev/null; then
  echo '```' >> "$REPORT"
  sudo smartctl -a /dev/nvme0n1 2>&1 | head -40 >> "$REPORT" || echo "smartctl failed" >> "$REPORT"
  echo '```' >> "$REPORT"
else
  echo "smartctl not installed. Install with: \`sudo apt install smartmontools\`" >> "$REPORT"
fi

cat >> "$REPORT" <<'FOOTER'

## 9. Logging Config Snapshot

FOOTER

echo '```php' >> "$REPORT"
cat /home/admin/backend/config/logging.php >> "$REPORT"
echo '```' >> "$REPORT"

echo ""
echo "=== Baseline captured ==="
echo "Report: ${REPORT}"
