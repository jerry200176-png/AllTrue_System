#!/usr/bin/env bash
# Runner-only transport for the separately approved, fixed read-only diagnostic.
set -euo pipefail
umask 077
readonly PROBE_NOT_AFTER_UTC=2026-10-21T00:00:00Z
readonly SCRIPT_SHA256=bb615a3a5643c153f08a6413e7004731250873800acbaee75abda97663b6505d
readonly SCRIPT=scripts/pop-mount-policy-readonly.sh
[[ "${MODE:-}" == probe-policy ]]
[[ "${PI_SSH_HOST:-}" == pi.lifenet.com.tw && "${PI_SSH_USER:-}" == admin ]]
[[ -n "${PI_SSH_KEY:-}" && ${#DUMP_ARTIFACT_KEY} -ge 16 ]]
[[ -n "${RUNNER_TEMP:-}" && -n "${HOME:-}" ]]
[[ "$(sha256sum "$SCRIPT" | cut -d ' ' -f 1)" == "$SCRIPT_SHA256" ]]
bash -n "$SCRIPT"
[[ $(( $(date -u -d "$PROBE_NOT_AFTER_UTC" +%s) - $(date -u +%s) )) -ge 120 ]]
DIAG_DIR=$(mktemp -d "$RUNNER_TEMP/pop-mount-policy.XXXXXX")
ARTIFACT_DIR="$RUNNER_TEMP/pop-mount-policy-artifact"
install -d -m 700 "$ARTIFACT_DIR"
cleanup() {
  rm -f "$DIAG_DIR/deploy_key" "$DIAG_DIR/policy.txt" "$DIAG_DIR/receipt.json" "$DIAG_DIR/evidence.tar.gz"
  rmdir "$DIAG_DIR"
}
trap cleanup EXIT
printf '%s' "$PI_SSH_KEY" | tr -d '[:space:]' | base64 -d > "$DIAG_DIR/deploy_key"
chmod 600 "$DIAG_DIR/deploy_key"
# Recheck immediately before the sole connection; there is no retry or fallback.
[[ $(( $(date -u -d "$PROBE_NOT_AFTER_UTC" +%s) - $(date -u +%s) )) -ge 120 ]]
started_at=$(date -u +%FT%TZ)
set +e
timeout --kill-after=5s 115s ssh -T -p 22 -i "$DIAG_DIR/deploy_key" \
  -o BatchMode=yes -o PreferredAuthentications=publickey -o IdentitiesOnly=yes \
  -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" \
  -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ClearAllForwardings=yes -o ForwardAgent=no \
  "$PI_SSH_USER@$PI_SSH_HOST" \
  '/usr/bin/timeout --kill-after=5s 100s /bin/bash --noprofile --norc -s' \
  < "$SCRIPT" > "$DIAG_DIR/policy.txt" 2>&1
ssh_rc=$?
set -e
# A successful SSH exit alone does not prove the fixed diagnostic actually ran.
if [ "$ssh_rc" -eq 0 ] && ! grep -q '^diagnostic_complete; no mount/data/permission mutation performed$' "$DIAG_DIR/policy.txt"; then
  ssh_rc=65
fi
ended_at=$(date -u +%FT%TZ)
python3 - "$DIAG_DIR/receipt.json" "$started_at" "$ended_at" "$ssh_rc" "$SCRIPT_SHA256" <<'PY_RECEIPT'
import json, os, pathlib, sys
path, started, ended, status, digest = sys.argv[1:]
pathlib.Path(path).write_text(json.dumps({
    "started_at_utc": started, "ended_at_utc": ended, "ssh_exit_status": int(status),
    "script_sha256": digest, "attempts": 1, "mode": "probe-policy",
    "github_run_id": os.environ.get("GITHUB_RUN_ID"),
    "github_run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT"),
    "workflow_sha": os.environ.get("GITHUB_SHA"),
    "credential_source": "production-activation environment PI_SSH_KEY",
}) + "\n")
PY_RECEIPT
tar -czf "$DIAG_DIR/evidence.tar.gz" -C "$DIAG_DIR" policy.txt receipt.json
openssl enc -aes-256-cbc -salt -pbkdf2 -iter 600000 \
  -pass env:DUMP_ARTIFACT_KEY -in "$DIAG_DIR/evidence.tar.gz" \
  -out "$ARTIFACT_DIR/pop-mount-policy.full.tar.gz.enc"
(cd "$ARTIFACT_DIR" && sha256sum pop-mount-policy.full.tar.gz.enc > pop-mount-policy.full.tar.gz.enc.sha256)
printf 'probe_policy_transport_finished ssh_exit=%s; details encrypted\n' "$ssh_rc"
exit "$ssh_rc"
