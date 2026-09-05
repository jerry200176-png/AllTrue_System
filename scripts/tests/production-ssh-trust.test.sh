#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
action_dir="$repo_root/.github/actions/production-ssh-trust"
setup="$action_dir/setup.sh"

test -x "$setup" || { echo 'setup.sh must be executable' >&2; exit 1; }
! rg -n --glob '*.yml' --glob '*.yaml' --glob '!presubmit.yml' \
  'ssh-keyscan|StrictHostKeyChecking[[:space:]]+no|UserKnownHostsFile[[:space:]=]+/dev/null' \
  "$repo_root/.github/workflows" \
  || { echo 'production workflows contain unsafe SSH trust configuration' >&2; exit 1; }

python3 - "$repo_root/.github/workflows" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
transport = re.compile(r'(?m)^\s*(?:ssh|scp|rsync)(?:\s|\\|=)|[=(](?:ssh|scp)(?:\s|$)')
missing = []
late = []
for path in sorted(root.glob('*.y*ml')):
    if path.name in {'ci.yml', 'presubmit.yml'}:
        continue
    lines = path.read_text(encoding='utf-8').splitlines()
    starts = [i for i, line in enumerate(lines) if re.match(r'^  [A-Za-z0-9_-]+:\s*$', line)]
    for n, start in enumerate(starts):
        end = starts[n + 1] if n + 1 < len(starts) else len(lines)
        block = '\n'.join(lines[start:end])
        if not transport.search(block):
            continue
        action = block.find('uses: ./.github/actions/production-ssh-trust')
        first_transport = min((m.start() for m in transport.finditer(block)), default=-1)
        if action < 0:
            missing.append(f'{path}:{lines[start].strip()}')
        elif first_transport >= 0 and action > first_transport:
            late.append(f'{path}:{lines[start].strip()}')
        if not re.search(r'host-key:\s*\$\{\{\s*secrets\.PI_HOST_KEY\s*\}\}', block):
            missing.append(f'{path}:{lines[start].strip()} (PI_HOST_KEY)')

if missing or late:
    for item in missing + late:
        print(item, file=sys.stderr)
    raise SystemExit(1)
PY

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
key="$tmp/test-key"
ssh-keygen -q -t ed25519 -N '' -f "$key"
host='pi.example.test'
known_hosts_line="$host $(cat "$key.pub")"

HOME="$tmp/home-ok" INPUT_HOST="$host" INPUT_HOST_KEY="$known_hosts_line" \
  GITHUB_OUTPUT="$tmp/output" bash "$setup"
test -s "$tmp/home-ok/.ssh/known_hosts"
test "$(ssh-keygen -F "$host" -f "$tmp/home-ok/.ssh/known_hosts" | wc -l)" -gt 0
rg -q '^  StrictHostKeyChecking yes$' "$tmp/home-ok/.ssh/config"
rg -q '^  GlobalKnownHostsFile /dev/null$' "$tmp/home-ok/.ssh/config"

if HOME="$tmp/home-mismatch" INPUT_HOST='other.example.test' INPUT_HOST_KEY="$known_hosts_line" \
  bash "$setup" >/dev/null 2>&1; then
  echo 'host mismatch was accepted' >&2
  exit 1
fi

if HOME="$tmp/home-multiline" INPUT_HOST="$host" INPUT_HOST_KEY="$known_hosts_line
other.example.test ssh-ed25519 invalid" \
  bash "$setup" >/dev/null 2>&1; then
  echo 'multi-line host key was accepted' >&2
  exit 1
fi

echo 'production-ssh-trust.test.sh: ok'
