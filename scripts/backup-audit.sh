#!/usr/bin/env bash
# backup-audit.sh — one-command read-only backup/offsite/restore audit
#
# Purpose:
# - Provide a single RED/YELLOW/GREEN verdict for backup health.
# - Never writes to production DB or backup files.
#
# Usage:
#   scripts/backup-audit.sh
#   BACKUP_ROOT=/home/admin/backups scripts/backup-audit.sh
#
set -euo pipefail

BACKUP_ROOT="${BACKUP_ROOT:-/home/admin/backups}"
MANIFEST_DIR="${MANIFEST_DIR:-$BACKUP_ROOT/manifests}"
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
RCLONE_BIN="${RCLONE_BIN:-$(command -v rclone || true)}"
REMOTE_PATH="${REMOTE_PATH:-g-drive:AllTrue-Backups}"
GH_REPO="${GH_REPO:-jerry200176-png/AllTrue_System}"
NOW_EPOCH="$(date +%s)"

RED=0
YELLOW=0

ok() { printf "GREEN  %s\n" "$*"; }
warn() { printf "YELLOW %s\n" "$*"; YELLOW=$((YELLOW + 1)); }
bad() { printf "RED    %s\n" "$*"; RED=$((RED + 1)); }

latest_file() {
  local pattern="$1"
  local latest
  latest=$(ls -1t $pattern 2>/dev/null | head -1 || true)
  printf "%s" "$latest"
}

age_hours() {
  local f="$1"
  local mtime
  mtime=$(stat -c %Y "$f")
  printf "%d" $(( (NOW_EPOCH - mtime) / 3600 ))
}

size_mb() {
  local f="$1"
  local size
  size=$(stat -c %s "$f")
  printf "%d" $(( size / 1024 / 1024 ))
}

check_local_file() {
  local label="$1"
  local pattern="$2"
  local stale_hours="$3"
  local min_mb="$4"
  local f age mb

  f="$(latest_file "$pattern")"
  if [ -z "$f" ]; then
    bad "$label missing ($pattern)"
    return
  fi

  age="$(age_hours "$f")"
  mb="$(size_mb "$f")"

  if [ "$age" -gt "$stale_hours" ]; then
    bad "$label stale: ${age}h old ($f)"
  elif [ "$age" -gt $(( stale_hours / 2 )) ]; then
    warn "$label getting old: ${age}h ($f)"
  else
    ok "$label fresh: ${age}h ($f)"
  fi

  if [ "$mb" -lt "$min_mb" ]; then
    warn "$label size small: ${mb}MB (< ${min_mb}MB) ($f)"
  else
    ok "$label size: ${mb}MB"
  fi
}

check_manifest() {
  local mf line_count
  mf="$(latest_file "$MANIFEST_DIR"/backup_manifest_*.txt)"
  if [ -z "$mf" ]; then
    bad "manifest missing in $MANIFEST_DIR"
    return
  fi

  line_count=$(awk 'NF >= 3 && $1 ~ /^[a-f0-9]{64}$/ {c++} END {print c+0}' "$mf")
  if [ "$line_count" -lt 3 ]; then
    bad "manifest looks incomplete: $mf (sha256 rows=$line_count)"
  else
    ok "manifest present: $mf (sha256 rows=$line_count)"
  fi
}

check_rclone_remote() {
  if [ -z "$RCLONE_BIN" ]; then
    warn "rclone not found; skip offsite checks"
    return
  fi

  local db_count monthly_count six_count mani_count
  db_count=$(timeout 20 "$RCLONE_BIN" ls "$REMOTE_PATH/db/" 2>/dev/null | wc -l || echo 0)
  monthly_count=$(timeout 20 "$RCLONE_BIN" ls "$REMOTE_PATH/monthly/" 2>/dev/null | wc -l || echo 0)
  six_count=$(timeout 20 "$RCLONE_BIN" ls "$REMOTE_PATH/sixhour/" 2>/dev/null | wc -l || echo 0)
  mani_count=$(timeout 20 "$RCLONE_BIN" ls "$REMOTE_PATH/manifests/" 2>/dev/null | wc -l || echo 0)

  [ "$db_count" -ge 1 ] && ok "offsite db count=$db_count" || bad "offsite db folder empty/unreachable"
  [ "$monthly_count" -ge 1 ] && ok "offsite monthly count=$monthly_count" || bad "offsite monthly folder empty/unreachable"
  [ "$six_count" -ge 1 ] && ok "offsite sixhour count=$six_count" || warn "offsite sixhour folder empty/unreachable"
  [ "$mani_count" -ge 1 ] && ok "offsite manifests count=$mani_count" || warn "offsite manifests folder empty/unreachable"
}

check_restore_drill() {
  if ! command -v gh >/dev/null 2>&1; then
    warn "gh not found; skip restore workflow check"
    return
  fi

  local row conclusion updated status
  row=$(gh run list --repo "$GH_REPO" --workflow "Backup Restore Verification" --limit 1 --json conclusion,updatedAt,status --jq '.[0] | [.conclusion,.updatedAt,.status] | @tsv' 2>/dev/null || true)
  if [ -z "$row" ]; then
    warn "cannot read latest restore drill workflow (auth/repo access?)"
    return
  fi
  conclusion=$(printf "%s" "$row" | awk '{print $1}')
  updated=$(printf "%s" "$row" | awk '{print $2}')
  status=$(printf "%s" "$row" | awk '{print $3}')

  if [ "$conclusion" = "success" ] || [ "$status" = "completed" ] && [ "$conclusion" = "success" ]; then
    ok "latest restore drill succeeded (updated=$updated)"
  elif [ "$status" = "in_progress" ] || [ "$status" = "queued" ]; then
    warn "restore drill currently $status (updated=$updated)"
  else
    bad "latest restore drill not successful (conclusion=$conclusion status=$status updated=$updated)"
  fi
}

check_rowcount_sanity_readonly() {
  if [ ! -f "$ENV_FILE" ]; then
    warn "env file not found ($ENV_FILE); skip DB row-count sanity"
    return
  fi
  if ! command -v mysql >/dev/null 2>&1; then
    warn "mysql client not found; skip DB row-count sanity"
    return
  fi

  local host port db user pass query out student_count class_count session_count
  host=$(grep -m1 '^DB_HOST=' "$ENV_FILE" | cut -d= -f2- || true)
  port=$(grep -m1 '^DB_PORT=' "$ENV_FILE" | cut -d= -f2- || true)
  db=$(grep -m1 '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2- || true)
  user=$(grep -m1 '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2- || true)
  pass=$(grep -m1 '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- || true)

  if [ -z "$host" ] || [ -z "$db" ] || [ -z "$user" ]; then
    warn "DB env incomplete; skip row-count sanity"
    return
  fi

  query="SELECT (SELECT COUNT(*) FROM Student),(SELECT COUNT(*) FROM StudentClass),(SELECT COUNT(*) FROM ClassSession);"
  out=$(mysql -h "$host" -P "${port:-3306}" -u "$user" -p"$pass" -N -s -e "$query" "$db" 2>/dev/null || true)
  if [ -z "$out" ]; then
    warn "cannot query DB row-count sanity (readonly check skipped)"
    return
  fi

  student_count=$(printf "%s" "$out" | awk '{print $1}')
  class_count=$(printf "%s" "$out" | awk '{print $2}')
  session_count=$(printf "%s" "$out" | awk '{print $3}')

  if [ "${student_count:-0}" -le 0 ] || [ "${class_count:-0}" -le 0 ] || [ "${session_count:-0}" -le 0 ]; then
    bad "row-count sanity suspicious: Student=$student_count StudentClass=$class_count ClassSession=$session_count"
  else
    ok "row-count sanity: Student=$student_count StudentClass=$class_count ClassSession=$session_count"
  fi
}

printf "=== AllTrue Backup Audit (read-only) ===\n"
printf "backup_root=%s\n" "$BACKUP_ROOT"
printf "remote=%s\n" "$REMOTE_PATH"
printf "\n"

check_local_file "nightly backup" "$BACKUP_ROOT/alltrue_nightly_*.sql.gz" 30 5
check_local_file "sixhour backup" "$BACKUP_ROOT/sixhour/alltrue_6h_*.sql.gz" 10 5
check_local_file "monthly backup" "$BACKUP_ROOT/monthly/alltrue_monthly_*.sql.gz" 800 5
check_manifest
check_rclone_remote
check_restore_drill
check_rowcount_sanity_readonly

printf "\n=== Verdict ===\n"
if [ "$RED" -gt 0 ]; then
  printf "RED (%d red, %d yellow)\n" "$RED" "$YELLOW"
  exit 2
fi
if [ "$YELLOW" -gt 0 ]; then
  printf "YELLOW (0 red, %d yellow)\n" "$YELLOW"
  exit 1
fi
printf "GREEN (0 red, 0 yellow)\n"
exit 0
