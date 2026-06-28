#!/usr/bin/env python3
"""
platform-gate-assemble.py — Input collector + signer. NO policy computation.

Reads collector outputs, passes them to policy-engine.py, signs ci-artifacts/pdp.json.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
POLICY_ENGINE = REPO / "scripts" / "platform" / "policy-engine.py"
VALIDATOR = REPO / "scripts" / "platform" / "policy-contract-validator.py"
LOCK = REPO / "scripts" / "platform" / "control-plane-lock.py"
FLAGS_PATH = REPO / "services" / "feature-flags" / "flags.json"
CANONICAL_PDP = REPO / "ci-artifacts" / "pdp.json"

sys.path.insert(0, str(REPO / "scripts" / "platform"))
from pdp_signature import attach_signature, binding_from_env  # noqa: E402
from promotion_integrity import integrity_from_pdp_artifact  # noqa: E402


def parse_json_blob(text: str) -> dict:
    text = (text or "").strip()
    if not text:
        return {}
    m = re.search(r"\{[\s\S]*\}\s*$", text)
    if m:
        try:
            return json.loads(m.group(0))
        except json.JSONDecodeError:
            pass
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return {"parse_error": True}


def load_step(work: Path, name: str) -> tuple[dict, int]:
    raw = work / f"{name}.raw"
    exit_f = work / f"{name}.exit"
    code = int(exit_f.read_text().strip()) if exit_f.exists() else 1
    data = parse_json_blob(raw.read_text(encoding="utf-8") if raw.exists() else "")
    return data, code


def collect_runtime(repo: Path) -> dict:
    latest = repo / "ci-artifacts" / "runtime" / "latest.json"
    default = {"error_rate": 0.0, "latency_p95": 0, "slo_breach": False}
    if not latest.exists():
        return default
    try:
        doc = json.loads(latest.read_text(encoding="utf-8"))
        return doc.get("runtime_metrics") or default
    except (json.JSONDecodeError, OSError):
        return default


def load_flag_definitions(repo: Path) -> list[dict]:
    if not FLAGS_PATH.exists():
        return []
    try:
        return list(json.loads(FLAGS_PATH.read_text(encoding="utf-8")).get("flags") or [])
    except (json.JSONDecodeError, OSError):
        return []


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--work", required=True)
    parser.add_argument("--pr", type=int, required=True)
    parser.add_argument("--out", default="")
    parser.add_argument("--repo-root", default=str(REPO))
    args = parser.parse_args()

    work = Path(args.work)
    repo = Path(args.repo_root)

    sop, sop_code = load_step(work, "sop")
    consistency, consistency_code = load_step(work, "consistency")
    decision, decision_code = load_step(work, "decision")
    exec_r, exec_code = load_step(work, "exec")
    runtime = collect_runtime(repo)
    flag_definitions = load_flag_definitions(repo)

    pdp_input = {
        "ci_signal": {
            "sources": {
                "sop": sop,
                "consistency": consistency,
                "decision": decision,
                "exec": exec_r,
            },
        },
        "runtime_metrics": runtime,
        "flag_definitions": flag_definitions,
        "meta": {
            "step_exit_codes": {
                "sop": sop_code,
                "consistency": consistency_code,
                "decision": decision_code,
                "exec": exec_code,
            },
        },
    }
    input_path = work / "pdp-input.json"
    input_path.write_text(json.dumps(pdp_input, indent=2) + "\n", encoding="utf-8")

    pdp_raw = subprocess.check_output(
        [sys.executable, str(POLICY_ENGINE), "--input", str(input_path)],
        text=True,
    )
    pdp = json.loads(pdp_raw)

    commit_sha, run_id, branch = binding_from_env()
    pdp_v3 = attach_signature(pdp.get("pdp_v3") or {}, commit_sha=commit_sha, run_id=run_id)

    baseline = {
        "commit_sha": commit_sha,
        "branch": branch,
        "generated_at": run_id,
    }

    artifact = {
        "status": pdp.get("status", "FAIL"),
        "pr": args.pr,
        "violations": pdp.get("violations") or [],
        "risk_score": pdp.get("risk_score"),
        "sources": pdp_input["ci_signal"]["sources"],
        "runtime_metrics": runtime,
        "pdp_v3": pdp_v3,
        "baseline": baseline,
        "promotion_integrity": None,
        "meta": {
            **pdp_input.get("meta", {}),
            "schema_version": "0.3",
            "phase": "control-plane-runtime",
            "policy_version": pdp.get("policy_version", "pdp-v3"),
            "decision_reason": pdp.get("decision_reason", ""),
            "pdp_v3_bound_signature": pdp_v3.get("_signature"),
            "pdp_content_hash": pdp_v3.get("_content_hash"),
        },
    }
    artifact["promotion_integrity"] = integrity_from_pdp_artifact(artifact)

    sys.path.insert(0, str(REPO / "scripts" / "platform"))
    from pdp_signing_authority import sign_artifact  # noqa: E402

    artifact = sign_artifact(artifact)

    text = json.dumps(artifact, indent=2, ensure_ascii=False) + "\n"

    out_path = Path(args.out) if args.out else None
    if out_path:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(text, encoding="utf-8")
        proc = subprocess.run(
            [sys.executable, str(VALIDATOR), "--input", str(out_path)],
            capture_output=True,
            text=True,
        )
        if proc.returncode != 0:
            print(proc.stdout or proc.stderr, file=sys.stderr)
            sys.exit(1)

    CANONICAL_PDP.parent.mkdir(parents=True, exist_ok=True)
    CANONICAL_PDP.write_text(text, encoding="utf-8")
    lock = subprocess.run([sys.executable, str(LOCK), str(CANONICAL_PDP)], capture_output=True, text=True)
    if lock.returncode != 0:
        print(lock.stdout or lock.stderr, file=sys.stderr)
        sys.exit(1)

    print(json.dumps(artifact, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
