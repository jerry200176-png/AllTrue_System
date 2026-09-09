#!/usr/bin/env bash
set -uo pipefail
echo "=== notif writer clues $(date -Iseconds) ==="
# Prefer application log lines around ResolvedAt / substitute for session 26509
for f in /home/admin/backend/storage/logs/laravel.log /home/admin/backend/storage/logs/laravel-$(date -d '2026-09-09' +%Y-%m-%d).log /home/admin/backend/storage/logs/laravel-2026-09-09.log; do
  if [ -f "$f" ]; then
    echo "--- grep $f ---"
    grep -n "26509\|17617\|substitute_restore\|substitute_undo\|voidParent\|ResolvedAt" "$f" 2>/dev/null | grep -E "2026-09-09 16:0|26509|17617|substitute" | tail -80
  fi
done
ls -la /home/admin/backend/storage/logs/ | head -30
echo "=== END ==="
