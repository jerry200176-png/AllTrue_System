#!/usr/bin/env bash
# Read a deploy's native Actions and production identity. No writes or SSH.
set -euo pipefail
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$script_dir/wait_github_deploy.py" "$@"
