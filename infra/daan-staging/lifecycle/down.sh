#!/usr/bin/env bash
# Stop staging app containers. Default preserves MySQL volume.
# Usage: down.sh [--volumes]   # --volumes destroys staging DB volume (explicit only)
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
require_env_file

mem_snapshot
if [[ "${1:-}" == "--volumes" ]]; then
  echo "WARNING: destroying staging volumes including MySQL data"
  compose down --volumes
else
  compose down
  echo "MySQL volume alltrue-stage-mysql-data preserved"
fi
mem_snapshot
echo "STAGING_DOWN"
