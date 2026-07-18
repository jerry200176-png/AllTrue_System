#!/usr/bin/env bash
# Technical Radar — runtime / CI pin drift (operational).
# Owner: Founder / CTO Agent
# Cadence: weekly (manual or CI)
# Policy: docs/radars/README.md
#
# Scope (intentionally narrow — avoid low-ROI noise):
#   1) PHP version in CI must match composer.json require.php major.minor
#   2) Warn if workflows pin more than one distinct Node major
# Out of scope this revision: mass Action SHA pinning, Dependabot CVE (use GH alerts).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT_DIR="$ROOT/docs/radars/runs"
mkdir -p "$OUT_DIR"
JSON="$OUT_DIR/technical-health-$STAMP.json"
SUM="$OUT_DIR/technical-health-latest.md"
LOG=$(mktemp)

severity_fail=0
severity_warn=0

run_check() {
  local name="$1"
  local cmd="$2"
  local sev="$3"
  echo "=== $name ===" | tee -a "$LOG"
  set +e
  bash -c "$cmd" >>"$LOG" 2>&1
  local rc=$?
  set -e
  if [[ $rc -ne 0 ]]; then
    echo "result: FAIL rc=$rc severity=$sev" | tee -a "$LOG"
    if [[ "$sev" == "fail" ]]; then
      severity_fail=$((severity_fail + 1))
    else
      severity_warn=$((severity_warn + 1))
    fi
    return 0
  fi
  echo "result: PASS" | tee -a "$LOG"
}

REV=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)

run_check "php_ci_matches_composer" "python3 scripts/radars/check-php-ci-pin.py" fail
run_check "node_ci_single_major" "python3 scripts/radars/check-node-ci-majors.py" warn

OVERALL="PASS"
[[ $severity_fail -eq 0 ]] || OVERALL="FAIL"

cat >"$JSON" <<EOF
{
  "radar": "technical_health",
  "revision": "$REV",
  "ran_at": "$STAMP",
  "overall": "$OVERALL",
  "fail_count": $severity_fail,
  "warn_count": $severity_warn,
  "owner": "Founder / CTO Agent",
  "cadence": "weekly",
  "remediation": "docs/radars/README.md + failing check script; do not mass-pin Actions in this radar"
}
EOF

{
  echo "# Technical Health — latest run"
  echo
  echo "- revision: \`$REV\`"
  echo "- ran_at: \`$STAMP\`"
  echo "- overall: **$OVERALL** (fail=$severity_fail warn=$severity_warn)"
  echo "- owner: Founder / CTO Agent"
  echo "- cadence: weekly"
  echo "- machine output: \`docs/radars/runs/technical-health-$STAMP.json\`"
  echo
  echo "## Log"
  echo
  echo '```'
  cat "$LOG"
  echo '```'
} >"$SUM"

rm -f "$LOG"
echo "Wrote $JSON"
echo "Wrote $SUM"
[[ "$OVERALL" == "PASS" ]]
