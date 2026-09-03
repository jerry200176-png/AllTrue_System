#!/usr/bin/env bash
# Read-only release/deploy/in-app trace for a GitHub issue.
#
# Keep this thin wrapper stable for existing runbooks; the implementation is
# in the dependency-free Python helper so its evidence rules can be tested.
set -euo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
exec python3 "$SCRIPT_DIR/trace-fix-deploy-status.py" "$@"
