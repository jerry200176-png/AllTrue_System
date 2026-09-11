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
_ROLLBACK_RE = re.compile(r"(?im)^\s*(?:[-*]\s+)?(?:\*\*)?Rollback(?:\*\*)?\s*:\s*(?:\*\*)?\s*(.+)$")
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

_WORKFLOW_ONLY_PREFIXES = (
    ".github/",
    "scripts/governance/",
    "scripts/tests/",
)

_CONTROL_PLANE_PREFIXES = (
    ".github/",
    "scripts/governance/",
    "governance/",
    "docs/governance/",
)

_CONTROL_PLANE_EXACT = {
    ".cursorrules",
    "AGENTS.md",
    "CLAUDE.md",
    "codex.md",
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
    "auth", "billing", "payment", "identity", "credential", "password",
    "secret", "token", "permission",
)
_SAFE_NON_PRODUCTION_WORKFLOWS = {
    ".github/workflows/autonomous-convergence.yml",
}

# A sensitive path is an input signal, not a decision by itself. These paths
# remain Founder-required because they are the release/control plane, an
# irreversible data boundary, or a service whose public contract is an
# entitlement/ledger mutation even when the changed lines look small.
_ALWAYS_FOUNDER_ACTIVATION_PREFIXES = (
    ".github/workflows/",
    ".github/CODEOWNERS",
    ".github/dependabot.yml",
    "backend/database/migrations/",
    "backend/app/Console/Commands/Repair",
    "backend/app/Services/Repair",
    "backend/app/Services/SessionDeduction",
    "backend/app/Services/SessionEntitlement",
    "backend/app/Services/ApprovalSessionSync",
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
_ALWAYS_FOUNDER_ACTIVATION_PATH_TERMS = (
    "credential", "password", "secret", "token", "permission",
)

# Generated release-note bundles are deployable frontend assets, but their
# historical text must not be treated as the effect of the current change.
_SEMANTIC_SCAN_EXCLUDED_PATHS = {
    "frontend/src/lib/changelogDraft.generated.js",
    "frontend/src/lib/staffUpdates.generated.js",
}

_FOUNDER_EFFECT_PATTERNS = (
    ("irreversible/data operation", re.compile(
        r"\b(?:migration|repair|restore|drop\s+table|truncate|delete\s+from|backfill|reconcile)\b",
    )),
    ("billing/ledger semantics", re.compile(
        r"\b(?:refund|ledger|writeoff|write-off|settle|capture|charge|payable|deduct|"
        r"session\s+deduction|invoice\s+total|payment\s+amount|contract\s+price|"
        r"countmodecharge|calculate(?:charge|amount|total)|recalculate)\b",
    )),
    ("authentication/privilege boundary", re.compile(
        r"(?:\bauth\s*\(|\b(?:authenticate|authentication|authorize|authorization|login|logout|password|"
        r"credential|oauth|sso|sanctum|bearer|access[_-]?token|refresh[_-]?token|"
        r"privilege|grant|revoke|assign[_-]?role|permission|"
        r"(?:link|unlink|bind|unbind|associate|merge|split)[_-]?(?:identity|guardian)|"
        r"(?:identity|guardian)[_-]?(?:link|unlink|bind|unbind|associate|merge|split))\b)",
    )),
    ("privacy/legal boundary", re.compile(
        r"\b(?:pii|personal\s+data|privacy|gdpr|legal|consent|retention)\b",
    )),
    ("runtime write", re.compile(
        r"(?:->|::)\s*(?:create(?:many)?|update(?:orcreate)?|save|delete|destroy|"
        r"forcedelete|insert|upsert|attach|detach|sync|increment|decrement)\s*\(",
    )),
)
_BROAD_MUTATION_RE = re.compile(r"\b(?:foreach|chunk(?:byid)?|each(?:byid)?|bulk|mass)\b")
_MAX_GUARDED_SENSITIVE_FILES = 3
_MAX_GUARDED_SENSITIVE_LINES = 240


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


def _semantic_runtime_patch(paths: list[str], patch: str) -> str:
    """Exclude generated historical bundles from current-effect analysis."""

    semantic_paths = [path for path in paths if path not in _SEMANTIC_SCAN_EXCLUDED_PATHS]
    return _runtime_patch(semantic_paths, patch)


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


_CSS_SELECTOR_LINE_RE = re.compile(r"^\s*[.#][a-z_][a-z0-9_-]*(?:::[a-z-]+|:[a-z-]+)?(?:[\s,>{].*)?$", re.IGNORECASE)


def _semantic_changed_code_lines(patch: str) -> str:
    """Exclude standalone CSS selectors from behavioral-effect detection.

    A selector such as ``.pp-btn-logout:focus-visible`` names a control but
    cannot change the logout/authentication operation. Keep every non-selector
    line, especially JavaScript calls such as ``logout()``, in the conservative
    semantic scan.
    """

    return "\n".join(
        line for line in _changed_code_lines(patch).splitlines()
        if not _CSS_SELECTOR_LINE_RE.fullmatch(line)
    )


def _patch_is_inspectable(paths: list[str], patch: str) -> bool:
    """Return false when a sensitive diff cannot be deterministically inspected."""

    if not patch:
        return False
    if "diff --git " not in patch:
        return bool(_changed_code_lines(patch))
    chunks = re.split(r"(?=^diff --git )", patch, flags=re.MULTILINE)
    for path in paths:
        matching = [
            chunk for chunk in chunks
            if f"diff --git a/{path} b/{path}" in chunk.split("\n", 1)[0]
        ]
        if len(matching) != 1 or not _changed_code_lines(matching[0]):
            return False
    return True


def _founder_effects(changed_code: str, *, include_runtime_write: bool = True) -> list[str]:
    effects = [label for label, pattern in _FOUNDER_EFFECT_PATTERNS if pattern.search(changed_code)]
    if not include_runtime_write:
        effects = [effect for effect in effects if effect != "runtime write"]
    if effects and _BROAD_MUTATION_RE.search(changed_code):
        effects.append("unbounded or mass mutation")
    return effects


def is_deployable_path(path: str) -> bool:
    """Return whether a changed path can alter the deployed runtime."""

    if _is_non_runtime_path(path):
        return False
    return path in _DEPLOYABLE_EXACT or path.startswith(("backend/", "frontend/", "scripts/"))


def is_application_runtime_path(path: str) -> bool:
    """Identify paths that require the deployed application revision to move.

    This reuses the deployability classifier while treating workflow and
    governance-only changes as control-plane revisions. It is intentionally
    narrower than ``is_deployable_path`` for the protected Parent Portal
    smoke provenance check.
    """

    normalized = path.replace("\\", "/")
    if normalized.startswith(_WORKFLOW_ONLY_PREFIXES):
        return False
    return is_deployable_path(normalized)


def is_control_plane_path(path: str) -> bool:
    """Identify changes effective in the delivery/control plane, not the app."""

    normalized = path.replace("\\", "/")
    return normalized in _CONTROL_PLANE_EXACT or normalized.startswith(_CONTROL_PLANE_PREFIXES)


def is_control_plane_only_paths(paths: Iterable[str]) -> bool:
    """Return whether a non-empty change has no application runtime files."""

    normalized = [str(path).replace("\\", "/") for path in paths if path]
    return bool(normalized) and any(is_control_plane_path(path) for path in normalized) and not any(
        is_application_runtime_path(path) for path in normalized
    )


def is_production_activation_sensitive_path(path: str) -> bool:
    """Return whether a path needs the protected production activation boundary."""

    normalized = path.replace("\\", "/")
    if normalized.startswith(".github/workflows/"):
        return normalized not in _SAFE_NON_PRODUCTION_WORKFLOWS
    if any(normalized.startswith(prefix) for prefix in _ACTIVATION_T3_PREFIXES):
        return True
    lowered = normalized.lower()
    path_parts = re.split(r"[/_.-]+", lowered)
    return any(
        part.startswith(term)
        for part in path_parts
        for term in _ACTIVATION_T3_PATH_TERMS
        if part
    )


def parse_declaration(body: str) -> tuple[int | None, int | None]:
    """Read the PR's explicit risk/tier declaration without trusting it."""

    risk_match = _RISK_RE.search(body or "")
    tier_match = _TIER_RE.search(body or "")
    risk = RISK_VALUES[risk_match.group(1)] if risk_match else None
    tier = TIER_VALUES[tier_match.group(1)] if tier_match else None
    return risk, tier


def has_rollback_evidence(body: str) -> bool:
    """Require a concrete rollback value rather than a template placeholder."""

    match = _ROLLBACK_RE.search(body or "")
    if not match:
        return False
    value = re.sub(r"<!--.*?-->", "", match.group(1), flags=re.DOTALL).strip().lower()
    return value not in {"", "n/a", "na", "none", "tbd", "todo", "___", "..."}


def classify_scope(paths: Iterable[str], patch: str = "") -> dict[str, object]:
    """Derive the minimum safe tier from paths and diff text."""

    normalized = [str(path).replace("\\", "/") for path in paths if path]
    # Tests, fixtures, and documentation cannot alter the deployed runtime.
    # Keep them eligible for the lightest path even when their assertions
    # mention a protected domain such as auth or billing.
    non_runtime_only = normalized and not any(is_deployable_path(path) for path in normalized)
    runtime_paths = [path for path in normalized if is_deployable_path(path)]
    marker_paths = runtime_paths or normalized
    # Release-note bundles are deployable assets, but their historical copy is
    # not evidence of the current change's runtime effect. Use the same
    # narrowed semantic input as activation classification; paths themselves
    # remain classified and executable runtime files are never excluded.
    haystack = ("\n".join(marker_paths) + "\n" + _semantic_runtime_patch(runtime_paths, patch)).lower()
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
    """Classify activation by effect, boundedness, and reversible evidence.

    ``classify_scope`` remains the conservative merge classifier. This
    activation view distinguishes routine T0/T1/T2 work from a bounded,
    read-only sensitive change that can use the existing exact-SHA, rollback,
    health, smoke, and production-verification path. Uninspectable or
    business/security/control-plane effects stay Founder-required.
    """

    normalized = [str(path).replace("\\", "/") for path in paths if path]
    runtime_paths = [path for path in normalized if is_deployable_path(path)]
    sensitive_paths = [path for path in normalized if is_production_activation_sensitive_path(path)]
    always_founder_paths = [
        path for path in sensitive_paths
        if any(path.startswith(prefix) for prefix in _ALWAYS_FOUNDER_ACTIVATION_PREFIXES)
        or any(
            part.startswith(term)
            for part in re.split(r"[/_.-]+", path.lower())
            for term in _ALWAYS_FOUNDER_ACTIVATION_PATH_TERMS
            if part
        )
    ]
    minimum = 0
    reasons: list[str] = []
    activation_class = "routine"

    semantic_patch = _semantic_runtime_patch(runtime_paths, patch)
    changed_code = _semantic_changed_code_lines(semantic_patch)
    founder_effects = _founder_effects(
        changed_code, include_runtime_write=bool(sensitive_paths)
    )

    if always_founder_paths:
        minimum = 3
        activation_class = "founder-required"
        reasons.extend(f"Founder-required production boundary: {path}" for path in always_founder_paths)
    elif sensitive_paths:
        inspectable = _patch_is_inspectable(
            [path for path in sensitive_paths if is_deployable_path(path)], patch
        )
        changed_lines = len(changed_code.splitlines()) if changed_code else 0
        if founder_effects:
            minimum = 3
            activation_class = "founder-required"
            reasons.append("Founder-required effect: " + ", ".join(founder_effects))
        elif not inspectable:
            minimum = 3
            activation_class = "founder-required"
            reasons.append("Founder-required: sensitive diff is not inspectable; fail closed")
        elif len(sensitive_paths) > _MAX_GUARDED_SENSITIVE_FILES:
            minimum = 3
            activation_class = "founder-required"
            reasons.append(
                f"Founder-required: sensitive blast radius exceeds {_MAX_GUARDED_SENSITIVE_FILES} files"
            )
        elif changed_lines > _MAX_GUARDED_SENSITIVE_LINES:
            minimum = 3
            activation_class = "founder-required"
            reasons.append(
                f"Founder-required: sensitive diff exceeds {_MAX_GUARDED_SENSITIVE_LINES} changed lines"
            )
        else:
            minimum = 2
            activation_class = "guarded-sensitive"
            reasons.extend(
                f"bounded sensitive path eligible for guarded activation: {path}"
                for path in sensitive_paths
            )
    elif runtime_paths:
        minimum = 1
        reasons.extend(f"reversible runtime path: {path}" for path in runtime_paths)

    if not sensitive_paths and founder_effects:
        minimum = max(minimum, 3)
        activation_class = "founder-required"
        reasons.append("Founder-required effect in runtime diff: " + ", ".join(founder_effects))
    elif not sensitive_paths and runtime_paths:
        matched_t2 = [marker for marker in _T2_MARKERS if marker in changed_code]
        if matched_t2:
            minimum = max(minimum, 2)
            reasons.append("product semantic marker in runtime diff: " + ", ".join(sorted(set(matched_t2))))

    return {
        "machine_minimum_tier": minimum,
        "tier_name": f"T{minimum}",
        "activation_class": activation_class,
        "guarded_sensitive": activation_class == "guarded-sensitive",
        "founder_required": activation_class == "founder-required",
        "protected_activation": activation_class == "founder-required",
        "runtime_paths": runtime_paths,
        "reasons": reasons or ["non-deployable or read-only change"],
    }


def classify_activation_provenance(records: Iterable[dict[str, object]]) -> dict[str, object]:
    """Classify an undeployed range from independently attributed merged PRs.

    Control-plane-only PRs are already effective when merged and therefore do
    not become part of a later application release's effect.  Application
    effects are still accumulated across PRs, while each PR is classified from
    its own files and patch.  Missing attribution/evidence is a hard hold.
    """

    normalized = list(records)
    if not normalized:
        return {
            "machine_minimum_tier": 3,
            "tier_name": "T3",
            "activation_class": "blocked",
            "founder_required": True,
            "protected_activation": True,
            "blocked": True,
            "reason": "no merged PR provenance for current main target; fail closed",
            "application_prs": [],
        }

    application_prs: list[dict[str, object]] = []
    minimum = 0
    blocked_reasons: list[str] = []
    declared_minimum = 0
    for record in normalized:
        number = record.get("number")
        paths = [str(path).replace("\\", "/") for path in (record.get("paths") or []) if path]
        patch = str(record.get("patch") or "")
        if not number or not paths or not record.get("merged_at"):
            blocked_reasons.append("merged PR attribution is incomplete")
            continue

        application = any(is_application_runtime_path(path) for path in paths)
        control_plane = is_control_plane_only_paths(paths)
        if not application and control_plane:
            # Its control-plane effect is effective at merge and must not raise
            # the next application release's tier.
            continue
        if not application:
            blocked_reasons.append(f"PR #{number}: provenance has no classified effect")
            continue
        if record.get("patch_complete") is not True:
            blocked_reasons.append(f"PR #{number}: application patch evidence is incomplete")
            continue

        scope = classify_activation_scope(paths, patch)
        application_prs.append({"number": str(number), "scope": scope})
        minimum = max(minimum, int(scope["machine_minimum_tier"]))

        declared_risk = record.get("declared_risk")
        declared_tier = record.get("declared_tier")
        if isinstance(declared_risk, int):
            declared_minimum = max(declared_minimum, declared_risk)
        if isinstance(declared_tier, int):
            declared_minimum = max(declared_minimum, declared_tier)
        _, declaration_error = effective_tier(
            int(scope["machine_minimum_tier"]), declared_risk, declared_tier
        )
        if declaration_error:
            blocked_reasons.append(f"PR #{number}: {declaration_error}")

    if blocked_reasons:
        return {
            "machine_minimum_tier": max(3, minimum),
            "tier_name": "T3",
            "activation_class": "blocked",
            "founder_required": True,
            "protected_activation": True,
            "blocked": True,
            "reason": "; ".join(blocked_reasons),
            "application_prs": application_prs,
        }
    if not application_prs:
        return {
            "machine_minimum_tier": 0,
            "tier_name": "T0",
            "activation_class": "control-plane-only",
            "founder_required": False,
            "protected_activation": False,
            "blocked": False,
            "reason": "merged control-plane-only PRs are effective on main",
            "application_prs": [],
        }

    return {
        "machine_minimum_tier": minimum,
        "tier_name": f"T{minimum}",
        "activation_class": "founder-required" if minimum >= 3 else "routine",
        "founder_required": minimum >= 3,
        "protected_activation": minimum >= 3,
        "blocked": False,
        "reason": "application effects classified from independently attributed PRs",
        "application_prs": application_prs,
        "declared_risk": declared_minimum,
        "declared_tier": declared_minimum,
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
    ci_success: bool = False,
    rollback_evidence: bool = False,
) -> dict[str, str]:
    """Choose automatic activation only for validated reversible changes.

    T2 is eligible for the autonomous path when exact-target CI and rollback
    readiness are complete. T3/protected paths never become automatic through
    routine evidence.
    """

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
    if effective == 2:
        missing = []
        if not ci_success:
            missing.append("successful CI")
        if not rollback_evidence:
            missing.append("rollback evidence")
        if not missing:
            return {"decision": "auto", "effective_tier": "T2", "reason": "validated reversible R2/T2 change has successful CI and rollback evidence"}
        return {"decision": "awaiting-activation", "effective_tier": "T2", "reason": "activation blocked until required evidence is satisfied: " + ", ".join(missing)}
    return {"decision": "awaiting-activation", "effective_tier": f"T{effective}", "reason": f"effective tier T{effective} requires Founder activation"}


def is_founder_approval_eligible(
    decision: dict[str, str], *, protected_activation: bool = False,
) -> bool:
    """Return whether an awaiting decision may enter the Founder Environment."""

    return decision.get("decision") == "awaiting-activation" and (
        protected_activation or decision.get("effective_tier") == "T3"
    )


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


def classify_production_runtime(
    *,
    production_sha: str,
    target_sha: str,
    comparison_status: str | None,
    provenance_sha: str | None,
    manifest_source: str | None,
) -> dict[str, object]:
    """Classify the deployed runtime relative to an exact activation target.

    A verified deployed ancestor is expected version lag while a deployment is
    pending. It must remain behind the protected activation boundary, but it
    must not be mistaken for an unexpected production fork. Any missing or
    contradictory identity evidence fails closed.
    """

    full_shas = (production_sha, target_sha)
    if any(not _FULL_SHA_RE.fullmatch(value or "") for value in full_shas):
        return {
            "state": "invalid-evidence",
            "retry_allowed": False,
            "reason": "production or target SHA is invalid",
        }
    if manifest_source != "github-actions:deploy.yml":
        return {
            "state": "provenance-unknown",
            "retry_allowed": False,
            "reason": "production manifest provenance is unknown",
        }
    if not _FULL_SHA_RE.fullmatch(provenance_sha or "") or provenance_sha != production_sha:
        return {
            "state": "provenance-unknown",
            "retry_allowed": False,
            "reason": "no successful deploy provenance matches the production SHA",
        }
    if production_sha == target_sha and comparison_status == "identical":
        return {
            "state": "current",
            "retry_allowed": False,
            "reason": "production already matches the activation target",
        }
    if production_sha != target_sha and comparison_status == "ahead":
        return {
            "state": "normal-version-lag",
            "retry_allowed": True,
            "reason": "production is a verified deployed ancestor of the target",
        }
    return {
        "state": "unexpected-production-sha",
        "retry_allowed": False,
        "reason": "production SHA is not a verified ancestor of the target",
    }


def environment_protection_is_valid(
    *, event_name: str, phase: str, required_reviewers_configured: bool,
    prevent_self_review: bool,
) -> bool:
    """Validate the single static Founder production environment boundary.

    Every protected activation event uses the same GitHub Environment
    configuration: the Founder is the required reviewer, self-review is
    allowed for this single-Founder repository, administrator bypass is checked
    by the workflow, and deployment is restricted to ``main``. Manual
    exceptional phases additionally retain their typed confirmation step in
    ``deploy.yml``. Evidence-complete reversible T2 does not call this gate.
    """

    if event_name not in {"workflow_run", "repository_dispatch", "workflow_dispatch"}:
        return False
    if event_name in {"workflow_run", "repository_dispatch"} and phase != "application-deploy":
        return False
    if event_name == "workflow_dispatch" and phase not in {
        "application-deploy", "parent-portal-smoke", "pop-bootstrap",
        "phase1-create", "phase2-cutover", "phase3-lock",
    }:
        return False
    return required_reviewers_configured and prevent_self_review is False


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
    "classify_activation_provenance",
    "classify_scope",
    "decide_activation",
    "decide_manual_activation",
    "is_founder_approval_eligible",
    "classify_production_runtime",
    "environment_protection_is_valid",
    "effective_tier",
    "has_rollback_evidence",
    "is_application_runtime_path",
    "is_control_plane_only_paths",
    "is_control_plane_path",
    "is_deployable_path",
    "is_production_activation_sensitive_path",
    "parse_declaration",
]
