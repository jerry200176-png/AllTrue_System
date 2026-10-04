#!/usr/bin/env bash
# Runner job-started hook (runs as ghrunner). Records what already exists in ghrunner's rootless Docker
# so job-completed.sh removes ONLY resources this job created.
set -u
# Fail closed: refuse to run a job if the MySQL block is not loaded (e.g. a ruleset flush after boot).
# Functional check (no root needed): if ghrunner can open a TCP connection to local MySQL, the guard is gone.
for addr in 127.0.0.1 ::1; do
  if timeout 3 bash -c "exec 3<>/dev/tcp/$addr/3306" 2>/dev/null; then
    echo "daan-runner: local MySQL reachable on $addr:3306 (firewall guard missing); refusing to run job" >&2
    exit 1
  fi
done
STATE="${RUNNER_TEMP:-/tmp}/daan-job-baseline"
mkdir -p "$STATE"
docker ps -aq --no-trunc > "$STATE/containers" 2>/dev/null || true
docker network ls -q --no-trunc > "$STATE/networks" 2>/dev/null || true
docker volume ls -q > "$STATE/volumes" 2>/dev/null || true
echo "${GITHUB_WORKSPACE:-}" > "$STATE/workspace"
