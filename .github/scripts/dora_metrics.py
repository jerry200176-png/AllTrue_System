"""Conservative weekly AllTrue DORA report from existing deploy.yml evidence."""

import hashlib
import io
import json
import os
import re
import resource
import subprocess
import sys
import tempfile
import zipfile
from datetime import datetime, timedelta, timezone
from urllib.parse import urlencode

PERIOD_DAYS = 30
# GitHub cancels a workflow run at 35 days; scan earlier creations for deploys
# completed during the last 30 days. Slices avoid the API's 1,000 result limit.
MAX_RUN_AGE_DAYS = 35
SLICE_DAYS = 7
MAX_RUNS = 2000
MAX_API_CALLS = 3000
PER_PAGE = 100
MAX_ARTIFACT_BYTES = 128 * 1024
MAX_RECEIPT_BYTES = 16 * 1024
ARTIFACT_PREFIX = "production-deploy-receipt-"
RECEIPT_FILE = "alltrue-production-deployment-receipt.json"
DEPLOY_JOB = "Deploy to Production"
REQUIRED_STEPS = ("Deploy", "Record deployed and production-verified state")
DEFAULT_PRODUCTION_URL = "https://daan.lifenet.com.tw"
SHA = re.compile(r"^[0-9a-f]{40}$")


class UnknownEvidence(Exception):
    """Available data cannot establish a truthful frequency."""

    def __init__(self, reason, lower_bound=0):
        super().__init__(reason)
        self.lower_bound = lower_bound


def timestamp(value):
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            raise ValueError("timestamp has no timezone")
        return parsed.astimezone(timezone.utc)
    except (AttributeError, TypeError, ValueError) as error:
        raise UnknownEvidence("missing or invalid timestamp") from error


class GitHub:
    def __init__(self, repo):
        if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
            raise UnknownEvidence("invalid repository identity")
        self.repo = repo
        self.calls = 0

    def get(self, path, params=None):
        self.calls += 1
        if self.calls > MAX_API_CALLS:
            raise UnknownEvidence("GitHub API request cap exceeded")
        endpoint = f"repos/{self.repo}/{path}"
        if params:
            endpoint += "?" + urlencode(params)
        try:
            result = subprocess.run(["gh", "api", endpoint], text=True, capture_output=True, timeout=30)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise UnknownEvidence("GitHub API unavailable") from error
        if result.returncode:
            raise UnknownEvidence("GitHub API request failed")
        try:
            return json.loads(result.stdout)
        except ValueError as error:
            raise UnknownEvidence("GitHub API returned invalid JSON") from error

    def download_artifact(self, artifact_id):
        self.calls += 1
        if self.calls > MAX_API_CALLS:
            raise UnknownEvidence("GitHub API request cap exceeded")
        if not isinstance(artifact_id, int) or artifact_id < 1:
            raise UnknownEvidence("invalid artifact identity")
        try:
            with tempfile.TemporaryFile() as output:
                def bound_output_file():
                    resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_ARTIFACT_BYTES, MAX_ARTIFACT_BYTES))

                result = subprocess.run(
                    ["gh", "api", f"repos/{self.repo}/actions/artifacts/{artifact_id}/zip"],
                    stdout=output, stderr=subprocess.DEVNULL, timeout=30, preexec_fn=bound_output_file)
                if result.returncode or output.tell() > MAX_ARTIFACT_BYTES:
                    raise UnknownEvidence("receipt artifact unavailable or oversized")
                output.seek(0)
                return output.read(MAX_ARTIFACT_BYTES + 1)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise UnknownEvidence("receipt artifact download failed") from error


def exact_target_receipt(gh, attempt_run, job, now):
    run_id, attempt = attempt_run["id"], attempt_run["run_attempt"]
    name = f"{ARTIFACT_PREFIX}{run_id}-{attempt}"
    artifacts = pages(gh, f"actions/runs/{run_id}/artifacts", "artifacts", {})
    matches = [artifact for artifact in artifacts if artifact.get("name") == name]
    if len(matches) != 1:
        raise UnknownEvidence("missing or duplicate exact deployment receipt artifact")
    artifact = matches[0]
    if artifact.get("expired") is not False or timestamp(artifact.get("expires_at")) <= now:
        raise UnknownEvidence("deployment receipt artifact is expired")
    artifact_created = timestamp(artifact.get("created_at"))
    if not timestamp(attempt_run.get("created_at")) <= artifact_created <= timestamp(job.get("completed_at")):
        raise UnknownEvidence("deployment receipt artifact is outside completed job")
    owner_run = artifact.get("workflow_run")
    if not isinstance(owner_run, dict) or owner_run.get("id") != run_id or owner_run.get("head_sha") != attempt_run.get("head_sha"):
        raise UnknownEvidence("deployment receipt artifact belongs to another run")
    size = artifact.get("size_in_bytes")
    if not isinstance(size, int) or not 0 < size <= MAX_ARTIFACT_BYTES:
        raise UnknownEvidence("deployment receipt artifact size is missing or excessive")
    archive = gh.download_artifact(artifact.get("id"))
    if len(archive) != size:
        raise UnknownEvidence("deployment receipt artifact archive size differs")
    digest = artifact.get("digest")
    if not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
        raise UnknownEvidence("deployment receipt artifact digest is missing or malformed")
    if digest != f"sha256:{hashlib.sha256(archive).hexdigest()}":
        raise UnknownEvidence("deployment receipt artifact digest differs")
    try:
        with zipfile.ZipFile(io.BytesIO(archive)) as bundle:
            files = bundle.infolist()
            if len(files) != 1 or files[0].filename != RECEIPT_FILE or files[0].file_size > MAX_RECEIPT_BYTES:
                raise UnknownEvidence("deployment receipt archive structure differs")
            with bundle.open(files[0]) as member:
                payload = member.read(MAX_RECEIPT_BYTES + 1)
            if len(payload) > MAX_RECEIPT_BYTES:
                raise UnknownEvidence("deployment receipt JSON is oversized")
            receipt = json.loads(payload.decode("utf-8"))
    except (ValueError, UnicodeError, zipfile.BadZipFile, RuntimeError) as error:
        raise UnknownEvidence("deployment receipt archive is invalid") from error
    if not isinstance(receipt, dict) or receipt.get("schema") != 1:
        raise UnknownEvidence("deployment receipt schema differs")
    event_head = str(attempt_run.get("head_sha") or "").lower()
    source = receipt.get("source_sha")
    revision = receipt.get("workflow_revision_sha")
    runtime = receipt.get("runtime")
    if (receipt.get("repository") != gh.repo or receipt.get("event") != attempt_run.get("event")
            or receipt.get("run_id") != run_id or receipt.get("run_attempt") != attempt
            or receipt.get("event_head_sha") != event_head
            or not isinstance(source, str) or not SHA.fullmatch(source)
            or not isinstance(revision, str) or not SHA.fullmatch(revision)
            or not SHA.fullmatch(event_head)
            or not isinstance(runtime, dict) or runtime.get("backend_sha") != source
            or runtime.get("source") != "github-actions:deploy.yml"
            or receipt.get("verification_state") != "production-verified"
            or not isinstance(receipt.get("verification_evidence"), str)
            or not receipt["verification_evidence"]
            or not isinstance(receipt.get("application_artifact_digest"), str)
            or not receipt["application_artifact_digest"]
            or not isinstance(receipt.get("configuration_version"), str)
            or not receipt["configuration_version"]):
        raise UnknownEvidence("deployment receipt provenance differs")
    if attempt_run.get("workflow_sha") is not None and revision != attempt_run["workflow_sha"]:
        raise UnknownEvidence("deployment receipt workflow revision differs")
    frontend = runtime.get("frontend_sha")
    if frontend != runtime.get("frontend_build_sha"):
        raise UnknownEvidence("deployment receipt frontend identities differ")
    if frontend is None or frontend == "unknown":
        expected_frontend_status = "unknown"
    elif isinstance(frontend, str) and SHA.fullmatch(frontend):
        expected_frontend_status = "full-sha"
    elif isinstance(frontend, str) and re.fullmatch(r"[0-9a-f]{4,39}", frontend):
        expected_frontend_status = "short-sha"
    else:
        raise UnknownEvidence("deployment receipt frontend identity is invalid")
    if runtime.get("frontend_identity_status") != expected_frontend_status:
        raise UnknownEvidence("deployment receipt frontend identity status differs")
    deployed = timestamp(receipt.get("deployed_at"))
    observed = timestamp(receipt.get("observed_at"))
    started = timestamp(receipt.get("attempt_started_at"))
    job_started = timestamp(job.get("started_at"))
    if (not timestamp(attempt_run.get("created_at")) <= job_started <= started <= observed <= artifact_created <= timestamp(job.get("completed_at")) <= now
            or observed - started > timedelta(minutes=35)
            or not max(timestamp(attempt_run.get("created_at")), started - timedelta(minutes=5),
                       observed - timedelta(minutes=35)) <= deployed <= observed + timedelta(minutes=5)
            or deployed > now):
        raise UnknownEvidence("deployment receipt time is outside completed job")
    return source, deployed


def pages(gh, path, key, params):
    """Fetch every page explicitly; never interpret a partial page as zero."""
    result = []
    expected = None
    for page in range(1, (MAX_RUNS // PER_PAGE) + 2):
        data = gh.get(path, {**params, "per_page": PER_PAGE, "page": page})
        if not isinstance(data, dict) or not isinstance(data.get("total_count"), int):
            raise UnknownEvidence("missing pagination total")
        if expected is None:
            expected = data["total_count"]
            if expected < 0 or expected >= 1000 or expected > MAX_RUNS:
                raise UnknownEvidence("pagination total exceeds bounded scan")
        if data["total_count"] != expected or not isinstance(data.get(key), list):
            raise UnknownEvidence("pagination changed or malformed")
        batch = data[key]
        if len(batch) != min(PER_PAGE, expected - len(result)):
            raise UnknownEvidence("incomplete pagination")
        result.extend(batch)
        if len(result) == expected:
            return result
    raise UnknownEvidence("pagination cap exceeded")


def workflow_runs(gh, start, end):
    runs = []
    seen = set()
    cursor = start
    while cursor < end:
        next_cursor = min(cursor + timedelta(days=SLICE_DAYS), end)
        query = {"created": f"{cursor.strftime('%Y-%m-%dT%H:%M:%SZ')}..{next_cursor.strftime('%Y-%m-%dT%H:%M:%SZ')}"}
        batch = pages(gh, "actions/workflows/deploy.yml/runs", "workflow_runs", query)
        batch_seen = set()
        for run in batch:
            if not isinstance(run, dict) or not isinstance(run.get("id"), int):
                raise UnknownEvidence("run lacks identity")
            created = timestamp(run.get("created_at"))
            if not cursor <= created <= next_cursor or run["id"] in batch_seen:
                raise UnknownEvidence("run page duplicates or exceeds query window")
            batch_seen.add(run["id"])
            # Slice endpoints are inclusive, so the same run can occur twice.
            if run["id"] not in seen:
                seen.add(run["id"])
                runs.append(run)
        if len(runs) > MAX_RUNS:
            raise UnknownEvidence("run scan cap exceeded")
        cursor = next_cursor
    return runs


def _collect_receipts(gh, runs, period_start, now, receipts):
    seen = set()
    for run in runs:
        run_id = run["id"]
        if timestamp(run.get("updated_at")) < period_start:
            continue
        if not str(run.get("path", "")).startswith(".github/workflows/deploy.yml"):
            raise UnknownEvidence("workflow provenance differs")
        attempts = run.get("run_attempt")
        if not isinstance(attempts, int) or not 1 <= attempts <= 10:
            raise UnknownEvidence("missing or excessive run attempts")
        for attempt in range(1, attempts + 1):
            attempt_run = run if attempt == attempts else gh.get(f"actions/runs/{run_id}/attempts/{attempt}")
            if attempt_run.get("id") != run_id or attempt_run.get("run_attempt") != attempt:
                raise UnknownEvidence("attempt identity differs")
            if attempt_run.get("status") != "completed":
                continue
            jobs = pages(gh, f"actions/runs/{run_id}/attempts/{attempt}/jobs", "jobs", {})
            matches = [job for job in jobs if job.get("name") == DEPLOY_JOB]
            if not matches:
                continue  # complete job list proves no exact deploy job executed
            if len(matches) != 1:
                raise UnknownEvidence("missing or ambiguous production deploy job")
            job = matches[0]
            if job.get("status") == "completed" and job.get("conclusion") == "skipped":
                continue
            steps = job.get("steps")
            if not isinstance(steps, list):
                raise UnknownEvidence("deploy steps unavailable")
            deploy_steps = [step for step in steps if step.get("name") == "Deploy"]
            if job.get("conclusion") != "success":
                if len(deploy_steps) == 1 and deploy_steps[0].get("conclusion") == "skipped":
                    continue  # failed before the production side-effect step
                if not deploy_steps and any(step.get("name") == "Final exact-main gate before production executor"
                                            and step.get("conclusion") in ("failure", "cancelled") for step in steps):
                    continue  # an ordered pre-deploy gate failed before Deploy
                raise UnknownEvidence("deploy step outcome is not proven")
            if job.get("status") != "completed":
                raise UnknownEvidence("production deploy job is not completed")
            if (job.get("run_id") != run_id or job.get("run_attempt") != attempt
                    or job.get("head_sha") != attempt_run.get("head_sha")):
                raise UnknownEvidence("deploy job attempt or source SHA differs")
            for name in REQUIRED_STEPS:
                matching = [step for step in steps if step.get("name") == name]
                if len(matching) != 1 or matching[0].get("conclusion") != "success" or matching[0].get("status") != "completed":
                    raise UnknownEvidence("required deploy step not proven")
            completed = timestamp(job.get("completed_at"))
            if not period_start <= completed <= now:
                continue
            event = attempt_run.get("event")
            sha = str(attempt_run.get("head_sha") or "").lower()
            deployed_at = None
            if event not in ("workflow_run", "workflow_dispatch", "repository_dispatch"):
                raise UnknownEvidence("unsupported production deployment event")
            upload_steps = [step for step in steps if step.get("name") == "Upload exact target deployment receipt"]
            if event in ("workflow_dispatch", "repository_dispatch") or upload_steps:
                marker_steps = [step for step in steps if step.get("name") == "Mark exact deployment attempt"]
                if len(marker_steps) != 1 or marker_steps[0].get("conclusion") != "success" or marker_steps[0].get("status") != "completed":
                    raise UnknownEvidence("exact deployment attempt marker not proven")
                if len(upload_steps) != 1 or upload_steps[0].get("conclusion") != "success" or upload_steps[0].get("status") != "completed":
                    raise UnknownEvidence("exact deployment receipt upload step not proven")
                sha, deployed_at = exact_target_receipt(gh, attempt_run, job, now)
                if event == "workflow_run" and sha != str(attempt_run.get("head_sha") or "").lower():
                    raise UnknownEvidence("automatic receipt target differs from run head")
                if deployed_at < period_start:
                    raise UnknownEvidence("exact deployment receipt predates reporting window")
            elif event != "workflow_run" or not SHA.fullmatch(sha):
                raise UnknownEvidence(f"run {run_id} attempt {attempt} target SHA is not provable from {event} metadata")
            receipt = (run_id, attempt)
            if receipt in seen:
                raise UnknownEvidence("duplicate run attempt receipt")
            seen.add(receipt)
            receipts.append({"run_id": run_id, "attempt": attempt, "sha": sha,
                             "completed": completed, "deployed_at": deployed_at})
    return receipts


def verified_receipts(gh, runs, period_start, now):
    receipts = []
    try:
        return _collect_receipts(gh, runs, period_start, now, receipts)
    except UnknownEvidence as error:
        error.lower_bound = len(receipts)
        raise


def runtime_identity():
    try:
        base_url = os.environ.get("ALLTRUE_PROD_URL", DEFAULT_PRODUCTION_URL).rstrip("/")
        if not base_url.startswith("https://"):
            raise UnknownEvidence("production URL must use HTTPS")
        result = subprocess.run(["curl", "-fsSL", "--proto", "=https", "--proto-redir", "=https",
                                 "--tlsv1.2", "--max-time", "10", f"{base_url}/deployment.json"],
                                text=True, capture_output=True, timeout=12)
        if result.returncode:
            raise UnknownEvidence("production runtime manifest unavailable")
        manifest = json.loads(result.stdout)
    except (OSError, ValueError, subprocess.TimeoutExpired) as error:
        raise UnknownEvidence("production runtime manifest unavailable") from error
    if not isinstance(manifest, dict):
        raise UnknownEvidence("production runtime manifest malformed")
    sha = str(manifest.get("backend_sha") or "").lower()
    if manifest.get("source") != "github-actions:deploy.yml" or not SHA.fullmatch(sha):
        raise UnknownEvidence("production runtime provenance missing")
    return sha, timestamp(manifest.get("deployed_at"))


def calculate(gh, now, runtime):
    start = now - timedelta(days=PERIOD_DAYS)
    if runtime[1] > now:
        raise UnknownEvidence("runtime deployment timestamp is after report cutoff")
    scan_start = start - timedelta(days=MAX_RUN_AGE_DAYS)
    receipts = verified_receipts(gh, workflow_runs(gh, scan_start, now), start, now)
    if receipts:
        latest = max(receipts, key=lambda item: item["completed"])
        if latest["sha"] != runtime[0] or (latest["deployed_at"] is not None and latest["deployed_at"] != runtime[1]):
            raise UnknownEvidence("latest proven deploy differs from production runtime", len(receipts))
    if not receipts and start <= runtime[1] <= now:
        raise UnknownEvidence("runtime deployed during window but no job receipt was proven")
    return receipts


def report(repo, now=None, gh=None, runtime_reader=runtime_identity):
    now = now or datetime.now(timezone.utc)
    try:
        gh = gh or GitHub(repo)
        runtime = runtime_reader()
        receipts = calculate(gh, now, runtime)
        frequency = f"{len(receipts) / PERIOD_DAYS * 7:.1f}/week ({len(receipts)} verified deploy attempts)"
        provenance = f"Runtime: {runtime[0]} deployed at {runtime[1].isoformat()}"
    except (UnknownEvidence, subprocess.TimeoutExpired) as error:
        frequency = "UNKNOWN"
        provenance = f"Reason: {error}; verified lower bound before uncertainty: {getattr(error, 'lower_bound', 0)} receipt(s)"
    calls = gh.calls if gh is not None else 0
    return "\n".join([
        f"### DORA Metrics — past {PERIOD_DAYS} days",
        f"As of: {now.strftime('%Y-%m-%dT%H:%M:%SZ')} (UTC); API calls: {calls}/{MAX_API_CALLS} cap; run cap: {MAX_RUNS}",
        f"Deployment Frequency: {frequency}",
        "Lead Time for Changes: UNKNOWN (commit-to-production mapping not established)",
        "Change Failure Rate: UNKNOWN (change-linked production failures not established)",
        "Failed Deployment Recovery Time: UNKNOWN (incident restoration evidence not established)",
        "Deployment Rework Rate: UNKNOWN (unplanned corrective deployments not linked to incidents)",
        provenance,
        "Source: deploy.yml successful Deploy to Production job + Deploy and Record deployed and production-verified state steps; exact run/attempt artifact receipt for dispatch; production deployment.json.",
    ])


def main():
    output = report(os.environ.get("REPO", ""))
    print(output)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(output + "\n")
    return int("Deployment Frequency: UNKNOWN" in output)


if __name__ == "__main__":
    sys.exit(main())
