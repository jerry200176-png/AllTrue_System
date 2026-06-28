#!/usr/bin/env python3
"""Drift nullification — detect secondary policy decision engines (validator only)."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]

ALLOWLIST = {
    "scripts/platform/policy-engine.py",
    "scripts/platform/pdp_signature.py",
    "scripts/platform/drift-nullifier.py",
    "scripts/platform/control-plane-lock.py",
    "scripts/platform/pdp-read.py",
    "scripts/platform/promotion-check.py",
    "scripts/platform/promotion-enforce.py",
    "scripts/platform/platform-gate-assemble.py",
    "scripts/platform/runtime-pdp-artifact.py",
    "scripts/platform/policy-contract-validator.py",
    "scripts/platform/policy-fork-detector.py",
    "scripts/platform/pdp-replay-guard.py",
    "scripts/platform/git-policy-audit.py",
    "scripts/platform/pdp-exec-gate.sh",
    "scripts/platform/require-pdp-production-deploy.sh",
    "scripts/platform/verify-promotion-integrity.sh",
    "scripts/platform/verify-github-checks.sh",
    "scripts/platform/apply-platform-enforcement.sh",
    "scripts/platform/promotion_integrity.py",
    "scripts/platform/github-enforcement-attestation.sh",
    "scripts/platform/github_enforcement_attestation.py",
    "scripts/platform/pdp_signing_authority.py",
    "scripts/platform/verify-pdp-signature.sh",
    ".github/workflows/deploy.yml",
    ".github/workflows/deploy-production.yml",
    "scripts/platform/test_control_plane_attacks.py",
    "services/feature-flags/flag-api.py",
    "scripts/runtime/metrics-collector.py",
    "docs/pdp-contract.md",
    "docs/github-ruleset-enforcement.md",
}

PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    ("risk_score_threshold", re.compile(r"risk_score\s*>\s*[\d.]+", re.I)),
    ("hardcoded_24h", re.compile(r"(timedelta\(hours\s*=\s*24\)|hours\s*<\s*24|1440)", re.I)),
    ("deploy_approved_bypass", re.compile(r"Deploy-Approved|deploy_approved", re.I)),
    ("yaml_block_merge_logic", re.compile(r"block_merge\s*=\s*[^$].*pdp", re.I)),
    ("php_hash_rollout", re.compile(r"crc32\s*\(", re.I)),
    ("js_local_flag_eval", re.compile(r"hash\s*\(.*\)\s*%\s*100", re.I)),
    ("legacy_pdp_v2", re.compile(r'"pdp"\s*:\s*\{[^}]*block_merge', re.I)),
    ("derived_pdp_authority", re.compile(r"pdp-runtime-snapshot|pdp-baseline\.json", re.I)),
    ("hash_rollout_bypass", re.compile(r"hash\s*\([^)]+\)\s*%\s*100", re.I)),
    ("legacy_ci_auto_deploy", re.compile(r'workflows:\s*\[\s*"CI — PHPUnit Tests"\s*\]', re.I)),
]


def scan_file(path: Path) -> list[str]:
    rel = path.relative_to(REPO).as_posix()
    if rel in ALLOWLIST:
        return []
    if "node_modules" in rel or "vendor/" in rel or ".git/" in rel:
        return []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    hits = []
    for name, pat in PATTERNS:
        if pat.search(text):
            hits.append(name)
    return hits


def main() -> None:
    blocked: dict[str, list[str]] = {}
    for glob in (
        ".github/workflows/*.yml",
        "scripts/platform/*.py",
        "scripts/platform/*.sh",
        "frontend/src/**/*.js",
        "frontend/src/**/*.vue",
        "backend/app/**/*.php",
    ):
        for path in REPO.glob(glob):
            if not path.is_file():
                continue
            reasons = scan_file(path)
            if reasons:
                blocked[path.relative_to(REPO).as_posix()] = reasons

    drift = bool(blocked)
    report = {
        "drift_detected": drift,
        "blocked_files": sorted(blocked.keys()),
        "details": blocked,
        "auto_fix_recommendation": [
            "Route all decisions through policy-engine.py pdp_v3",
            "Use pdp-read.py / promotion-enforce.py in workflows only",
            "Remove Deploy-Approved and manual approval gates",
        ] if drift else [],
    }
    print(json.dumps(report, indent=2))
    sys.exit(1 if drift else 0)


if __name__ == "__main__":
    main()
