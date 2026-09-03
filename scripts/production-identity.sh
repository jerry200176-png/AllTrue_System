#!/usr/bin/env bash
# Machine-readable AllTrue production identity (read-only).
# Does NOT treat HTTP 200 alone as success — surfaces scheduler/deploy drift.
set -euo pipefail

REPO="${ALLTRUE_REPO:-jerry200176-png/AllTrue_System}"
PROD_URL="${ALLTRUE_PROD_URL:-https://daan.lifenet.com.tw}"
OUT_FORMAT="${1:-json}"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "missing $1" >&2; exit 2; }; }
need_cmd curl
need_cmd python3
need_cmd gh
need_cmd git

REMOTE_MAIN="$(git ls-remote "https://github.com/${REPO}.git" refs/heads/main | awk '{print $1}')"
HEALTH_RAW="$(curl -sk --max-time 20 "${PROD_URL}/api/v1/health" || true)"
VERSION_RAW="$(curl -sk --max-time 20 "${PROD_URL}/version.json" || true)"
DEPLOYMENT_RAW="$(curl -sk --max-time 20 "${PROD_URL}/deployment.json" || true)"
HEALTH_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "${PROD_URL}/api/v1/health" || echo 000)"

PI_HEALTH_JSON="$(gh run list -R "$REPO" --workflow=pi-health.yml --limit 1 --json databaseId,conclusion,status,createdAt,url,displayTitle 2>/dev/null || echo '[]')"
DEPLOYMENT_BACKEND_SHA="$(python3 -c 'import json,sys; print((json.loads(sys.stdin.read() or "{}") or {}).get("backend_sha") or "")' <<< "$DEPLOYMENT_RAW" 2>/dev/null || true)"
DEPLOY_JSON="$(gh run list -R "$REPO" --workflow=deploy.yml --limit 20 --json databaseId,conclusion,status,headSha,createdAt,url,displayTitle 2>/dev/null || echo '[]')"
MATCHING_DEPLOY_JSON='[]'
if [[ -n "$DEPLOYMENT_BACKEND_SHA" ]]; then
    MATCHING_DEPLOY_JSON="$(gh run list -R "$REPO" --workflow=deploy.yml --commit "$DEPLOYMENT_BACKEND_SHA" --limit 20 --json databaseId,conclusion,status,headSha,createdAt,url,displayTitle 2>/dev/null || echo '[]')"
fi
COMPARE_JSON='{}'
if [[ -n "$DEPLOYMENT_BACKEND_SHA" && -n "$REMOTE_MAIN" ]]; then
    COMPARE_JSON="$(gh api "/repos/${REPO}/compare/${DEPLOYMENT_BACKEND_SHA}...${REMOTE_MAIN}" 2>/dev/null || echo '{}')"
fi

IDENTITY_TMP_DIR="$(mktemp -d)"
trap 'rm -rf -- "$IDENTITY_TMP_DIR"' EXIT
umask 077
printf '%s' "$HEALTH_RAW" > "$IDENTITY_TMP_DIR/health.json"
printf '%s' "$VERSION_RAW" > "$IDENTITY_TMP_DIR/version.json"
printf '%s' "$DEPLOYMENT_RAW" > "$IDENTITY_TMP_DIR/deployment.json"
printf '%s' "$DEPLOY_JSON" > "$IDENTITY_TMP_DIR/deploy-runs.json"
printf '%s' "$MATCHING_DEPLOY_JSON" > "$IDENTITY_TMP_DIR/matching-deploy-runs.json"
printf '%s' "$PI_HEALTH_JSON" > "$IDENTITY_TMP_DIR/pi-health.json"
printf '%s' "$COMPARE_JSON" > "$IDENTITY_TMP_DIR/compare.json"

# Keep large API responses out of argv. Apart from avoiding the host's
# ARG_MAX limit, this keeps evidence transport independent of response size.
python3 - "$REMOTE_MAIN" "$IDENTITY_TMP_DIR/health.json" "$IDENTITY_TMP_DIR/version.json" "$IDENTITY_TMP_DIR/deployment.json" "$HEALTH_CODE" "$IDENTITY_TMP_DIR/deploy-runs.json" "$IDENTITY_TMP_DIR/matching-deploy-runs.json" "$IDENTITY_TMP_DIR/pi-health.json" "$IDENTITY_TMP_DIR/compare.json" "$PROD_URL" "$REPO" <<'PY'
import json,sys,datetime
from pathlib import Path

remote_main, health_path, version_path, deployment_path, health_code, deploy_path, matching_deploy_path, pi_path, compare_path, prod_url, repo = sys.argv[1:12]

def read_raw(path):
    return Path(path).read_text(encoding="utf-8")

health_raw = read_raw(health_path)
version_raw = read_raw(version_path)
deployment_raw = read_raw(deployment_path)
deploy_json = read_raw(deploy_path)
matching_deploy_json = read_raw(matching_deploy_path)
pi_json = read_raw(pi_path)
compare_json = read_raw(compare_path)

def parse(raw, default=None):
    try:
        return json.loads(raw) if raw else default
    except Exception:
        return default

health = parse(health_raw, {})
version = parse(version_raw, {})
deployment = parse(deployment_raw, {})
deploy_runs = parse(deploy_json, []) or []
matching_deploy_runs = parse(matching_deploy_json, []) or []
pi = (parse(pi_json, []) or [None])[0]
compare = parse(compare_json, {}) or {}

frontend_hash = (version or {}).get('hash')
frontend_time = (version or {}).get('t')
frontend_build_sha = (version or {}).get('build_sha') or (deployment or {}).get('frontend_build_sha')
deployment_backend_sha = (deployment or {}).get('backend_sha')
deployment_deployed_at = (deployment or {}).get('deployed_at')
health_status = (health or {}).get('status')
health_ok = health_code == '200' and health_status == 'ok'

latest_deploy_workflow_run = deploy_runs[0] if deploy_runs else None
successful_deploy_runs = [
    run for run in deploy_runs
    if run.get('status') == 'completed' and run.get('conclusion') == 'success'
]
# workflow_run fires for every main CI completion, including deployable=false
# runs where the real production job is skipped. The public deployment manifest
# is the authoritative runtime identity, so select the successful deploy run
# whose head actually matches that manifest instead of blindly taking the
# newest workflow trigger.
deploy = next(
    (run for run in matching_deploy_runs
     if run.get('status') == 'completed' and run.get('conclusion') == 'success'
     and run.get('headSha') == deployment_backend_sha),
    None,
)
deploy_sha = (deploy or {}).get('headSha')
deploy_conclusion = (deploy or {}).get('conclusion')
pi_conclusion = (pi or {}).get('conclusion')
pi_created = (pi or {}).get('createdAt')

red = []
drift_notes = []
compare_files = compare.get('files') if isinstance(compare, dict) else None
compare_files = compare_files if isinstance(compare_files, list) else None
runtime_prefixes = ('backend/', 'frontend/', 'scripts/')
runtime_exact = {'composer.json', 'composer.lock', '.github/workflows/deploy.yml'}
pending_runtime_paths = [
    item.get('filename', '') for item in (compare_files or [])
    if item.get('filename', '').startswith(runtime_prefixes)
    or item.get('filename', '') in runtime_exact
]
if not health_ok:
    red.append('health_not_ok')
if not remote_main:
    red.append('remote_main_unknown')
if not deploy_sha:
    red.append('deployed_backend_sha_unknown')
if not deployment_backend_sha:
    red.append('deployment_manifest_unknown')
if deployment_backend_sha and not deploy:
    red.append('no_successful_deploy_matches_manifest')
if deploy_conclusion != 'success':
    red.append('last_deploy_not_success')
if remote_main and deploy_sha and remote_main != deploy_sha:
    if compare_files is None:
        red.append('remote_main_diff_unknown')
    elif pending_runtime_paths:
        red.append('remote_main_deployable_ne_deployed_sha')
    else:
        drift_notes.append('remote_main_ahead_only_non_deployable_changes')
if deploy_sha and deployment_backend_sha and deploy_sha != deployment_backend_sha:
    red.append('manifest_ne_last_deploy_sha')
if not frontend_hash:
    red.append('frontend_asset_sha_unknown')
if pi_conclusion != 'success':
    red.append('scheduler_or_pi_health_failed')
# HTTP 200 must not mask scheduler failure
if health_ok and pi_conclusion != 'success':
    red.append('health_ok_but_background_jobs_unhealthy')

# Frontend hash is short by design — never claim it equals full git SHA
identity = {
  'project': 'AllTrue_System',
  'generated_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'prod_url': prod_url,
  'repo': repo,
  'remote_main_full_sha': remote_main or None,
  'deployed_backend_full_sha': deploy_sha or None,
  'deployed_frontend_asset_sha': frontend_hash or None,
  'deployed_frontend_build_sha': frontend_build_sha or None,
  'deployment_manifest_backend_sha': deployment_backend_sha or None,
  'deployment_manifest_frontend_sha': (deployment or {}).get('frontend_build_sha'),
  'deployment_manifest_deployed_at': deployment_deployed_at,
  'deployment_manifest': deployment or None,
  'frontend_asset_built_at_local': frontend_time,
  'frontend_sha_is_full_git_sha': False,
  'last_deploy_workflow_run': deploy,
  'latest_deploy_workflow_run': latest_deploy_workflow_run,
  'successful_deploy_runs_considered': len(successful_deploy_runs),
  'deploy_status': deploy_conclusion,
  'pending_runtime_paths': pending_runtime_paths,
  'drift_notes': drift_notes,
  'scheduler_evidence': {
    'source': 'pi-health.yml last run',
    'conclusion': pi_conclusion,
    'timestamp': pi_created,
    'url': (pi or {}).get('url'),
    'note': 'Use Pi Health CRITICALS; schedule:list is not proof of execution',
  },
  'health': {
    'http_code': health_code,
    'body': health,
    'ok': health_ok,
  },
  'db_migration_version': None,
  'db_migration_version_status': 'unknown_without_pi_ssh',
  'rollback_target': deploy_sha,
  'data_reconciliation_last_result': None,
  'data_reconciliation_status': 'unknown_without_authenticated_ops_api',
  'reconciliation_baseline': {
    'status': 'unknown',
    'note': 'Populated on Pi via scheduler:evidence-summary; residuals must not flip execution_healthy',
  },
  'critical_jobs': {
    'expected': None,
    'executed': None,
    'succeeded': None,
    'partial': None,
    'failed': None,
    'stale': None,
    'source': 'pi scheduler:evidence-summary critical_jobs (after deploy of Phase 1)',
  },
  'drift_red': red,
  'overall': 'RED' if red else 'GREEN',
}
print(json.dumps(identity, indent=2, ensure_ascii=False))
sys.exit(1 if red else 0)
PY
