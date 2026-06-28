"""PDP v3 signing and verification — commit-bound + run-bound forensic baseline."""
from __future__ import annotations

import hashlib
import json
import os
from typing import Any

SIGNATURE_KEY = "_signature"
CONTENT_HASH_KEY = "_content_hash"
BINDING_KEY = "_binding"
METADATA_KEYS = frozenset({SIGNATURE_KEY, CONTENT_HASH_KEY, BINDING_KEY})


def pdp_body_for_signing(pdp_v3: dict[str, Any]) -> dict[str, Any]:
    return {k: v for k, v in pdp_v3.items() if k not in METADATA_KEYS}


def compute_content_hash(pdp_v3: dict[str, Any]) -> str:
    body = pdp_body_for_signing(pdp_v3)
    payload = json.dumps(body, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def compute_bound_signature(content_hash: str, commit_sha: str, run_id: str) -> str:
    material = f"{content_hash}:{commit_sha}:{run_id}"
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def binding_from_env() -> tuple[str, str, str]:
    commit_sha = (
        os.environ.get("GITHUB_SHA")
        or os.environ.get("CI_COMMIT_SHA")
        or os.environ.get("PDP_COMMIT_SHA")
        or "local"
    )
    run_id = (
        os.environ.get("GITHUB_RUN_ID")
        or os.environ.get("CI_RUN_ID")
        or os.environ.get("PDP_RUN_ID")
        or "local"
    )
    branch = (
        os.environ.get("GITHUB_REF_NAME")
        or os.environ.get("CI_COMMIT_REF_NAME")
        or os.environ.get("PDP_BRANCH")
        or "local"
    )
    return commit_sha, run_id, branch


def attach_signature(
    pdp_v3: dict[str, Any],
    *,
    commit_sha: str | None = None,
    run_id: str | None = None,
) -> dict[str, Any]:
    env_commit, env_run, _ = binding_from_env()
    commit = commit_sha or env_commit
    run = run_id or env_run
    body = pdp_body_for_signing(pdp_v3)
    content_hash = compute_content_hash(body)
    signed = dict(body)
    signed[CONTENT_HASH_KEY] = content_hash
    signed[BINDING_KEY] = {"commit_sha": commit, "run_id": run}
    signed[SIGNATURE_KEY] = compute_bound_signature(content_hash, commit, run)
    return signed


def verify_signature(
    pdp_v3: dict[str, Any],
    *,
    commit_sha: str | None = None,
    run_id: str | None = None,
) -> tuple[bool, str]:
    if SIGNATURE_KEY not in pdp_v3:
        return False, "missing _signature"
    if CONTENT_HASH_KEY not in pdp_v3:
        return False, "missing _content_hash"

    binding = pdp_v3.get(BINDING_KEY) or {}
    expected_commit = commit_sha or binding.get("commit_sha", "")
    expected_run = run_id or binding.get("run_id", "")
    if not expected_commit or not expected_run:
        return False, "missing commit/run binding context"

    actual_content = compute_content_hash(pdp_v3)
    stored_content = pdp_v3[CONTENT_HASH_KEY]
    if stored_content != actual_content:
        return False, (
            f"content hash mismatch (stored {stored_content[:12]}…, "
            f"computed {actual_content[:12]}…)"
        )

    expected_sig = compute_bound_signature(stored_content, expected_commit, expected_run)
    if pdp_v3[SIGNATURE_KEY] != expected_sig:
        return False, (
            f"signature mismatch (expected {expected_sig[:12]}…, "
            f"got {pdp_v3[SIGNATURE_KEY][:12]}…)"
        )

    if binding:
        if binding.get("commit_sha") and binding.get("commit_sha") != expected_commit:
            return False, "binding commit_sha mismatch"
        if binding.get("run_id") and binding.get("run_id") != expected_run:
            return False, "binding run_id mismatch"

    return True, "ok"


def verify_artifact_binding(artifact: dict[str, Any]) -> tuple[bool, str]:
    baseline = artifact.get("baseline") or {}
    v3 = artifact.get("pdp_v3") or {}
    if not isinstance(v3, dict):
        return False, "missing pdp_v3"
    commit = baseline.get("commit_sha") or (v3.get(BINDING_KEY) or {}).get("commit_sha")
    run_id = baseline.get("generated_at") or (v3.get(BINDING_KEY) or {}).get("run_id")
    if not commit or not run_id:
        return False, "artifact missing baseline commit_sha or generated_at run binding"
    return verify_signature(v3, commit_sha=commit, run_id=run_id)
