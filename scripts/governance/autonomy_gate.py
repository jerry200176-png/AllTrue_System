"""Deterministic risk and deployability classification for autonomous delivery.

The classifier is intentionally conservative.  It derives a minimum tier from
the changed paths and patch text; a PR declaration may raise that tier but can
never lower it.  The caller is responsible for treating missing or invalid
evidence as a hold.
"""

from __future__ import annotations

import fnmatch
import re
from typing import Iterable


TIER_VALUES = {"T0": 0, "T1": 1, "T2": 2, "T3": 3}
RISK_VALUES = {"R0": 0, "R1": 1, "R2": 2, "R3": 3}

_RISK_RE = re.compile(r"(?im)^\s*(?:[-*]\s*)?\*?\*?Risk-Class\*?\*?\s*:\s*\*?\*?\s*(R[0-3])\b")
_TIER_RE = re.compile(r"(?im)^\s*(?:[-*]\s*)?\*?\*?Autonomy-Tier\*?\*?\s*:\s*\*?\*?\s*(T[0-3])\b")
_FULL_SHA_RE = re.compile(r"^[0-9a-f]{40}$")

_T3_PREFIXES = (
    ".github/workflows/",
    ".github/CODEOWNERS",
    ".github/dependabot.yml",
    "backend/database/migrations/",
    "backend/app/Console/Commands/Repair",
    "backend/app/Services/Repair",
    "backend/app/Services/SessionDeduction",
    "backend/app/Services/SessionEntitlement",
    "backend/app/Services/ApprovalSessionSync",
    "backend/app/Http/Controllers/Alert",
    "backend/app/Http/Controllers/PaymentReport",
    "backend/app/Http/Controllers/Billing",
    "backend/app/Http/Controllers/SwipeRfid",
    "backend/app/Http/Controllers/StudentIdentity",
    "backend/app/Http/Controllers/Auth",
    "backend/app/Http/Middleware/",
    "backend/routes/api.php",
    "scripts/ops/",
    "scripts/production",
    "governance/",
    "docs/governance/",
    ".cursorrules",
    "AGENTS.md",
    "CLAUDE.md",
    "codex.md",
)

_T2_PREFIXES = (
    "backend/",
    "composer.json",
    "composer.lock",
    "frontend/package.json",
    "frontend/package-lock.json",
    "scripts/",
)

_NON_DEPLOYABLE_PATTERNS = (
    "backend/tests/**",
    "frontend/e2e/**",
    "frontend/**/__tests__/**",
    "frontend/**/*.test.js",
    "frontend/**/*.test.ts",
    "frontend/**/*.test.tsx",
    "scripts/tests/**",
    "scripts/ci/**",
    "docs/**",
    ".cursor/**",
    "operations/closeout/**",
)

_DEPLOYABLE_EXACT = {
    "composer.json",
    "composer.lock",
    ".github/workflows/deploy.yml",
}

_T3_MARKERS = (
    "billing",
    "payment",
    "invoice",
    "entitlement",
    "session deduction",
    "auth",
    "authorization",
    "permission",
    "identity",
    "credential",
    "password",
    "secret",
    "token",
    "migration",
    "repair",
    "restore",
    "drop table",
    "delete from",
    "production-activation",
)

_T2_MARKERS = (
    "schedule",
    "attendance",
    "classsession",
    "cross-campus",
    "webhook",
    "cron",
)

_ACTIVATION_T3_PREFIXES = tuple(
    prefix for prefix in _T3_PREFIXES
    if prefix not in {".github/workflows/", "governance/", "docs/governance/"}
)
_ACTIVATION_T3_PATH_TERMS = (
    "/auth/", "/billing/", "/payment", "/identity", "/credential",
    "/password", "/secret", "/token", "/permission",
)
_SAFE_NON_PRODUCTION_WORKFLOWS = {
    ".github/workflows/autonomous-convergence.yml",
}


def _path_matches(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def _is_non_runtime_path(path: str) -> bool:
    """Match test/evidence paths explicitly; fnmatch's ** is not recursive."""

    normalized = path.replace("\\", "/")
    parts = normalized.split("/")
    return (
        any(_path_matches(normalized, pattern) for pattern in _NON_DEPLOYABLE_PATTERNS)
        or
        normalized.startswith(("backend/tests/", "frontend/e2e/", "scripts/tests/", "scripts/ci/"))
        or "__tests__" in parts
        or any(part.endswith((".test.js", ".test.ts", ".test.tsx")) for part in parts)
        or normalized.startswith(("docs/", ".cursor/", "operations/closeout/"))
    )


def _runtime_patch(paths: list[str], patch: str) -> str:
    """Keep semantic-marker scans scoped to deployable file diffs."""

    if not patch or "diff --git " not in patch:
        return patch or ""
    allowed = set(paths)
    chunks = re.split(r"(?=^diff --git )", patch, flags=re.MULTILINE)
    return "\n".join(
        chunk for chunk in chunks
        if any(f"diff --git a/{path} b/{path}" in chunk.split("\n", 1)[0] for path in allowed)
    )


def _changed_code_lines(patch: str) -> str:
    lines = []
    for line in (patch or "").splitlines():
        if not line.startswith(("+", "-")) or line.startswith(("+++", "---")):
            continue
        code = line[1:].lstrip()
        if code.startswith(("#", "//", "/*", "*", "<!--", "-->", "<!--")):
            continue
        lines.append(code)
    return "\n".join(lines).lower()


def is_deployable_path(path: str) -> bool:
    """Return whether a changed path can alter the deployed runtime."""

    if _is_non_runtime_path(path):
        return False
    return path in _DEPLOYABLE_EXACT or path.startswith(("backend/", "frontend/", "scripts/"))


def is_production_activation_sensitive_path(path: str) -> bool:
    """Return whether a path needs the protected production activation boundary."""

    normalized = path.replace("\\", "/")
    if normalized.startswith(".github/workflows/"):
        return normalized not in _SAFE_NON_PRODUCTION_WORKFLOWS
    if any(normalized.startswith(prefix) for prefix in _ACTIVATION_T3_PREFIXES):
        return True
    lowered = f"/{normalized.lower()}/"
    return any(term in lowered for term in _ACTIVATION_T3_PATH_TERMS)


def parse_declaration(body: str) -> tuple[int | None, int | None]:
    """Read the PR's explicit risk/tier declaration without trusting it."""

    risk_match = _RISK_RE.search(body or "")
    tier_match = _TIER_RE.search(body or "")
    risk = RISK_VALUES[risk_match.group(1)] if risk_match else None
    tier = TIER_VALUES[tier_match.group(1)] if tier_match else None
    return risk, tier


def classify_scope(paths: Iterable[str], patch: str = "") -> dict[str, object]:
    """Derive the minimum safe tier from paths and diff text."""

    normalized = [str(path).replace("\\", "/") for path in paths if path]
    # Tests, fixtures, and documentation cannot alter the deployed runtime.
    # Keep them eligible for the lightest path even when their assertions
    # mention a protected domain such as auth or billing.
    non_runtime_only = normalized and not any(is_deployable_path(path) for path in normalized)
    runtime_paths = [path for path in normalized if is_deployable_path(path)]
    marker_paths = runtime_paths or normalized
    haystack = ("\n".join(marker_paths) + "\n" + _runtime_patch(runtime_paths, patch)).lower()
    minimum = 0
    reasons: list[str] = []

    for path in normalized:
        if any(path.startswith(prefix) for prefix in _T3_PREFIXES):
            minimum = max(minimum, 3)
            reasons.append(f"protected path: {path}")
        elif not non_runtime_only and path.startswith("frontend/src/"):
            minimum = max(minimum, 1)
            reasons.append(f"frontend runtime path: {path}")
        elif not non_runtime_only and any(path.startswith(prefix) for prefix in _T2_PREFIXES):
            minimum = max(minimum, 2)
            reasons.append(f"product/runtime path: {path}")

    if not non_runtime_only:
        matched_t3 = [marker for marker in _T3_MARKERS if marker in haystack]
        if matched_t3:
            minimum = max(minimum, 3)
            reasons.append("protected semantic marker: " + ", ".join(sorted(set(matched_t3))))
        else:
            matched_t2 = [marker for marker in _T2_MARKERS if marker in haystack]
            if matched_t2:
                minimum = max(minimum, 2)
                reasons.append("product semantic marker: " + ", ".join(sorted(set(matched_t2))))

    if not normalized:
        minimum = 3
        reasons.append("empty scope")

    return {
        "machine_minimum_tier": minimum,
        "tier_name": f"T{minimum}",
        "reasons": reasons or ["documentation or generated evidence only"],
    }


def classify_activation_scope(paths: Iterable[str], patch: str = "") -> dict[str, object]:
    """Classify only changes that can require a production application activation.

    Merge safety remains conservative in ``classify_scope``.  This second view
    prevents read-only CI/governance metadata from forcing a Pi deploy through
    the Founder review environment, while keeping true production side effects
    protected.
    """

    normalized = [str(path).replace("\\", "/") for path in paths if path]
    runtime_paths = [path for path in normalized if is_deployable_path(path)]
    sensitive_paths = [path for path in normalized if is_production_activation_sensitive_path(path)]
    minimum = 0
    reasons: list[str] = []

    if sensitive_paths:
        minimum = 3
        reasons.extend(f"protected production activation path: {path}" for path in sensitive_paths)
    elif runtime_paths:
        minimum = 1
        reasons.extend(f"reversible runtime path: {path}" for path in runtime_paths)

    changed_code = _changed_code_lines(_runtime_patch(runtime_paths, patch))
    matched_t3 = [marker for marker in _T3_MARKERS if marker in changed_code]
    if matched_t3:
        minimum = max(minimum, 3)
        reasons.append("protected semantic marker in runtime diff: " + ", ".join(sorted(set(matched_t3))))
    elif runtime_paths:
        matched_t2 = [marker for marker in _T2_MARKERS if marker in changed_code]
        if matched_t2:
            minimum = max(minimum, 2)
            reasons.append("product semantic marker in runtime diff: " + ", ".join(sorted(set(matched_t2))))

    return {
        "machine_minimum_tier": minimum,
        "tier_name": f"T{minimum}",
        "protected_activation": bool(sensitive_paths or matched_t3),
        "runtime_paths": runtime_paths,
        "reasons": reasons or ["non-deployable or read-only change"],
    }


def decide_activation(
    *,
    event_name: str,
    deployable: bool,
    classifier_available: bool,
    machine_validated: bool,
    machine_tier: str | None = None,
    declared_risk: str | None = None,
    declared_tier: str | None = None,
    protected_activation: bool = False,
) -> dict[str, str]:
    """Choose automatic activation only for validated reversible changes."""

    if not deployable:
        return {"decision": "no-op", "effective_tier": "none", "reason": "non-deployable change"}
    if event_name == "workflow_dispatch":
        return {"decision": "manual", "effective_tier": "protected", "reason": "manual activation requires the production environment gate"}
    if not classifier_available or not machine_validated or machine_tier not in TIER_VALUES:
        return {"decision": "awaiting-activation", "effective_tier": "unknown", "reason": "activation classifier evidence unavailable; fail closed"}
    if declared_risk not in RISK_VALUES or declared_tier not in TIER_VALUES:
        return {"decision": "awaiting-activation", "effective_tier": f"T{TIER_VALUES.get(machine_tier, 3)}", "reason": "missing or invalid risk/tier declaration; fail closed"}
    if RISK_VALUES[declared_risk] != TIER_VALUES[declared_tier]:
        return {"decision": "awaiting-activation", "effective_tier": f"T{max(RISK_VALUES[declared_risk], TIER_VALUES[declared_tier])}", "reason": "Risk-Class and Autonomy-Tier do not match; fail closed"}
    if TIER_VALUES[declared_tier] < TIER_VALUES[machine_tier]:
        return {"decision": "awaiting-activation", "effective_tier": machine_tier, "reason": "declared tier is below the machine-derived minimum; fail closed"}
    effective = max(TIER_VALUES[declared_tier], TIER_VALUES[machine_tier])
    if protected_activation:
        return {"decision": "awaiting-activation", "effective_tier": f"T{effective}", "reason": "production-side-effect boundary requires protected activation"}
    if effective in (0, 1):
        return {"decision": "auto", "effective_tier": f"T{effective}", "reason": f"validated reversible {declared_risk}/{declared_tier} change is auto-deployable"}
    return {"decision": "awaiting-activation", "effective_tier": f"T{effective}", "reason": f"effective tier T{effective} requires Founder activation"}


def decide_manual_activation(
    *, workflow_ref: str, target_sha: str, current_main_sha: str,
    ci_success: bool, founder_gate_reached: bool,
) -> dict[str, str]:
    """Validate a protected manual activation without performing production work."""

    if workflow_ref != "refs/heads/main":
        return {"decision": "rejected", "reason": "manual application activation must run from refs/heads/main"}
    if not _FULL_SHA_RE.fullmatch(target_sha or ""):
        return {"decision": "rejected", "reason": "activation requires a full target SHA"}
    if target_sha != current_main_sha:
        return {"decision": "rejected", "reason": "target SHA is not the current main SHA"}
    if not ci_success:
        return {"decision": "rejected", "reason": "exact target SHA has no successful main CI"}
    if not founder_gate_reached:
        return {"decision": "awaiting-founder-approval", "reason": "production environment approval is required"}
    return {"decision": "activation-gate-reached", "reason": "Founder-approved exact-main activation may proceed"}


def environment_protection_is_valid(
    *, event_name: str, phase: str, required_reviewers_configured: bool,
    prevent_self_review: bool,
) -> bool:
    """Validate the solo-Founder production environment boundary.

    Protected actions still require an explicit workflow dispatch and exact
    typed confirmation. In solo mode, a required reviewer is an
    unsatisfiable self-approval queue, so the Environment must not carry that
    rule. If a reviewer rule is reintroduced, fail closed regardless of its
    self-review setting.
    """

    if event_name != "workflow_dispatch":
        return False
    if phase not in {"application-deploy", "phase1-create", "phase2-cutover", "phase3-lock"}:
        return False
    if required_reviewers_configured:
        return False
    return prevent_self_review is False


def effective_tier(
    machine_minimum_tier: int,
    declared_risk: int | None,
    declared_tier: int | None,
) -> tuple[int | None, str | None]:
    """Validate declarations and return the effective numeric tier."""

    if machine_minimum_tier not in range(4):
        return None, "machine-derived tier is invalid"
    if declared_risk not in range(4) or declared_tier not in range(4):
        return None, "missing or invalid risk/tier declaration"
    if declared_risk != declared_tier:
        return None, "Risk-Class and Autonomy-Tier do not match"
    if declared_tier < machine_minimum_tier:
        return None, "declared tier is below the machine-derived minimum"
    return max(machine_minimum_tier, declared_tier), None


__all__ = [
    "classify_activation_scope",
    "classify_scope",
    "decide_activation",
    "decide_manual_activation",
    "environment_protection_is_valid",
    "effective_tier",
    "is_deployable_path",
    "is_production_activation_sensitive_path",
    "parse_declaration",
]
