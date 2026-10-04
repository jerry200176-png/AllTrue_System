#!/usr/bin/env bash
# Step 1/3 — prepare an ISOLATED CI runner user on daan-ai. Does NOT register or start a runner.
# Run on daan-ai:  sudo bash 01-install-isolation.sh
#
# Isolation layers (no single one is trusted alone; 02-verify-isolation.sh checks each):
#   1. Dedicated user `ghrunner`: no sudo, NOT in the `docker` group (that group is root-equivalent).
#   2. Rootless Docker owned by ghrunner: containers run as ghrunner's sub-UIDs, cannot see the system
#      Docker daemon, and cannot read files ghrunner cannot read.
#   3. File permissions: home dirs stay 750 (the staging .env itself is root:root 644, so /home/admin 750 is
#      what keeps other users out; it is NOT changed here because PHP/staging may read it — step 2 proves
#      ghrunner cannot reach it).
#   4. Network: nftables drops ghrunner-owned TCP to local MySQL (3306/33060) on every address.
#      Rootless containers egress through ghrunner-owned processes, so the same rule covers them.
#   5. MySQL itself: verification proves ghrunner cannot log in over the socket or TCP.
set -euo pipefail

RUNNER_USER=ghrunner
RUNNER_VERSION="2.337.0"            # pin; bump deliberately
RUNNER_DIR=/opt/actions-runner

[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }

apt-get install -y --no-install-recommends uidmap passt dbus-user-session

id "$RUNNER_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$RUNNER_USER"
# Never in docker/sudo/admin groups.
for g in docker sudo admin; do gpasswd -d "$RUNNER_USER" "$g" >/dev/null 2>&1 || true; done
grep -q "^$RUNNER_USER:" /etc/subuid || usermod --add-subuids 300000-365535 --add-subgids 300000-365535 "$RUNNER_USER"

# Layer 3: tighten file permissions (record previous modes for rollback).
mkdir -p /var/lib/daan-runner
for p in /home/admin /home/jerry /home/jeng; do
  [ -e "$p" ] && stat -c '%a %n' "$p" >> /var/lib/daan-runner/previous-modes.txt
done
chmod 750 /home/admin /home/jerry /home/jeng

# AppArmor profile so rootlesskit may create user namespaces (Ubuntu restricts unprivileged userns).
cat > /etc/apparmor.d/usr.bin.rootlesskit <<'EOF'
abi <abi/4.0>,
include <tunables/global>
profile rootlesskit /usr/bin/rootlesskit flags=(unconfined) {
  userns,
  include if exists <local/usr.bin.rootlesskit>
}
EOF
apparmor_parser -r /etc/apparmor.d/usr.bin.rootlesskit

# Layer 4: block ghrunner (and therefore its rootless containers) from local MySQL.
RUNNER_UID=$(id -u "$RUNNER_USER")
cat > /etc/nftables.d-daan-runner.nft <<EOF
table inet daan_runner_guard {
  chain output {
    type filter hook output priority 0; policy accept;
    meta skuid $RUNNER_UID tcp dport { 3306, 33060 } counter reject with tcp reset
  }
}
EOF
nft -f /etc/nftables.d-daan-runner.nft
# Persist across reboot.
cat > /etc/systemd/system/daan-runner-guard.service <<'EOF'
[Unit]
Description=Block CI runner user from local MySQL
After=nftables.service
Wants=nftables.service
[Service]
Type=oneshot
ExecStart=/usr/sbin/nft -f /etc/nftables.d-daan-runner.nft
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable daan-runner-guard.service

# Layer 2: rootless Docker for ghrunner (systemd user service, survives logout).
loginctl enable-linger "$RUNNER_USER"
sudo -iu "$RUNNER_USER" XDG_RUNTIME_DIR="/run/user/$RUNNER_UID" dockerd-rootless-setuptool.sh install --skip-iptables
sudo -iu "$RUNNER_USER" XDG_RUNTIME_DIR="/run/user/$RUNNER_UID" systemctl --user enable --now docker

# Runner binaries only (NOT configured, NOT started — see 03-enable-runner.sh).
# Root never executes or writes through anything ghrunner can modify:
#   checksum-verified tarball -> extracted + installdependencies run while the dir is still root-owned,
#   hooks live in a root-owned dir, .env is written as ghrunner, then the runner dir is handed over.
RUNNER_SHA256=70920811a4f8ad4328818682bca5c6469c1c942fab52448868071d0063816613
HOOK_DIR=/usr/local/lib/daan-runner
install -d -o root -g root -m 755 "$HOOK_DIR"
# Keep the verified tarball root-owned: 03 re-extracts it after registration to undo any tampering.
TGZ="$HOOK_DIR/runner-${RUNNER_VERSION}.tgz"
if [ ! -f "$TGZ" ]; then
  curl -fsSL -o "$TGZ.part" "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
  mv "$TGZ.part" "$TGZ"
fi
echo "$RUNNER_SHA256  $TGZ" | sha256sum -c - || { rm -f "$TGZ"; echo "runner checksum mismatch"; exit 1; }
chmod 644 "$TGZ"
if [ ! -x "$RUNNER_DIR/config.sh" ]; then
  rm -rf "$RUNNER_DIR"; install -d -o root -g root -m 755 "$RUNNER_DIR"
  tar xzf "$TGZ" -C "$RUNNER_DIR" --no-same-owner
  "$RUNNER_DIR/bin/installdependencies.sh"
fi
# Never follow anything ghrunner could have planted (re-runs): runner dir and its entries must not be symlinks.
[ -L "$RUNNER_DIR" ] && { echo "refusing: $RUNNER_DIR is a symlink"; exit 1; }
chown root:root "$RUNNER_DIR"; chmod 755 "$RUNNER_DIR"
for d in _work _diag; do [ -L "$RUNNER_DIR/$d" ] && rm -f "$RUNNER_DIR/$d"; done
rm -f "$RUNNER_DIR/.env"
install -o root -g root -m 755 "$(dirname "$0")/job-started.sh" "$HOOK_DIR/job-started.sh"
install -o root -g root -m 755 "$(dirname "$0")/job-completed.sh" "$HOOK_DIR/job-completed.sh"
# Runner code stays ROOT-owned (a job cannot alter bin/, runsvc.sh, config.sh for later jobs).
# ghrunner may write only its state: _work, _diag and the files config.sh creates at registration.
for d in _work _diag; do
  [ -d "$RUNNER_DIR/$d" ] || mkdir -m 700 "$RUNNER_DIR/$d"
  chown -h "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR/$d"
done
printf 'DOCKER_HOST=unix:///run/user/%s/docker.sock\nACTIONS_RUNNER_HOOK_JOB_STARTED=%s/job-started.sh\nACTIONS_RUNNER_HOOK_JOB_COMPLETED=%s/job-completed.sh\n' \
  "$RUNNER_UID" "$HOOK_DIR" "$HOOK_DIR" > "$RUNNER_DIR/.env"   # root-owned dir; .env removed above, so no symlink to follow
chown root:root "$RUNNER_DIR/.env"; chmod 644 "$RUNNER_DIR/.env"

echo "Step 1 done. Next: sudo bash 02-verify-isolation.sh  (runner is NOT registered or running)"
