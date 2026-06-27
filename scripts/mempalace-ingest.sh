#!/usr/bin/env bash
# Canonical MemPalace ingestion entry point (single path).
#
# Delegates to stage-based engine: scripts/mempalace/engine/run.sh
# Config SSOT: scripts/mempalace-config.sh

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/mempalace/engine/run.sh" "$@"
