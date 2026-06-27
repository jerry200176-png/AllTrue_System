#!/usr/bin/env bash
# Run a single MemPalace ingest stage (for testing / recovery).
#
# Usage:
#   bash scripts/mempalace/run-stage.sh preflight
#   bash scripts/mempalace/run-stage.sh discovery --no-lock
#   bash scripts/mempalace/run-stage.sh storage --resume

set -euo pipefail
STAGE="${1:?stage name required}"
shift || true
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/mempalace-ingest.sh" --stage "$STAGE" "$@"
