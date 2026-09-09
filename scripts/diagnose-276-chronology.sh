#!/usr/bin/env bash
set -uo pipefail
echo "=== restore logs $(date -Iseconds) ==="
grep -n "substitute_restore_original.*26509\|26509.*substitute_restore" /home/admin/backend/storage/logs/laravel-2026-09-10.log /home/admin/backend/storage/logs/laravel-2026-09-09.log 2>/dev/null
echo "=== END ==="
