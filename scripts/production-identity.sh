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
HEALTH_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 "${PROD_URL}/api/v1/health" || echo 000)"

DEPLOY_JSON="$(gh run list -R "$REPO" --workflow=deploy.yml --limit 1 --json databaseId,conclusion,status,headSha,createdAt,url,displayTitle 2>/dev/null || echo '[]')"
PI_HEALTH_JSON="$(gh run list -R "$REPO" --workflow=pi-health.yml --limit 1 --json databaseId,conclusion,status,createdAt,url,displayTitle 2>/dev/null || echo '[]')"

python3 - "$REMOTE_MAIN" "$HEALTH_RAW" "$VERSION_RAW" "$HEALTH_CODE" "$DEPLOY_JSON" "$PI_HEALTH_JSON" "$PROD_URL" "$REPO" <<'PY'
import json,sys,datetime
remote_main, health_raw, version_raw, health_code, deploy_json, pi_json, prod_url, repo = sys.argv[1:9]

def parse(raw, default=None):
    try:
        return json.loads(raw) if raw else default
    except Exception:
        return default

health = parse(health_raw, {})
version = parse(version_raw, {})
deploy = (parse(deploy_json, []) or [None])[0]
pi = (parse(pi_json, []) or [None])[0]

frontend_hash = (version or {}).get('hash')
frontend_time = (version or {}).get('t')
health_status = (health or {}).get('status')
health_ok = health_code == '200' and health_status == 'ok'

deploy_sha = (deploy or {}).get('headSha')
deploy_conclusion = (deploy or {}).get('conclusion')
pi_conclusion = (pi or {}).get('conclusion')
pi_created = (pi or {}).get('createdAt')

red = []
if not health_ok:
    red.append('health_not_ok')
if not remote_main:
    red.append('remote_main_unknown')
if not deploy_sha:
    red.append('deployed_backend_sha_unknown')
if deploy_conclusion != 'success':
    red.append('last_deploy_not_success')
if remote_main and deploy_sha and remote_main != deploy_sha:
    red.append('remote_main_ne_last_deploy_sha')
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
  'frontend_asset_built_at_local': frontend_time,
  'frontend_sha_is_full_git_sha': False,
  'last_deploy_workflow_run': deploy,
  'deploy_status': deploy_conclusion,
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
  'drift_red': red,
  'overall': 'RED' if red else 'GREEN',
}
print(json.dumps(identity, indent=2, ensure_ascii=False))
sys.exit(1 if red else 0)
PY
