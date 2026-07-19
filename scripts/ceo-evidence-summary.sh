#!/usr/bin/env bash
# CEO Evidence Contract — AllTrue (machine-readable, no PII).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
set +e; IDENTITY_JSON="$(bash scripts/production-identity.sh 2>/dev/null)"; ID_RC=$?; set -e
python3 -c '
import json,sys,datetime
raw=sys.stdin.read()
try: identity=json.loads(raw) if raw.strip().startswith("{") else {}
except Exception: identity={}
registry=json.load(open("ops/critical-job-registry.json"))
jobs=[j for j in registry["jobs"] if j.get("criticality") in ("critical","high")]
overall=identity.get("overall","unknown")
status="green" if overall=="GREEN" else ("red" if overall=="RED" else "unknown")
print(json.dumps({
  "project":"AllTrue_System",
  "as_of":datetime.datetime.now(datetime.timezone.utc).isoformat(),
  "production":{"status":status,"sha":identity.get("deployed_backend_full_sha") or identity.get("remote_main_full_sha"),
    "verified":identity.get("deploy_status")=="success" and (identity.get("health") or {}).get("ok") is True},
  "critical_jobs":identity.get("critical_jobs") or {"expected":len([j for j in registry["jobs"] if j["criticality"]=="critical"]),
    "executed":None,"succeeded":None,"partial":None,"failed":None,"stale":None},
  "outcomes":{"scheduler_evidence":identity.get("scheduler_evidence"),"reconciliation_baseline":identity.get("reconciliation_baseline"),
    "drift_red":identity.get("drift_red") or []},
  "risks":identity.get("drift_red") or [],"founder_decisions_required":[],
  "evidence_confidence":"medium" if identity else "low",
  "registry_job_count":len(registry["jobs"]),
  "critical_high_job_keys":[j["job_key"] for j in jobs],"identity_exit_code":'"$ID_RC"',
},indent=2,ensure_ascii=False))
' <<<"$IDENTITY_JSON"
