#!/usr/bin/env bash
# Full rollback of the daan-ai CI runner. Run on daan-ai:  sudo bash 99-rollback.sh [REMOVAL_TOKEN]
# Order: stop jobs → unregister → remove rootless docker + user → remove firewall/apparmor → restore file modes.
# Repo side: unset repo variable CI_RUNNER first so workflows go back to GitHub-hosted runners.
set -uo pipefail
DIR=/opt/actions-runner
U=ghrunner

systemctl disable --now daan-ci-runner.service 2>/dev/null || true
rm -f /etc/systemd/system/daan-ci-runner.service
if [ -n "${1:-}" ] && [ -x "$DIR/config.sh" ]; then sudo -u "$U" "$DIR/config.sh" remove --token "$1" || true; fi

if id "$U" >/dev/null 2>&1; then
  UID_=$(id -u "$U")
  sudo -iu "$U" env XDG_RUNTIME_DIR="/run/user/$UID_" systemctl --user disable --now docker 2>/dev/null || true
  sudo -iu "$U" env XDG_RUNTIME_DIR="/run/user/$UID_" dockerd-rootless-setuptool.sh uninstall 2>/dev/null || true
  loginctl disable-linger "$U" || true
  pkill -u "$U" || true
  userdel -r "$U" 2>/dev/null || true
  sed -i "/^$U:/d" /etc/subuid /etc/subgid
fi
rm -rf "$DIR" /usr/local/lib/daan-runner

systemctl disable --now daan-runner-guard.service 2>/dev/null || true
rm -f /etc/systemd/system/daan-runner-guard.service /etc/nftables.d-daan-runner.nft
nft delete table inet daan_runner_guard 2>/dev/null || true
apparmor_parser -R /etc/apparmor.d/usr.bin.rootlesskit 2>/dev/null || true
rm -f /etc/apparmor.d/usr.bin.rootlesskit
systemctl daemon-reload

# Restore previous file modes recorded by step 1 (home dirs and staging .env).
if [ -f /var/lib/daan-runner/previous-modes.txt ]; then
  while read -r mode path; do [ -e "$path" ] && chmod "$mode" "$path"; done < /var/lib/daan-runner/previous-modes.txt
fi
rm -rf /var/lib/daan-runner
echo "Rollback done. Packages uidmap/passt/dbus-user-session were left installed (harmless); remove with apt if wanted."
