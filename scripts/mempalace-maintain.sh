#!/usr/bin/env bash
# MemPalace health wrapper — status and repair only. Ingestion delegates to mempalace-ingest.sh.
#
# Usage:
#   bash scripts/mempalace-maintain.sh           # status → ingest → status
#   bash scripts/mempalace-maintain.sh --status  # status only
#   bash scripts/mempalace-maintain.sh --ingest  # ingest only (same as mempalace-ingest.sh)
#   bash scripts/mempalace-maintain.sh --repair  # rebuild vector index (confirm)

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/mempalace-config.sh
source "$SCRIPT_DIR/mempalace-config.sh"

MODE="${1:-all}"

run_status() {
  mempalace_require_cli
  echo "=== MemPalace status ==="
  echo "CLI: $($MEMPALACE --version 2>&1 | tail -1)"
  echo "Palace: $MEMPALACE_PALACE_DIR"
  if [ -d "$MEMPALACE_PALACE_DIR" ]; then
    du -sh "$MEMPALACE_PALACE_DIR" 2>/dev/null || true
  fi
  echo ""
  "$MEMPALACE" status
  mempalace_warn_orphan_wings
  if [ -d "$MEMPALACE_PALACE_DIR" ]; then
    CHROMA_SQL="$MEMPALACE_PALACE_DIR/chroma.sqlite3"
    [ -f "$CHROMA_SQL" ] && echo "chroma.sqlite3: $(du -h "$CHROMA_SQL" | cut -f1)"
    find "$MEMPALACE_PALACE_DIR" -name 'link_lists.bin' -size +100M 2>/dev/null | while read -r f; do
      echo "⚠️  Large link_lists.bin: $f — run --repair"
    done
  fi
}

run_repair() {
  mempalace_require_cli
  echo "⚠️  repair rebuilds the vector index from stored drawers."
  read -r -p "Continue? [y/N] " ans
  if [ "${ans:-}" = "y" ] || [ "${ans:-}" = "Y" ]; then
    "$MEMPALACE" repair --yes
  else
    echo "Aborted."
  fi
}

case "$MODE" in
  --status|-s) run_status ;;
  --ingest) bash "$SCRIPT_DIR/mempalace-ingest.sh" ;;
  --repair) run_repair ;;
  --help|-h)
    echo "Usage: bash scripts/mempalace-maintain.sh [--status|--ingest|--repair]"
    echo "Ingestion SSOT: scripts/mempalace-ingest.sh"
    ;;
  all|"") run_status; echo ""; bash "$SCRIPT_DIR/mempalace-ingest.sh"; echo ""; run_status ;;
  *) echo "Unknown: $MODE"; exit 1 ;;
esac

echo ""
echo "✅ Done. SoT = git markdown; MemPalace = recall index (ingest via mempalace-ingest.sh only)."
