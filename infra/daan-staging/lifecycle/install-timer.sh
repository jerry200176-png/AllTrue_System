#!/usr/bin/env bash
# Install/refresh the user-level pull-deploy timer (no sudo; linger must be on).
set -euo pipefail
DIR="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "${HOME}/.config/systemd/user"
cp "${DIR}"/systemd/alltrue-staging-update.{service,timer} "${HOME}/.config/systemd/user/"
systemctl --user daemon-reload
systemctl --user enable --now alltrue-staging-update.timer
systemctl --user list-timers alltrue-staging-update.timer --no-pager
