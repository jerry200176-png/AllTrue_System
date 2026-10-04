#!/usr/bin/env bash
# Step 3/3 — register + start the runner. Refuses unless 02-verify-isolation.sh passes right now.
# Run on daan-ai:  sudo bash 03-enable-runner.sh <REGISTRATION_TOKEN>
set -euo pipefail
TOKEN="${1:?usage: sudo bash 03-enable-runner.sh <REGISTRATION_TOKEN>}"
DIR=/opt/actions-runner
HERE="$(cd "$(dirname "$0")" && pwd)"

bash "$HERE/02-verify-isolation.sh" || { echo "isolation checks failed; runner NOT enabled"; exit 1; }

cd "$DIR"
# Registration must write .runner/.credentials into the runner dir. Close the window a ghrunner process
# could use to tamper with runner files: stop ALL ghrunner processes first, register, then re-lock and
# restore the verified binaries, keeping only the known registration files.
systemctl disable --now daan-ci-runner.service 2>/dev/null || true
pkill -KILL -u ghrunner 2>/dev/null || true
chown ghrunner "$DIR"
sudo -u ghrunner ./config.sh --unattended --replace --disableupdate \
  --url https://github.com/jerry200176-png/AllTrue_System --token "$TOKEN" \
  --name daan-ai --labels alltrue-daan --work _work
pkill -KILL -u ghrunner 2>/dev/null || true
chown root:root "$DIR"; chmod 755 "$DIR"
TGZ=$(ls /usr/local/lib/daan-runner/runner-*.tgz | head -1)
KEEP='^(\.runner|\.credentials|\.credentials_rsaparams|\.env|_work|_diag)$'
# Remove anything that is neither shipped in the verified tarball nor a known registration/state file.
SHIPPED=$(tar tzf "$TGZ" | sed 's#^\./##; s#/.*##' | sort -u)
for e in $(ls -A "$DIR"); do
  echo "$e" | grep -Eq "$KEEP" && continue
  echo "$SHIPPED" | grep -qx "$e" || { echo "removing unexpected $e"; rm -rf "${DIR:?}/$e"; }
done
tar xzf "$TGZ" -C "$DIR" --no-same-owner --overwrite     # restore binaries exactly as verified
for f in .runner .credentials .credentials_rsaparams; do
  [ -L "$DIR/$f" ] && { echo "refusing: $f is a symlink"; exit 1; }
  [ -f "$DIR/$f" ] && chown -h root:ghrunner "$DIR/$f" && chmod 640 "$DIR/$f"
done
chown -h root:root "$DIR/.env"; chmod 644 "$DIR/.env"
# Bring ghrunner's user manager (and its rootless Docker) back after the process kill above.
systemctl restart "user@$(id -u ghrunner).service"
for _ in $(seq 1 20); do sudo -iu ghrunner env XDG_RUNTIME_DIR="/run/user/$(id -u ghrunner)" docker info >/dev/null 2>&1 && break; sleep 2; done
bash "$HERE/02-verify-isolation.sh" || { echo "post-registration verification failed; runner NOT started"; exit 1; }

# Root-authored unit (do not run the ghrunner-writable svc.sh as root). The runner itself runs as ghrunner.
cat > /etc/systemd/system/daan-ci-runner.service <<EOF
[Unit]
Description=GitHub Actions runner (daan-ai, isolated ghrunner)
After=network-online.target daan-runner-guard.service
Requires=daan-runner-guard.service
[Service]
User=ghrunner
WorkingDirectory=$DIR
# Fail closed: no runner without the MySQL firewall guard.
ExecStartPre=+/usr/sbin/nft list table inet daan_runner_guard
ExecStart=$DIR/runsvc.sh
Restart=always
KillMode=process
NoNewPrivileges=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now daan-ci-runner.service
systemctl --no-pager status daan-ci-runner.service | head -5
echo "Runner daan-ai enabled (label alltrue-daan). Workflows still use GitHub-hosted until repo variable CI_RUNNER is set."
