"""Build a bounded receipt after the sole production executor has succeeded."""

import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
import re
import sys


SHA = re.compile(r"[0-9a-f]{40}\Z")
SHORT_SHA = re.compile(r"[0-9a-f]{4,39}\Z")
REPOSITORY = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z")
EVENTS = {"workflow_run", "workflow_dispatch", "repository_dispatch"}


def full_sha(value, name):
    if not isinstance(value, str) or not SHA.fullmatch(value):
        raise ValueError(f"{name} must be a full lowercase SHA")
    return value


def positive_int(value, name):
    if not isinstance(value, str) or not value.isdecimal() or int(value) < 1:
        raise ValueError(f"{name} must be a positive integer")
    return int(value)


def parsed_time(value, name):
    if not isinstance(value, str):
        raise ValueError(f"{name} is missing")
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError(f"{name} is invalid") from exc
    if result.tzinfo is None:
        raise ValueError(f"{name} lacks timezone")
    return result


def frontend_identity(value):
    if value is None or value == "unknown":
        return "unknown"
    if isinstance(value, str) and SHA.fullmatch(value):
        return "full-sha"
    if isinstance(value, str) and SHORT_SHA.fullmatch(value):
        return "short-sha"
    raise ValueError("runtime frontend identity is invalid")


def make_receipt(manifest, metadata, now=None):
    now = now or datetime.now(timezone.utc)
    if not isinstance(manifest, dict):
        raise ValueError("runtime manifest must be an object")
    repository = metadata.get("GITHUB_REPOSITORY")
    if not isinstance(repository, str) or not REPOSITORY.fullmatch(repository):
        raise ValueError("GITHUB_REPOSITORY must be owner/repo")
    event = metadata.get("GITHUB_EVENT_NAME")
    if event not in EVENTS:
        raise ValueError("unsupported deployment event")
    target = full_sha(metadata.get("TARGET_SHA"), "TARGET_SHA")
    workflow_sha = full_sha(metadata.get("GITHUB_WORKFLOW_SHA"), "GITHUB_WORKFLOW_SHA")
    event_sha = full_sha(metadata.get("GITHUB_SHA"), "GITHUB_SHA")
    backend_sha = full_sha(manifest.get("backend_sha"), "runtime backend_sha")
    if backend_sha != target:
        raise ValueError("runtime backend_sha differs from resolved target")
    frontend_sha = manifest.get("frontend_sha")
    frontend_build_sha = manifest.get("frontend_build_sha")
    identity_status = frontend_identity(frontend_sha)
    if frontend_sha != frontend_build_sha:
        raise ValueError("runtime frontend identities differ")
    if manifest.get("source") != "github-actions:deploy.yml":
        raise ValueError("unexpected runtime manifest source")
    deployed_at = manifest.get("deployed_at")
    deployed = parsed_time(deployed_at, "runtime deployed_at")
    attempt_started_at = metadata.get("DEPLOY_ATTEMPT_STARTED_AT")
    started = parsed_time(attempt_started_at, "DEPLOY_ATTEMPT_STARTED_AT")
    if not started <= now or now - started > timedelta(minutes=35):
        raise ValueError("deployment attempt marker is outside this job")
    if not max(started - timedelta(minutes=5), now - timedelta(minutes=35)) <= deployed <= now + timedelta(minutes=5):
        raise ValueError("runtime deployed_at predates this attempt or is too far in the future")
    return {
        "schema": 1,
        "repository": repository,
        "source_sha": target,
        "workflow_revision_sha": workflow_sha,
        "event_head_sha": event_sha,
        "event": event,
        "run_id": positive_int(metadata.get("GITHUB_RUN_ID"), "GITHUB_RUN_ID"),
        "run_attempt": positive_int(metadata.get("GITHUB_RUN_ATTEMPT"), "GITHUB_RUN_ATTEMPT"),
        "attempt_started_at": attempt_started_at,
        "deployed_at": deployed_at,
        "observed_at": now.isoformat(),
        "runtime": {
            "backend_sha": backend_sha,
            "frontend_sha": frontend_sha,
            "frontend_build_sha": frontend_build_sha,
            "frontend_identity_status": identity_status,
            "source": manifest["source"],
        },
        "verification_state": "production-verified",
        "verification_evidence": "deploy.yml Deploy step health and post-merge smoke succeeded before receipt creation",
        "application_artifact_digest": "unknown",
        "configuration_version": "unknown",
    }


def main():
    if len(sys.argv) != 3:
        raise SystemExit("usage: deploy_receipt.py MANIFEST_JSON OUTPUT_JSON")
    manifest = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    receipt = make_receipt(manifest, os.environ)
    Path(sys.argv[2]).write_text(json.dumps(receipt, sort_keys=True, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        raise SystemExit(f"deployment receipt refused: {exc}") from exc
