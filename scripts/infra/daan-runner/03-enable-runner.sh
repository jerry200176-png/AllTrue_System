#!/usr/bin/env bash
# Step 3/3 — register + start the runner. Refuses unless 02-verify-isolation.sh passes right now.
# Run on daan-ai:  sudo bash 03-enable-runner.sh <REGISTRATION_TOKEN>
set -euo pipefail
TOKEN="${1:?usage: sudo bash 03-enable-runner.sh <REGISTRATION_TOKEN>}"
DIR=/opt/actions-runner
HERE="$(cd "$(dirname "$0")" && pwd)"

bash "$HERE/02-verify-isolation.sh" || { echo "isolation checks failed; runner NOT enabled"; exit 1; }

cd "$DIR"
sudo -u ghrunner ./config.sh --unattended --replace \
  --url https://github.com/jerry200176-png/AllTrue_System --token "$TOKEN" \
  --name daan-ai --labels alltrue-daan --work _work
# Root-authored unit (do not run the ghrunner-writable svc.sh as root). The runner itself runs as ghrunner.
cat > /etc/systemd/system/daan-ci-runner.service <<EOF
[Unit]
Description=GitHub Actions runner (daan-ai, isolated ghrunner)
After=network-online.target daan-runner-guard.service
Requires=daan-runner-guard.service
[Service]
User=ghrunner
WorkingDirectory=$DIR
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
