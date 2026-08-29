#!/usr/bin/env python3
"""Fail-closed risk and independent-review gate for pull requests.

This is deliberately a small policy adapter around the repository's existing
Presubmit check.  The canonical tier meanings remain in
docs/governance/RISK_BASED_MERGE_POLICY.md; this file only derives the minimum
tier from the actual diff and verifies the evidence needed for that tier.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Iterable


ROOT = Path(__file__).resolve().parents[2]

TIER_NAMES = {0: "T0", 1: "T1", 2: "T2", 3: "T3"}

PROTECTED_PATHS = (
    re.compile(r"^backend/database/migrations/"),
    re.compile(r"^backend/app/Http/Middleware/"),
    re.compile(r"^backend/app/(Http/Controllers/(Auth|Alert)|Services/(SessionDeduction|ApprovalSessionSync))"),
    re.compile(r"^backend/app/Http/Controllers/SwipeRfid"),
    re.compile(r"^backend/routes/(api|web)\.php$"),
    re.compile(r"^\.github/workflows/(deploy|.*(repair|activation|restore|transfer|unpaid|reconcile|backfill|migration|rotation).*)\.ya?ml$"),
    re.compile(r"^scripts/(production|repair|ops)([-_/]|\.)"),
    re.compile(r"(^|/)\.env(?:\.|$)"),
    re.compile(r"(^|/)(credentials?|secrets?)(/|\.|$)", re.IGNORECASE),
)

T2_PATHS = (
    re.compile(r"^backend/"),
    re.compile(r"^\.github/workflows/"),
    re.compile(r"^scripts/"),
    re.compile(r"^\.exo/"),
    re.compile(r"^(AGENTS|CLAUDE)\.md$"),
    re.compile(r"^(codex\.md|\.cursorrules)$"),
    re.compile(r"^\.cursor/(rules|skills)/"),
    re.compile(r"^governance/"),
    re.compile(r"^docs/(governance|sop)/"),
    re.compile(r"^(composer\.json|composer\.lock|package\.json|package-lock\.json|vite\.config\.)"),
)

METADATA_PATHS = (re.compile(r"^\.agent-session/"),)

UI_SEMANTIC_MARKERS = re.compile(
    r"(auth|permission|role:|require_campus|campusid|attendance|leave|learningrecord|schedule|classsession|billing|payment|invoice|entitlement|sessiondeduction)",
    re.IGNORECASE,
)

MUTATION_MARKERS = re.compile(
    r"(appleboy/ssh-action|ssh\s+|mysql\s+|psql\s+|php\s+artisan\s+(migrate|db:|.*repair)|DB::table|UPDATE\s+|DELETE\s+FROM|production-activation|workflow_dispatch)",
    re.IGNORECASE,
)

ADAPTER_FILES = (
    "AGENTS.md",
    "CLAUDE.md",
    "codex.md",
    ".cursorrules",
    ".cursor/skills/alltrue-security/SKILL.md",
    ".cursor/skills/alltrue-code-review/SKILL.md",
    ".exo/CONSTITUTION.md",
    ".exo/governance.lock.json",
    ".exo/policy.sealed.json",
)

STALE_AUTONOMY_MARKERS = (
    "approval:human",
    "governance requires explicit human approval",
    "等使用者批准後才 DEV",
    "使用者批准後才實作",
    "每個 Phase 結束必列 Exit Checklist，問使用者",
)

STALE_COMMAND_PATTERN = re.compile(
    r"(?m)^\s*(?:-\s*)?(?:`)?(?:npm test|npm run lint|pytest|python -m pytest|python3 -m pytest)(?:`)?\s*$"
)


def _matches(path: str, patterns: Iterable[re.Pattern[str]]) -> bool:
    return any(pattern.search(path) for pattern in patterns)


def classify_scope(paths: list[str], patch: str = "") -> dict[str, Any]:
    """Return the minimum tier required by the real changed scope.

    Unknown paths and protected-looking content deliberately resolve to T3 and
    cannot be autonomously merged.  A PR declaration can only raise the tier,
    never lower the result of this classifier.
    """

    tier = 0
    reasons: list[str] = []
    protected = False
    unknown = []

    for path in paths:
        if _matches(path, METADATA_PATHS):
            reasons.append(f"session metadata path: {path}")
            continue
        if _matches(path, PROTECTED_PATHS):
            tier = max(tier, 3)
            protected = True
            reasons.append(f"protected path: {path}")
            continue
        if _matches(path, T2_PATHS):
            tier = max(tier, 2)
            reasons.append(f"T2 path: {path}")
            continue
        if path.startswith("frontend/"):
            if UI_SEMANTIC_MARKERS.search(patch):
                tier = max(tier, 2)
                reasons.append(f"frontend semantic marker: {path}")
            else:
                tier = max(tier, 1)
                reasons.append(f"presentation path: {path}")
            continue
        if path.startswith(("docs/", ".github/ISSUE_TEMPLATE/")) or path in {
            "README.md",
            "SECURITY.md",
            "CONTRIBUTING.md",
        }:
            reasons.append(f"documentation path: {path}")
            continue
        if path.startswith(("tests/", "backend/tests/", "frontend/tests/", "scripts/tests/")):
            tier = max(tier, 1)
            reasons.append(f"test path: {path}")
            continue
        unknown.append(path)

    executable_workflow = any(
        path.startswith(".github/workflows/") and path not in {".github/workflows/presubmit.yml", ".github/workflows/ci.yml"}
        for path in paths
    )
    if MUTATION_MARKERS.search(patch) and executable_workflow:
        tier = max(tier, 3)
        protected = True
        reasons.append("production mutation or activation marker in executable control path")

    if unknown:
        tier = 3
        protected = True
        reasons.append("unknown path(s) fail closed: " + ", ".join(unknown))

    return {
        "tier": tier,
        "tier_name": TIER_NAMES[tier],
        "protected_boundary": protected,
        "unknown_paths": unknown,
        "reasons": reasons,
        "autonomous_merge_possible": tier < 3,
    }


def parse_declaration(body: str) -> tuple[int | None, str | None]:
    risk = re.search(r"Risk-Class:\*{0,2}\s*R([0-3])", body or "", re.IGNORECASE)
    tier = re.search(r"Autonomy-Tier:\*{0,2}\s*T([0-3])", body or "", re.IGNORECASE)
    return (int(risk.group(1)) if risk else None, f"T{tier.group(1)}" if tier else None)


def independent_review(reviews: list[dict[str, Any]], author: str, head_sha: str) -> dict[str, Any]:
    """Require a current, approved review from an identity other than author."""

    latest: dict[str, dict[str, Any]] = {}
    for review in reviews:
        user = review.get("user") or {}
        login = str(user.get("login") or "").strip()
        if not login or login == author:
            continue
        timestamp = str(review.get("submitted_at") or "")
        previous = latest.get(login)
        if previous is None or timestamp >= str(previous.get("submitted_at") or ""):
            latest[login] = review

    for login, review in latest.items():
        if (
            str(review.get("state", "")).upper() == "APPROVED"
            and str(review.get("commit_id") or "") == head_sha
        ):
            return {"ok": True, "reviewer": login, "commit": head_sha}

    return {
        "ok": False,
        "reviewer": None,
        "commit": None,
        "reason": "no current APPROVED review from an identity distinct from the PR author",
    }


def _git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def changed_scope(base: str, head: str) -> tuple[list[str], str]:
    paths = [line for line in _git("diff", "--name-only", base, head).splitlines() if line]
    patch = _git("diff", base, head, "--")
    return paths, patch


def fetch_reviews(event: dict[str, Any], token: str | None) -> list[dict[str, Any]] | None:
    pr = event.get("pull_request") or {}
    repo = event.get("repository") or {}
    number = pr.get("number")
    full_name = repo.get("full_name")
    if not token or not number or not full_name:
        return None
    request = urllib.request.Request(
        f"https://api.github.com/repos/{full_name}/pulls/{number}/reviews?per_page=100",
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            value = json.load(response)
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        raise RuntimeError(f"cannot verify GitHub reviews: {exc}") from exc
    return value if isinstance(value, list) else []


def check_instructions(root: Path = ROOT) -> list[str]:
    violations: list[str] = []
    for relative in ADAPTER_FILES:
        path = root / relative
        if not path.is_file():
            violations.append(f"missing instruction source: {relative}")
            continue
        text = path.read_text(encoding="utf-8")
        for marker in STALE_AUTONOMY_MARKERS:
            if marker in text:
                violations.append(f"stale/conflicting autonomy marker in {relative}: {marker}")
        if STALE_COMMAND_PATTERN.search(text):
            violations.append(f"stale test command in {relative}")
    constitution = root / ".exo/CONSTITUTION.md"
    if constitution.is_file() and "T0/T1" not in constitution.read_text(encoding="utf-8"):
        violations.append(".exo/CONSTITUTION.md does not state current T0/T1/T2/T3 model")
    return violations


def goal_dry_run(goal: str) -> dict[str, Any]:
    text = goal.lower()
    if any(marker in text for marker in ("木柵", "請假", "補課", "合約", "堂數", "收費")):
        return {
            "goal": goal,
            "detected_tier": "T3",
            "allowed_actions": ["read-only investigation", "reproduce", "regression tests", "code fix", "dry-run evidence package"],
            "stop_boundary": "production repair, data mutation, migration/schema cutover, billing/entitlement semantic decision, activation",
            "autonomous_merge_allowed": False,
        }
    if any(marker in text for marker in ("老師", "登入", "資訊太散", "下一個", "teacher", "next action")):
        return {
            "goal": goal,
            "detected_tier": "T1 (unless diff changes domain semantics)",
            "allowed_actions": ["bounded UI slice", "responsive/accessibility verification", "regression tests", "PR"],
            "stop_boundary": "permissions, attendance/schedule semantics, auth, or major product direction",
            "autonomous_merge_allowed": True,
        }
    if any(marker in text for marker in ("laravel", "升級", "bounded", "composer", "framework")):
        return {
            "goal": goal,
            "detected_tier": "T2 (major dependency work; docs-only inventory may be T0)",
            "allowed_actions": ["compatibility inventory", "isolated spike", "full tests", "rollback plan", "PR"],
            "stop_boundary": "production runtime activation, migration/schema cutover, auth behavior decision, or unverified Pi compatibility",
            "autonomous_merge_allowed": "conditional on actual diff and independent review",
        }
    return {
        "goal": goal,
        "detected_tier": "T3",
        "allowed_actions": ["read-only investigation only"],
        "stop_boundary": "unknown scope",
        "autonomous_merge_allowed": False,
    }


def self_test() -> int:
    assert classify_scope(["README.md"])["tier"] == 0
    assert classify_scope([".agent-session/manifest.json"])["tier"] == 0
    assert classify_scope(["frontend/src/pages/TeacherHomePage.vue"], "copy and spacing")["tier"] == 1
    assert classify_scope(["backend/app/Services/SessionDeductionService.php"])["tier"] == 3
    assert classify_scope(["unknown.bin"])["autonomous_merge_possible"] is False
    assert parse_declaration("Risk-Class: R2\nAutonomy-Tier: T2") == (2, "T2")
    assert parse_declaration("**Risk-Class:** R2\n**Autonomy-Tier:** T2") == (2, "T2")
    reviews = [{"user": {"login": "author"}, "state": "APPROVED", "commit_id": "head", "submitted_at": "2"}]
    assert independent_review(reviews, "author", "head")["ok"] is False
    reviews.append({"user": {"login": "verifier"}, "state": "APPROVED", "commit_id": "head", "submitted_at": "3"})
    assert independent_review(reviews, "author", "head")["ok"] is True
    assert goal_dry_run("木柵請假補課撞新合約")["detected_tier"] == "T3"
    assert goal_dry_run("老師登入後資訊太散")["autonomous_merge_allowed"] is True
    assert "T2" in goal_dry_run("Laravel 升級 bounded step")["detected_tier"]
    print("autonomy-gate self-test: PASS")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base")
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--event")
    parser.add_argument("--token")
    parser.add_argument("--goal")
    parser.add_argument("--check-instructions", action="store_true")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        return self_test()
    if args.check_instructions:
        self_test()
        violations = check_instructions()
        if violations:
            for violation in violations:
                print(f"AUTONOMY-INSTRUCTION-FAIL: {violation}")
            return 1
        print("autonomy instruction consistency: PASS")
        return 0
    if args.goal:
        print(json.dumps(goal_dry_run(args.goal), ensure_ascii=False, indent=2))
        return 0
    if not args.base:
        parser.error("--base is required unless --self-test, --goal, or --check-instructions is used")

    paths, patch = changed_scope(args.base, args.head)
    result = classify_scope(paths, patch)
    result["changed_files"] = paths

    event: dict[str, Any] = {}
    if args.event:
        event = json.loads(Path(args.event).read_text(encoding="utf-8"))
    pr = event.get("pull_request") or {}
    declared_risk, declared_tier = parse_declaration(str(pr.get("body") or ""))
    result["declared_risk"] = declared_risk
    result["declared_tier"] = declared_tier

    if pr and (declared_risk is None or declared_tier is None):
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: PR must declare Risk-Class and Autonomy-Tier", file=sys.stderr)
        return 1

    if declared_risk is not None and declared_risk < result["tier"]:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: PR declaration is lower than actual changed scope", file=sys.stderr)
        return 1
    if declared_tier is not None and int(declared_tier[1:]) < result["tier"]:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: Autonomy-Tier is lower than actual changed scope", file=sys.stderr)
        return 1

    if result["tier"] >= 2:
        if not pr:
            result["independent_review"] = {"ok": None, "reason": "local scope check; GitHub review is evaluated only for a PR event"}
        else:
            reviews = fetch_reviews(event, args.token)
            if reviews is None:
                result["independent_review"] = {"ok": False, "reason": "GitHub review evidence unavailable"}
            else:
                author = str((pr.get("user") or {}).get("login") or "")
                head_sha = str((pr.get("head") or {}).get("sha") or "")
                result["independent_review"] = independent_review(reviews, author, head_sha)
        if result["tier"] == 2 and pr and not result["independent_review"]["ok"]:
            print(json.dumps(result, ensure_ascii=False, indent=2))
            print("AUTONOMY-GATE-FAIL: T2 requires verifiable independent review", file=sys.stderr)
            return 1

    if result["tier"] >= 3:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: T3/protected scope is fail-closed for autonomous merge", file=sys.stderr)
        return 1

    print(json.dumps(result, ensure_ascii=False, indent=2))
    print(f"autonomy gate: PASS ({result['tier_name']})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
