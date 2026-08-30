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


def _path_matches(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def is_deployable_path(path: str) -> bool:
    """Return whether a changed path can alter the deployed runtime."""

    if any(_path_matches(path, pattern) for pattern in _NON_DEPLOYABLE_PATTERNS):
        return False
    return path in _DEPLOYABLE_EXACT or path.startswith(("backend/", "frontend/", "scripts/"))


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
    haystack = ("\n".join(normalized) + "\n" + (patch or "")).lower()
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
    "classify_scope",
    "effective_tier",
    "is_deployable_path",
    "parse_declaration",
]
