"""Deterministic risk and deployability classification for autonomous delivery.

The classifier is intentionally conservative.  It derives a minimum tier from
the changed paths and patch text; a PR declaration may raise that tier but can
never lower it.  The caller is responsible for treating missing or invalid
evidence as a hold.
"""

from __future__ import annotations

import fnmatch
import re
from collections import Counter
from datetime import datetime
from typing import Iterable, Mapping


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
    "frontend/playwright*.config.js",
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
    """Return the net semantic lines of a unified diff.

    A line which is present on both sides of a hunk is context moved by a
    neighbouring edit, not an effect of the change.  Keeping both copies made
    unrelated markers (for example an existing attendance matcher) escalate a
    display-only change.  Only cancel exact added/removed pairs; unmatched
    additions and removals remain conservative classification evidence.
    """

    changes: list[tuple[str, str]] = []
    for line in (patch or "").splitlines():
        if not line.startswith(("+", "-")) or line.startswith(("+++", "---")):
            continue
        code = line[1:].lstrip()
        if code.startswith(("#", "//", "/*", "*", "<!--", "-->", "<!--")):
            continue
        changes.append((line[0], code.lower()))

    additions = Counter(code for sign, code in changes if sign == "+")
    removals = Counter(code for sign, code in changes if sign == "-")
    cancelled = {code: min(additions[code], removals[code]) for code in additions.keys() & removals.keys()}
    seen: Counter[tuple[str, str]] = Counter()
    lines: list[str] = []
    for sign, code in changes:
        if seen[(sign, code)] < cancelled.get(code, 0):
            seen[(sign, code)] += 1
            continue
        seen[(sign, code)] += 1
        lines.append(code)
    return "\n".join(lines)


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
    # Test and evidence files never run in production.  Their filenames may
    # legitimately contain domain words (for example ``Authoritative...``)
    # that share a prefix with a protected path term such as ``auth``.  They
    # are regression evidence, not an activation effect.
    if _is_non_runtime_path(normalized):
        return False
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
    # A unified diff contains unchanged context lines.  Those lines explain a
    # nearby edit but are not an effect of this PR; classifying them as one can
    # incorrectly turn a copy-only UI change next to a payment field into T3.
    # Paths remain an independent conservative signal, while semantic markers
    # are limited to added/removed executable lines in deployable files.
    semantic_patch = _semantic_runtime_patch(runtime_paths, patch)
    semantic_effect = (
        _semantic_changed_code_lines(semantic_patch)
        if "diff --git " in semantic_patch
        else semantic_patch
    )
    haystack = ("\n".join(marker_paths) + "\n" + semantic_effect).lower()
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


def _parse_timestamp(value: object) -> datetime | None:
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def _full_sha(value: object) -> str | None:
    candidate = str(value or "").strip().lower()
    return candidate if _FULL_SHA_RE.fullmatch(candidate) else None


def _file_evidence(files: Iterable[Mapping[str, object]]) -> dict[str, tuple[object, ...]] | None:
    normalized: dict[str, tuple[object, ...]] = {}
    for item in files:
        path = str(item.get("filename") or "").replace("\\", "/")
        patch = item.get("patch")
        if not path or patch is None or path in normalized:
            return None
        normalized[path] = (
            item.get("status"),
            item.get("additions"),
            item.get("deletions"),
            item.get("changes"),
            str(patch),
        )
    return normalized


def aggregate_landed_pr_effects(
    commit_effects: Iterable[Mapping[str, object]],
) -> list[dict[str, object]]:
    """Aggregate actual landed commit effects by their merged PR.

    A PR's historical branch file list can contain work that landed through a
    different PR while the branch was being rebased or integrated.  Activation
    classification must therefore consume only the commit effects in the
    undeployed main range.  The caller supplies those commit effects in main
    order; this helper preserves patch order while deterministically unioning
    their changed paths for each PR.
    """

    grouped: dict[str, dict[str, object]] = {}
    for effect in commit_effects:
        number = str(effect.get("number") or "").strip()
        if not number:
            continue
        group = grouped.setdefault(
            number,
            {
                "number": number,
                "merged_at": effect.get("merged_at"),
                "declared_risk": effect.get("declared_risk"),
                "declared_tier": effect.get("declared_tier"),
                "paths": [],
                "patch_parts": [],
                "patch_complete": True,
                "metadata_consistent": True,
            },
        )
        for key in ("merged_at", "declared_risk", "declared_tier"):
            if group[key] != effect.get(key):
                group["metadata_consistent"] = False
        paths = effect.get("paths")
        if not isinstance(paths, list):
            group["patch_complete"] = False
        else:
            group["paths"].extend(str(path).replace("\\", "/") for path in paths if path)
        patch = effect.get("patch")
        if not isinstance(patch, str):
            group["patch_complete"] = False
        else:
            commit_sha = str(effect.get("commit_sha") or "").strip()
            prefix = f"commit {commit_sha}\n" if commit_sha else ""
            group["patch_parts"].append(prefix + patch)
        if effect.get("patch_complete") is not True:
            group["patch_complete"] = False

    aggregated = []
    for number, group in grouped.items():
        aggregated.append({
            "number": number,
            "merged_at": group["merged_at"],
            "paths": sorted(set(group["paths"])),
            "patch": "\n".join(group["patch_parts"]),
            "patch_complete": bool(group["patch_complete"] and group["metadata_consistent"]),
            "declared_risk": group["declared_risk"] if group["metadata_consistent"] else None,
            "declared_tier": group["declared_tier"] if group["metadata_consistent"] else None,
        })
    return aggregated


def _required_check_contract(value: object) -> list[tuple[str, int | None]] | None:
    if not isinstance(value, list) or not value:
        return None
    contract: list[tuple[str, int | None]] = []
    seen: set[str] = set()
    for item in value:
        if not isinstance(item, Mapping):
            return None
        context = str(item.get("context") or "").strip()
        if not context or context in seen:
            return None
        integration_id = item.get("integration_id")
        if integration_id is not None:
            try:
                integration_id = int(integration_id)
            except (TypeError, ValueError):
                return None
            if integration_id < 0:
                return None
        seen.add(context)
        contract.append((context, integration_id))
    return contract


def _check_evidence_is_green(check_evidence: Mapping[str, object]) -> bool:
    required = _required_check_contract(check_evidence.get("required_status_checks"))
    check_runs = check_evidence.get("check_runs")
    statuses = check_evidence.get("statuses")
    if required is None or not isinstance(check_runs, list) or not isinstance(statuses, list) or not check_runs:
        return False
    if check_evidence.get("check_runs_total") != len(check_runs):
        return False
    if check_evidence.get("statuses_total") != len(statuses):
        return False
    if any(
        not isinstance(item, Mapping)
        or item.get("status") != "completed"
        or item.get("conclusion") not in {"success", "neutral", "skipped"}
        for item in check_runs
    ):
        return False
    if any(
        not isinstance(item, Mapping) or item.get("state") != "success"
        for item in statuses
    ):
        return False

    for context, integration_id in required:
        matches = []
        for item in check_runs:
            if not isinstance(item, Mapping) or item.get("name") != context:
                continue
            app = item.get("app")
            app_id = item.get("app_id")
            if app_id is None and isinstance(app, Mapping):
                app_id = app.get("id")
            if integration_id is not None:
                try:
                    if int(app_id) != integration_id:
                        continue
                except (TypeError, ValueError):
                    continue
            matches.append(item)
        if matches:
            continue
        if integration_id is None and any(
            isinstance(item, Mapping) and item.get("context") == context
            for item in statuses
        ):
            continue
        return False
    return True


def _already_integrated_closeout(
    comments: Iterable[Mapping[str, object]],
    *,
    target_sha: str,
    head_sha: str,
    target_timestamp: datetime,
) -> bool:
    marker = re.compile(r"(?:closing|closed)\s+as\s+already\s+integrated|already\s+integrated|已整合", re.IGNORECASE)
    rejected = re.compile(
        r"review\s+rejection|product\s+rejection|security\s+finding|ci\s+(?:failure|failed)|rejected|遭拒|退回",
        re.IGNORECASE,
    )
    target_prefix = target_sha[:8]
    head_prefix = head_sha[:8]
    for comment in comments:
        if str(comment.get("author_association") or "").upper() not in {
            "OWNER", "MEMBER", "COLLABORATOR",
        }:
            continue
        body = str(comment.get("body") or "")
        created_at = _parse_timestamp(comment.get("created_at"))
        if (
            marker.search(body)
            and not rejected.search(body)
            and target_prefix in body.lower()
            and head_prefix in body.lower()
            and created_at is not None
            and created_at >= target_timestamp
        ):
            return True
    return False


def reconcile_preexisting_pr_provenance(
    *,
    target_commit: Mapping[str, object],
    pr: Mapping[str, object],
    pr_files: Iterable[Mapping[str, object]],
    target_files: Iterable[Mapping[str, object]],
    pr_head_commit: Mapping[str, object],
    check_evidence: Mapping[str, object],
    closeout_comments: Iterable[Mapping[str, object]],
) -> dict[str, object]:
    """Recover only independently provable, pre-existing PR provenance.

    This path is for a PR whose exact implementation reached main through a
    direct commit before GitHub recorded a merge.  It never synthesizes
    ``merged_at`` and returns an explicit ``reconciled`` record only after
    time, tree, file/patch, check, declaration, review, and closeout evidence
    all pass.  Commit-message PR references are only candidate locators; they
    are never validation evidence.
    """

    def reject(reason: str) -> dict[str, object]:
        return {"accepted": False, "reason": reason, "record": None}

    target_sha = _full_sha(target_commit.get("sha"))
    head_sha = _full_sha((pr.get("head") or {}).get("sha") if isinstance(pr.get("head"), Mapping) else None)
    if not target_sha or not head_sha:
        return reject("target or PR head SHA is invalid")
    if pr.get("state") != "closed" or pr.get("merged_at"):
        return reject("PR is not closed without a merge timestamp")
    base = pr.get("base")
    if not isinstance(base, Mapping) or base.get("ref") != "main":
        return reject("PR base is not main")

    target_timestamp = _parse_timestamp(target_commit.get("committer_date"))
    head_timestamp = _parse_timestamp(pr_head_commit.get("committer_date"))
    created_at = _parse_timestamp(pr.get("created_at"))
    closed_at = _parse_timestamp(pr.get("closed_at"))
    if not target_timestamp or not head_timestamp or not created_at or not closed_at:
        return reject("timestamp evidence is incomplete")
    if created_at >= target_timestamp:
        return reject("PR was created after the target commit")
    if head_timestamp > target_timestamp:
        return reject("PR head was not present before the target commit")
    if closed_at < target_timestamp:
        return reject("PR closed before the target commit")

    target_parents = target_commit.get("parents")
    head_parents = pr_head_commit.get("parents")
    if not isinstance(target_parents, list) or not isinstance(head_parents, list) or len(target_parents) != 1 or len(head_parents) != 1:
        return reject("single-parent commit evidence is required")
    target_parent = _full_sha((target_parents[0] or {}).get("sha")) if isinstance(target_parents[0], Mapping) else None
    head_parent = _full_sha((head_parents[0] or {}).get("sha")) if isinstance(head_parents[0], Mapping) else None
    if not target_parent or target_parent != head_parent:
        return reject("target and PR head do not share the same parent")

    target_tree = _full_sha((target_commit.get("tree") or {}).get("sha") if isinstance(target_commit.get("tree"), Mapping) else None)
    head_tree = _full_sha((pr_head_commit.get("tree") or {}).get("sha") if isinstance(pr_head_commit.get("tree"), Mapping) else None)
    if not target_tree or target_tree != head_tree:
        return reject("target and PR head trees are not identical")

    target_files_map = _file_evidence(target_files)
    pr_files_map = _file_evidence(pr_files)
    if not target_files_map or not pr_files_map or target_files_map != pr_files_map:
        return reject("changed-file set or patch evidence is not exact-equivalent")

    if not _check_evidence_is_green(check_evidence):
        return reject("PR head required checks are not completely green")
    if not _already_integrated_closeout(
        closeout_comments,
        target_sha=target_sha,
        head_sha=head_sha,
        target_timestamp=target_timestamp,
    ):
        return reject("already-integrated closeout evidence is missing")

    paths = sorted(target_files_map)
    patch = "\n".join(
        f"diff --git a/{path} b/{path}\n+++ b/{path}\n{target_files_map[path][-1]}"
        for path in paths
    )
    scope = classify_activation_scope(paths, patch)
    declared_risk, declared_tier = parse_declaration(str(pr.get("body") or ""))
    if declared_risk not in {0, 1} or declared_tier not in {0, 1} or declared_risk != declared_tier:
        return reject("only matching R0/T0 or R1/T1 declarations are recoverable")
    if int(scope["machine_minimum_tier"]) > 1 or bool(scope["founder_required"]):
        return reject("machine classification is outside the reversible R0/R1 boundary")
    if any(is_production_activation_sensitive_path(path) for path in paths):
        return reject("changed paths cross a protected activation boundary")

    return {
        "accepted": True,
        "reason": "pre-existing exact-equivalent PR accepted as reconciled",
        "record": {
            "number": str(pr.get("number") or ""),
            "provenance_state": "reconciled",
            "reconciliation_basis": "pre-existing-exact-equivalent",
            "paths": paths,
            "patch": patch,
            "patch_complete": True,
            "declared_risk": declared_risk,
            "declared_tier": declared_tier,
            "reconciliation_evidence": {
                "target_sha": target_sha,
                "pr_head_sha": head_sha,
                "target_tree_sha": target_tree,
                "pr_head_tree_sha": head_tree,
                "target_commit_at": target_timestamp.isoformat(),
                "pr_created_at": created_at.isoformat(),
            },
        },
    }


def classify_activation_provenance(records: Iterable[dict[str, object]]) -> dict[str, object]:
    """Classify an undeployed range from independently attributed PRs.

    Control-plane-only PRs are already effective when merged and therefore do
    not become part of a later application release's effect.  Application
    effects are still accumulated across PRs, while each PR is classified from
    its own files and patch.  A separately validated pre-existing exact-
    equivalent record may be marked ``reconciled``; missing or invalid
    attribution/evidence remains a hard hold.
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
        provenance_state = record.get("provenance_state", "merged")
        if not number or not paths:
            blocked_reasons.append("PR attribution is incomplete")
            continue
        if provenance_state == "merged":
            if not record.get("merged_at"):
                blocked_reasons.append("merged PR attribution is incomplete")
                continue
        elif provenance_state == "reconciled":
            if record.get("reconciliation_basis") != "pre-existing-exact-equivalent":
                blocked_reasons.append(f"PR #{number}: reconciled provenance basis is invalid")
                continue
        else:
            blocked_reasons.append(f"PR #{number}: unknown provenance state")
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
        application_prs.append({
            "number": str(number),
            "provenance_state": provenance_state,
            "scope": scope,
        })
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


def wait_for_exact_successful_provenance(
    *, lookup, expected_sha: str, attempts: int = 7, interval_seconds: int = 10, sleep_fn=None,
) -> str | None:
    """Bound a same-SHA deployment-provenance visibility retry to 60 seconds.

    The caller supplies the authoritative completed-success lookup. This helper
    never treats a manifest, a wrong SHA, or an API error represented as None
    as deployment proof.
    """

    if not _FULL_SHA_RE.fullmatch(expected_sha or ""):
        return None
    if attempts < 1 or interval_seconds < 0:
        raise ValueError("retry bounds must be non-negative")
    sleeper = sleep_fn or __import__("time").sleep
    for attempt in range(attempts):
        if lookup(expected_sha) == expected_sha:
            return expected_sha
        if attempt + 1 < attempts:
            sleeper(interval_seconds)
    return None


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


def machine_declaration(paths: Iterable[str], patch: str = "") -> dict[str, object]:
    """Return the declaration generated from the actual changed scope.

    This is an authoring aid, not an authority override: callers must still
    validate the submitted declaration with :func:`validate_declaration`.
    """

    scope = classify_scope(paths, patch)
    minimum = int(scope["machine_minimum_tier"])
    return {
        "risk_class": f"R{minimum}",
        "autonomy_tier": f"T{minimum}",
        "machine_minimum_tier": minimum,
        "reasons": list(scope["reasons"]),
    }


def validate_declaration(
    body: str, paths: Iterable[str], patch: str = ""
) -> dict[str, object]:
    """Validate a PR declaration against an independently classified scope."""

    generated = machine_declaration(paths, patch)
    declared_risk, declared_tier = parse_declaration(body)
    effective, error = effective_tier(
        int(generated["machine_minimum_tier"]), declared_risk, declared_tier
    )
    return {
        "valid": error is None,
        "error": error,
        "declared_risk": None if declared_risk is None else f"R{declared_risk}",
        "declared_tier": None if declared_tier is None else f"T{declared_tier}",
        "effective_tier": None if effective is None else f"T{effective}",
        "generated": generated,
    }


__all__ = [
    "classify_activation_scope",
    "classify_activation_provenance",
    "aggregate_landed_pr_effects",
    "reconcile_preexisting_pr_provenance",
    "classify_scope",
    "decide_activation",
    "decide_manual_activation",
    "is_founder_approval_eligible",
    "classify_production_runtime",
    "wait_for_exact_successful_provenance",
    "environment_protection_is_valid",
    "effective_tier",
    "machine_declaration",
    "validate_declaration",
    "has_rollback_evidence",
    "is_application_runtime_path",
    "is_control_plane_only_paths",
    "is_control_plane_path",
    "is_deployable_path",
    "is_production_activation_sensitive_path",
    "parse_declaration",
]
