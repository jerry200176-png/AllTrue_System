#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
python3 scripts/check-pop-mount-remote-guards.py
python3 scripts/check-pop-mount-remote-guards.py --self-test
echo 'check-pop-mount-remote-guards test: OK'
