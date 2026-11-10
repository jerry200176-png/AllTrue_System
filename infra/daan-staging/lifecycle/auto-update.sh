#!/usr/bin/env bash
# GitOps pull deploy (Argo CD / Flux style): no inbound access, no GitHub secrets.
# Deploys the newest `main` commit whose push CI succeeded (public API, unauthenticated:
# 1 request per run, 4 runs/h << 60/h limit). Rolls back to the last good SHA on failure.
# Env: STAGING_REPO (default jerry200176-png/AllTrue_System), STAGING_STATE_DIR.
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "${DIR}/_common.sh"
source "${DIR}/deploy-rule.sh"

STATE="${STAGING_STATE_DIR:-${HOME}/alltrue-stage/state}"
EVID="$(staging_evidence_dir)/auto-update.log"
REPO="${STAGING_REPO:-jerry200176-png/AllTrue_System}"
API="https://api.github.com/repos/${REPO}/actions/workflows/ci.yml/runs?branch=main&event=push&status=success&per_page=1"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" | tee -a "${EVID}"; }

run_cycle() { # $1=sha ; migrate/health are inside redeploy.sh
  bash "${DIR}/redeploy.sh" "$1" >>"${EVID}" 2>&1 \
    && bash "${DIR}/smoke.sh" >>"${EVID}" 2>&1 \
    && bash "${DIR}/assert-host-checkout-clean.sh" >>"${EVID}" 2>&1
}

main() {
  mkdir -p "${STATE}"
  exec 9>"${STATE}/lock"; flock -n 9 || { log "SKIP another run active"; return 0; }

  [[ -e "${STATE}/pause" ]] && { log "SKIP paused (rm ${STATE}/pause to resume)"; return 0; }
  local sha good
  sha="$(curl -fsS --max-time 20 "${API}" | python3 -c 'import json,sys; r=json.load(sys.stdin)["workflow_runs"]; print(r[0]["head_sha"] if r else "")')" \
    || { log "ERROR github api unreachable/rate-limited"; return 1; }
  [[ "${sha}" =~ ^[0-9a-f]{40}$ ]] || { log "ERROR bad sha '${sha}'"; return 1; }
  good="$(cat "${STATE}/last_good_sha" 2>/dev/null || true)"
  git -C "${REPO_ROOT}" fetch -q origin main >>"${EVID}" 2>&1 || { log "ERROR fetch origin main"; return 1; }
  case "$(rule_decide "${REPO_ROOT}" "${good}" "${sha}")" in
    NOOP_NOT_ON_MAIN) log "NOOP ${sha:0:12} not on origin/main"; return 0 ;;   # force-pushed away / branch-only
    NOOP_OLDER) log "NOOP ${sha:0:12} older than deployed ${good:0:12}"; return 0 ;;
  esac
  if [[ "${sha}" == "${good}" ]]; then
    # Containers use restart:"no"; after a host reboot the SHA matches but nothing runs. Heal by redeploying it.
    if bash "${DIR}/health.sh" >/dev/null 2>&1; then log "NOOP green main=${sha:0:12} already deployed"; return 0; fi
    log "UNHEALTHY at ${sha:0:12}; redeploying"
    if run_cycle "${sha}"; then log "OK ${sha:0:12}"; return 0; else log "FAIL ${sha:0:12}"; return 1; fi
  fi

  log "DEPLOY ${good:0:12} -> ${sha:0:12}"
  if run_cycle "${sha}"; then
    echo "${sha}" >"${STATE}/last_good_sha"; log "OK ${sha:0:12}"; return 0
  fi
  log "FAIL ${sha:0:12}"
  if [[ -n "${good}" ]]; then
    log "ROLLBACK to ${good:0:12}"
    if run_cycle "${good}"; then log "ROLLBACK_OK ${good:0:12}"; else log "ROLLBACK_FAILED ${good:0:12}"; fi
  fi
  return 1
}
main "$@"; exit $?
