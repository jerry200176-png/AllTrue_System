"""Read a native deploy's state and verify its exact production identity."""

import argparse
import json
import os
import re
import subprocess
import time
from datetime import datetime
from pathlib import Path
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
FULL_SHA = re.compile(r"[0-9a-f]{40}")
TARGET_LINE = re.compile(
    r"(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z) Resolved activation target: ([0-9a-f]{40})",
)
RETRYABLE_HTTP = {408, 429, 500, 502, 503, 504}
RESULT_CODES = {
    "verified": 0, "failed": 1, "unavailable": 2, "approval_required": 3,
    "waiting": 4, "unverified": 4, "not_deployed": 4,
}


def is_full_sha(value):
    return isinstance(value, str) and FULL_SHA.fullmatch(value) is not None


class ObservationError(Exception):
    def __init__(self, message, *, retryable=False):
        super().__init__(message)
        self.retryable = retryable


def read_command(args, deadline, *, accepted=(0,), env=None):
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise ObservationError("Observation time limit reached.")
    try:
        result = subprocess.run(
            args, stdin=subprocess.DEVNULL, capture_output=True, text=True,
            env=env, timeout=min(25, remaining),
        )
    except subprocess.TimeoutExpired as exc:
        raise ObservationError("Read timed out.", retryable=True) from exc
    except UnicodeError as exc:
        raise ObservationError("Read returned invalid text.") from exc
    except OSError as exc:
        raise ObservationError("Required read command could not be started.") from exc
    if result.returncode not in accepted:
        status = re.search(r"\bHTTP (\d{3})\b", result.stderr)
        if status:
            code = int(status[1])
            raise ObservationError(f"GitHub API returned HTTP {code}.", retryable=code in RETRYABLE_HTTP)
        raise ObservationError("Read command failed; check GitHub CLI authentication and connectivity.")
    return result.stdout


def decode_json(raw):
    try:
        return json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise ObservationError("Read returned invalid JSON.") from exc


class Observer:
    def __init__(self, options):
        self.options = options
        self.deadline = time.monotonic() + options.timeout
        self.run_id = options.run_id
        self.target = options.sha

    def api(self, endpoint, *, raw=False, response_type=dict):
        response = read_command(["gh", "api", f"/repos/{self.options.repo}/{endpoint}"], self.deadline)
        if raw:
            return response
        decoded = decode_json(response)
        if not isinstance(decoded, response_type):
            raise ObservationError("GitHub returned an unexpected response type.")
        return decoded

    def select_run(self):
        if self.options.pr and not self.target:
            pr = self.api(f"pulls/{self.options.pr}")
            if pr.get("merged") is not True:
                return {"status": "waiting", "reason": "The PR has not merged.",
                        "next": "Check required reviews and checks for this PR."}
            self.target = pr.get("merge_commit_sha") or ""
            if not is_full_sha(self.target):
                raise ObservationError("GitHub did not return an exact merge commit.")
        if self.target and not is_full_sha(self.target):
            commit = self.api(f"commits/{self.target}")
            resolved = commit.get("sha") or ""
            if not is_full_sha(resolved) or not resolved.startswith(self.target):
                raise ObservationError("Could not resolve the requested commit prefix.")
            self.target = resolved
        if self.run_id is None:
            runs = self.api(
                f"actions/workflows/{self.options.workflow}/runs?branch=main&head_sha={self.target}&per_page=8"
            ).get("workflow_runs", [])
            if not isinstance(runs, list) or any(not isinstance(item, dict) for item in runs):
                raise ObservationError("GitHub returned an incomplete workflow run list.")
            run = next((item for item in runs if item.get("head_sha") == self.target), None)
            if not run:
                return {"status": "waiting", "target": self.target, "reason": "No matching deploy run.",
                        "next": "Check main CI. For a manually selected target, supply its exact run ID."}
            self.run_id = run.get("id")
        return None

    def result(self, status, reason, **details):
        return {"status": status, "run_id": self.run_id, "target": self.target or None,
                "url": f"https://github.com/{self.options.repo}/actions/runs/{self.run_id}",
                "reason": reason, **details}

    def run_jobs(self):
        data = self.api(f"actions/runs/{self.run_id}/jobs?per_page=100&filter=latest")
        jobs = data.get("jobs")
        if (not isinstance(jobs, list) or type(data.get("total_count")) is not int or data["total_count"] != len(jobs)
                or any(not isinstance(job, dict) or job.get("run_id") != self.run_id for job in jobs)):
            raise ObservationError("The activation jobs could not be read completely.")
        return jobs

    def resolved_target(self, jobs):
        resolvers = [job for job in jobs if job.get("name") == "Resolve exact activation target"]
        if len(resolvers) != 1:
            raise ObservationError("Expected exactly one target resolver job.")
        resolver = resolvers[0]
        if (type(resolver.get("id")) is not int or resolver["id"] <= 0
                or resolver.get("run_id") != self.run_id
                or (resolver.get("status"), resolver.get("conclusion")) != ("completed", "success")):
            raise ObservationError("The run has no successful target resolver.")
        log = self.api(f"actions/jobs/{resolver['id']}/logs", raw=True)
        lines = [line for line in log.splitlines() if re.match(r"^\S+ Resolved activation target:", line)]
        matches = [TARGET_LINE.fullmatch(line) for line in lines]
        if len(matches) != 1 or matches[0] is None:
            raise ObservationError("The run does not have one exact target marker.")
        try:
            datetime.fromisoformat(matches[0][1].replace("Z", "+00:00"))
        except ValueError as exc:
            raise ObservationError("The target marker has an invalid timestamp.") from exc
        return matches[0][2]

    def verify_production(self):
        raw = read_command(
            ["bash", str(ROOT / "scripts/production-identity.sh")],
            self.deadline,
            accepted=(0, 1),
            env={**os.environ, "ALLTRUE_REPO": self.options.repo, "ALLTRUE_PROD_URL": self.options.prod_url,
                 "ALLTRUE_DEPLOY_RUN_ID": str(self.run_id)},
        )
        identity = decode_json(raw)
        if not isinstance(identity, dict):
            raise ObservationError("Production identity is not an object.")
        job = identity.get("production_deploy_job")
        if job is not None and not isinstance(job, dict):
            raise ObservationError("Production identity has an invalid deploy job.")
        job = job or {}
        backend = identity.get("deployment_manifest_backend_sha") or ""
        target = job.get("target_sha") or ""
        if not isinstance(backend, str) or not isinstance(target, str):
            raise ObservationError("Production identity has an invalid commit field.")
        if (job.get("run_id") == self.run_id and is_full_sha(backend)
                and backend == target == identity.get("deployed_backend_full_sha")
                and (not self.target or target == self.target)):
            self.target = target
            return self.result("verified", "Production backend matches the verified deploy job.",
                               frontend=identity.get("deployed_frontend_build_sha"))
        return self.result(
            "unverified", "Workflow succeeded; this run and target are not verified in the current production manifest.",
            next="Read production identity and the original deploy receipt before retrying or dispatching.",
        )

    def inspect(self):
        selection = self.select_run()
        if selection:
            return selection
        if type(self.run_id) is not int or self.run_id <= 0:
            raise ObservationError("Invalid GitHub run ID.")
        run = self.api(f"actions/runs/{self.run_id}")
        if (run.get("id") != self.run_id or run.get("head_branch") != "main"
                or run.get("path") != f".github/workflows/{self.options.workflow}"):
            raise ObservationError("The run is outside the requested main-branch deploy workflow.")
        status = run.get("status")
        if status not in ("completed", "waiting", "pending", "in_progress", "queued", "requested"):
            raise ObservationError("GitHub returned an unknown run state.")
        if status == "completed":
            conclusion = run.get("conclusion")
            if conclusion == "success":
                jobs = [job for job in self.run_jobs() if job.get("name") == "Deploy to Production"]
                if len(jobs) != 1 or (jobs[0].get("status"), jobs[0].get("conclusion")) != ("completed", "success"):
                    return self.result("not_deployed", "This workflow finished without a successful production deploy job.",
                                       next="Read its release-state job and locate the authoritative release-train run before taking action.")
                return self.verify_production()
            return self.result("failed", f"Workflow concluded {conclusion or 'without a result'}.",
                               next="Check the failed job and current production identity before retrying.")
        if status in {"waiting", "pending", "in_progress"}:
            gates = self.api(f"actions/runs/{self.run_id}/pending_deployments", response_type=list)
            if any(not isinstance(gate, dict) or not isinstance(gate.get("environment"), dict) for gate in gates):
                raise ObservationError("GitHub returned invalid pending deployment data.")
            if any(gate.get("environment", {}).get("name") == "production-activation" for gate in gates):
                target = self.resolved_target(self.run_jobs())
                if self.target and target != self.target:
                    return self.result("unverified", "The waiting deploy targets a different commit.",
                                       observed_target=target, next="Locate the run for the requested target.")
                self.target = target
                return self.result(
                    "approval_required", "GitHub is waiting on the production-activation environment.",
                    next="Authorized reviewer: open the run, choose Review deployments, select production-activation, and approve. Then recheck this same run ID.",
                )
        return self.result("waiting", "The existing workflow has not finished.", workflow_status=status,
                           next="Check this same run ID again.")


def options_from_cli():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=os.getenv("WAIT_GITHUB_REPO", "jerry200176-png/AllTrue_System"))
    parser.add_argument("--sha", type=str.lower, default="")
    parser.add_argument("--pr", type=int)
    parser.add_argument("--run-id", type=int)
    parser.add_argument("--once", action="store_true", help="Read once and report a specific state.")
    parser.add_argument("--json", action="store_true", help="Print one final JSON object.")
    parser.add_argument("--timeout", type=int, default=os.getenv("WAIT_GITHUB_TIMEOUT_SEC", "600"))
    parser.add_argument("--poll-interval", type=int, default=os.getenv("WAIT_GITHUB_POLL_SEC", "15"))
    options = parser.parse_args()
    options.workflow = os.getenv("WAIT_GITHUB_WORKFLOW", "deploy.yml")
    legacy_url = os.getenv("WAIT_GITHUB_VERSION_URL", "")
    options.prod_url = os.getenv("WAIT_GITHUB_PROD_URL") or legacy_url.removesuffix("/version.json") or "https://daan.lifenet.com.tw"
    options.prod_url = options.prod_url.rstrip("/")
    url = urlsplit(options.prod_url)
    if (not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", options.repo)
            or any(part in {".", ".."} for part in options.repo.split("/"))):
        parser.error("use an owner/repo name")
    if not re.fullmatch(r"[A-Za-z0-9_-]+\.ya?ml", options.workflow):
        parser.error("use a workflow YAML filename")
    if url.scheme != "https" or not url.hostname or url.username is not None or url.password is not None or url.query or url.fragment:
        parser.error("use an HTTPS production base URL")
    if not (options.pr or options.sha or options.run_id):
        parser.error("specify --sha, --pr, or --run-id")
    if options.sha and not re.fullmatch(r"[0-9a-f]{8,40}", options.sha):
        parser.error("use an 8 to 40 hexadecimal SHA")
    if any(number is not None and number <= 0 for number in (options.pr, options.run_id)):
        parser.error("PR and run IDs must be positive")
    if not 1 <= options.timeout <= 3600 or not 1 <= options.poll_interval <= 300:
        parser.error("timeout and polling interval must be positive and bounded")
    return options


def watch(observer):
    consecutive_errors = 0
    previous = None
    while True:
        try:
            result = observer.inspect()
            consecutive_errors = 0
        except ObservationError as exc:
            consecutive_errors += 1
            if observer.options.once or not exc.retryable or consecutive_errors == 3:
                return {"status": "unavailable", "run_id": observer.run_id, "reason": str(exc),
                        "next": "Check GitHub CLI access or service status, then recheck the existing run."}
            result = {"status": "waiting", "reason": str(exc), "attempt": consecutive_errors}
        if result["status"] != "waiting" or observer.options.once:
            return result
        if result != previous and not observer.options.json:
            print(json.dumps(result, ensure_ascii=False), flush=True)
        previous = result
        delay = observer.options.poll_interval * max(1, 2 ** max(0, consecutive_errors - 1))
        if time.monotonic() + delay >= observer.deadline:
            return {**result, "deadline_reached": True}
        time.sleep(delay)


def main():
    options = options_from_cli()
    result = watch(Observer(options))
    print(json.dumps(result, ensure_ascii=False))
    return RESULT_CODES[result["status"]]


if __name__ == "__main__":
    raise SystemExit(main())
