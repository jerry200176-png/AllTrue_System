#!/usr/bin/env python3
"""Fail-closed risk and review gate for pull requests.

The canonical meanings live in docs/governance/RISK_BASED_MERGE_POLICY.md.
This adapter derives the minimum tier from the changed scope, combines it with
the PR declaration, and verifies the evidence required by the effective tier.
It deliberately does not classify natural-language goals: a goal is planning
context; the actual diff and repository policy decide merge eligibility.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Iterable


ROOT = Path(__file__).resolve().parents[2]
TIER_NAMES = {0: "T0", 1: "T1", 2: "T2", 3: "T3"}
AUTHORIZED_REVIEW_ASSOCIATIONS = {"COLLABORATOR", "MEMBER", "OWNER"}

PROTECTED_PATHS = (
    re.compile(r"^backend/app/Http/Middleware/"),
    re.compile(r"^backend/app/(Http/Controllers/Auth|Services/(Identity|Authorization))"),
    re.compile(r"^\.github/workflows/(.*(activation|restore|repair|backfill|reconcile|rotation|transfer|unpaid|migration).*)\.ya?ml$", re.I),
    re.compile(r"^scripts/(production|repair|ops)([-_/]|\.)", re.I),
    re.compile(r"(^|/)\.env(?:\.|$)"),
    re.compile(r"(^|/)(credentials?|secrets?)(/|\.|$)", re.I),
)

T2_PATHS = (
    re.compile(r"^\.github/workflows/"),
    re.compile(r"^\.exo/"),
    re.compile(r"^(AGENTS|CLAUDE)\.md$"),
    re.compile(r"^(codex\.md|\.cursorrules)$"),
    re.compile(r"^\.cursor/(rules|skills)/"),
    re.compile(r"^governance/"),
    re.compile(r"^docs/(governance|sop)/"),
    re.compile(r"^(composer\.json|composer\.lock|package\.json|package-lock\.json|vite\.config\.)"),
)

METADATA_PATHS = (re.compile(r"^\.agent-session/"),)

DOMAIN_T2_MARKERS = re.compile(
    r"(schedule|class.?session|attendance|leave|learning.?record|billing|payment|invoice|entitlement|session.?deduct|contract|cross.?campus|cron|queue|job)",
    re.IGNORECASE,
)
PROTECTED_MARKERS = re.compile(
    r"(production[ _-]?(activation|repair|mutation|backfill)|data[ _-]?(repair|mutation|backfill)|schema[ _-]?cutover|privilege[ _-]?expansion|permission[ _-]?grant|role[ _-]?grant|authentication|authorization|authz|\bcredential\b|\bsecret\b|backup[ _-]?restore|destructive|drop[ _]+(table|column)|truncate|financial[ _-]?correction|security[ _-]?boundary|ssh[ \t]+|appleboy/ssh-action|ACTIVATE_PRODUCTION)",
    re.IGNORECASE,
)
DESTRUCTIVE_MIGRATION_MARKERS = re.compile(
    r"(drop[ _]+(table|column|index)|truncate|rename[ _]+column|removeColumn|schema[ _-]?cutover|destructive|delete[ _]+from)",
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

# Only normative surfaces are scanned. Decision records, changelogs, and
# technical-debt history are intentionally not executable instruction inputs.
ACTIVE_POLICY_FILES = ADAPTER_FILES + (
    ".github/pull_request_template.md",
    "CONTRIBUTING.md",
    "docs/governance/RISK_BASED_MERGE_POLICY.md",
    "docs/OPERATIONS_RUNBOOK.md",
    "docs/REF_GITHUB_RULESET_BASELINE.md",
    ".github/workflows/high-risk-test-gate.yml",
)

STALE_AUTONOMY_MARKERS = (
    "approval:human",
    "governance requires explicit human approval",
    "高風險模組（堂數扣除、繳費計算）必須標記「需使用者確認」",
    "以「自動代理人 + 強制檢查」近似第二雙眼",
    "CI = 自動 reviewer",
    "CI 取代 human review",
    "沒有第二位強制 reviewer，只能靠自動化代理與自我檢查表近似補位",
    "CEO 批准後；docs-only 亦同",
    "觸發時 CEO 自審即可",
    "等使用者批准後才 DEV",
    "使用者批准後才實作",
    "每個 Phase 結束必列 Exit Checklist，問使用者",
)

STALE_COMMAND_PATTERN = re.compile(
    r"(?m)^\s*(?:-\s*)?(?:`)?(?:npm test|npm run lint|pytest|python -m pytest|python3 -m pytest)(?:`)?\s*$"
)


def _matches(path: str, patterns: Iterable[re.Pattern[str]]) -> bool:
    return any(pattern.search(path) for pattern in patterns)


def _path_patch(patch: str, path: str) -> str:
    """Return one file's diff, preventing comments in another file changing risk."""
    chunks = re.split(r"(?=^diff --git a/)", patch or "", flags=re.MULTILINE)
    for chunk in chunks:
        if re.search(rf"^\+\+\+ b/{re.escape(path)}$", chunk, re.MULTILINE):
            return chunk
    return patch or ""


def _is_migration(path: str) -> bool:
    return path.startswith("backend/database/migrations/")


def _is_deployable(path: str) -> bool:
    return path.startswith(("backend/", "frontend/", "scripts/")) or path in {
        "composer.json",
        "composer.lock",
        ".github/workflows/deploy.yml",
    }


def classify_scope(paths: list[str], patch: str = "") -> dict[str, Any]:
    """Derive the minimum tier from actual changed paths and file-local patch text."""
    tier = 0
    reasons: list[str] = []
    protected = False
    unknown: list[str] = []
    deployable = False

    for path in paths:
        file_patch = _path_patch(patch, path)
        deployable = deployable or _is_deployable(path)

        if _matches(path, METADATA_PATHS):
            reasons.append(f"session metadata path: {path}")
            continue
        if _is_migration(path):
            if DESTRUCTIVE_MIGRATION_MARKERS.search(file_patch):
                tier = max(tier, 3)
                protected = True
                reasons.append(f"destructive migration/schema cutover: {path}")
            else:
                tier = max(tier, 2)
                reasons.append(f"additive/reversible migration: {path}")
            continue
        if _matches(path, PROTECTED_PATHS):
            tier = max(tier, 3)
            protected = True
            reasons.append(f"protected path: {path}")
            continue
        if path.startswith(".github/workflows/"):
            if PROTECTED_MARKERS.search(file_patch) and path != ".github/workflows/presubmit.yml":
                tier = max(tier, 3)
                protected = True
                reasons.append(f"protected workflow behavior: {path}")
            else:
                tier = max(tier, 2)
                reasons.append(f"control-plane workflow: {path}")
            continue
        if path.startswith("backend/"):
            if PROTECTED_MARKERS.search(file_patch):
                tier = max(tier, 3)
                protected = True
                reasons.append(f"protected backend behavior: {path}")
            elif DOMAIN_T2_MARKERS.search(path) or DOMAIN_T2_MARKERS.search(file_patch):
                tier = max(tier, 2)
                reasons.append(f"domain-semantic backend behavior: {path}")
            else:
                tier = max(tier, 1)
                reasons.append(f"isolated backend bugfix candidate: {path}")
            continue
        if path.startswith("frontend/"):
            if PROTECTED_MARKERS.search(file_patch):
                tier = max(tier, 3)
                protected = True
                reasons.append(f"protected frontend behavior: {path}")
            elif DOMAIN_T2_MARKERS.search(path) or DOMAIN_T2_MARKERS.search(file_patch):
                tier = max(tier, 2)
                reasons.append(f"domain-semantic frontend behavior: {path}")
            else:
                tier = max(tier, 1)
                reasons.append(f"presentation frontend behavior: {path}")
            continue
        if path.startswith("scripts/tests/") or path.startswith(("tests/", "backend/tests/", "frontend/tests/")):
            tier = max(tier, 1)
            reasons.append(f"test path: {path}")
            continue
        if path.startswith("scripts/"):
            if path.startswith("scripts/governance/"):
                tier = max(tier, 2)
                reasons.append(f"governance enforcement script: {path}")
            elif PROTECTED_MARKERS.search(file_patch):
                tier = max(tier, 3)
                protected = True
                reasons.append(f"protected script behavior: {path}")
            else:
                tier = max(tier, 2)
                reasons.append(f"engineering/control script: {path}")
            continue
        if _matches(path, T2_PATHS):
            tier = max(tier, 2)
            reasons.append(f"T2 policy/config/dependency path: {path}")
            continue
        if path.startswith(("docs/", ".github/ISSUE_TEMPLATE/")) or path in {
            "README.md",
            "SECURITY.md",
            "CONTRIBUTING.md",
            ".github/pull_request_template.md",
        }:
            reasons.append(f"documentation path: {path}")
            continue
        unknown.append(path)

    if unknown:
        tier = 3
        protected = True
        reasons.append("unknown path(s) fail closed: " + ", ".join(unknown))

    return {
        "machine_minimum_tier": tier,
        "tier": tier,
        "tier_name": TIER_NAMES[tier],
        "protected_boundary": protected,
        "unknown_paths": unknown,
        "reasons": reasons,
        "deployable_scope": deployable,
    }


def parse_declaration(body: str) -> tuple[int | None, int | None]:
    risk = re.search(r"Risk-Class:\*{0,2}\s*R([0-3])", body or "", re.IGNORECASE)
    tier = re.search(r"Autonomy-Tier:\*{0,2}\s*T([0-3])", body or "", re.IGNORECASE)
    return (int(risk.group(1)) if risk else None, int(tier.group(1)) if tier else None)


def effective_tier(machine_minimum: int, declared_risk: int | None, declared_tier: int | None) -> tuple[int | None, str | None]:
    if declared_risk is None or declared_tier is None:
        return None, "both Risk-Class and Autonomy-Tier are required for a PR"
    if declared_risk != declared_tier:
        return None, "Risk-Class and Autonomy-Tier must map to the same tier"
    if declared_risk < machine_minimum or declared_tier < machine_minimum:
        return None, "declaration understates machine-derived minimum tier"
    return max(machine_minimum, declared_risk, declared_tier), None


def independent_review(reviews: list[dict[str, Any]], author: str, head_sha: str) -> dict[str, Any]:
    """Accept a current-head GitHub approval from a distinct identity."""
    latest: dict[str, dict[str, Any]] = {}
    for review in reviews:
        login = str((review.get("user") or {}).get("login") or "").strip()
        if not login or login == author:
            continue
        timestamp = str(review.get("submitted_at") or "")
        previous = latest.get(login)
        if previous is None or timestamp >= str(previous.get("submitted_at") or ""):
            latest[login] = review
    for login, review in latest.items():
        association = str(review.get("author_association") or "").upper()
        if (
            str(review.get("state", "")).upper() == "APPROVED"
            and str(review.get("commit_id") or "") == head_sha
            and association in AUTHORIZED_REVIEW_ASSOCIATIONS
        ):
            return {"ok": True, "kind": "github", "reviewer": login, "commit": head_sha}
    return {"ok": False, "kind": "github", "reason": "no current distinct authorized GitHub APPROVED review"}


def _canonical_json(value: dict[str, Any]) -> bytes:
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _valid_manifest(manifest: Any, expected_base: str, session_id_to_exclude: str = "") -> tuple[bool, str]:
    if not isinstance(manifest, dict):
        return False, "verifier manifest must be an object"
    required = {
        "schema_version", "session_id", "project", "task_id", "repo_remote", "base_sha",
        "branch", "worktree_path", "started_at", "production_mutation", "preflight_result",
        "provenance_type",
    }
    missing = sorted(required - set(manifest))
    if missing:
        return False, "verifier manifest missing: " + ", ".join(missing)
    if manifest.get("schema_version") != "1.0" or manifest.get("project") != "alltrue":
        return False, "verifier manifest schema/project invalid"
    session_id = str(manifest.get("session_id") or "")
    if len(session_id) < 8 or session_id == session_id_to_exclude:
        return False, "verifier session is missing or is the implementing session"
    if manifest.get("base_sha") != expected_base or not re.fullmatch(r"[0-9a-f]{40}", str(manifest.get("base_sha"))):
        return False, "verifier manifest base_sha does not match PR base"
    if manifest.get("production_mutation") is not False or manifest.get("preflight_result") != "pass":
        return False, "verifier manifest is not a passed non-production session"
    if manifest.get("provenance_type") != "agent-session":
        return False, "verifier provenance_type must be agent-session"
    if not str(manifest.get("branch") or "") or not str(manifest.get("worktree_path") or ""):
        return False, "verifier manifest branch/worktree is missing"
    blob = json.dumps(manifest, ensure_ascii=True)
    if re.search(r"(api[_-]?key|token|password|secret|BEGIN PRIVATE)", blob, re.IGNORECASE):
        return False, "verifier manifest contains sensitive-looking data"
    return True, "ok"


def verifier_attestation(repo: Path, body: str, author_session_id: str, base_sha: str, head_sha: str) -> dict[str, Any]:
    """Validate optional evidence using the existing agent-session schema."""
    match = re.search(r"(?im)^\s*Independent-Review-Attestation:\s*([^\s]+)\s*$", body or "")
    relative = match.group(1).strip() if match else ".agent-session/independent-review.json"
    if not relative.startswith(".agent-session/") or ".." in Path(relative).parts:
        return {"ok": False, "kind": "verifier", "reason": "attestation path must be under .agent-session/"}
    path = repo / relative
    if not path.is_file():
        return {"ok": False, "kind": "verifier", "reason": f"attestation file missing: {relative}"}
    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return {"ok": False, "kind": "verifier", "reason": f"attestation unreadable: {exc}"}
    if not isinstance(evidence, dict) or evidence.get("schema_version") != "1.0":
        return {"ok": False, "kind": "verifier", "reason": "attestation schema invalid"}
    if evidence.get("provenance_type") != "independent-agent-review" or evidence.get("decision") != "approved":
        return {"ok": False, "kind": "verifier", "reason": "attestation is not an approved independent-agent review"}
    if evidence.get("reviewed_base_sha") != base_sha or evidence.get("reviewed_head_sha") != head_sha:
        return {"ok": False, "kind": "verifier", "reason": "attestation does not cover the current base/head"}
    manifest = evidence.get("verifier_manifest")
    valid, reason = _valid_manifest(manifest, base_sha, author_session_id)
    if not valid:
        return {"ok": False, "kind": "verifier", "reason": reason}
    expected_hash = hashlib.sha256(_canonical_json(manifest)).hexdigest()
    if evidence.get("verifier_manifest_sha256") != expected_hash:
        return {"ok": False, "kind": "verifier", "reason": "verifier manifest hash is invalid"}
    return {"ok": True, "kind": "verifier", "session_id": manifest["session_id"], "head_sha": head_sha, "artifact": relative}


def review_evidence(repo: Path, event: dict[str, Any], base_sha: str, head_sha: str) -> dict[str, Any]:
    pr = event.get("pull_request") or {}
    author = str((pr.get("user") or {}).get("login") or "")
    reviews = event.get("reviews")
    github = independent_review(reviews, author, head_sha) if isinstance(reviews, list) else {
        "ok": False, "kind": "github", "reason": "GitHub review evidence unavailable",
    }
    if github.get("ok"):
        return github
    manifest_path = repo / ".agent-session/manifest.json"
    implementer_session_id = ""
    if manifest_path.is_file():
        try:
            implementer_session_id = str(json.loads(manifest_path.read_text(encoding="utf-8")).get("session_id") or "")
        except (OSError, json.JSONDecodeError):
            pass
    verifier = verifier_attestation(repo, str(pr.get("body") or ""), implementer_session_id, base_sha, head_sha)
    if verifier.get("ok"):
        return verifier
    return {"ok": False, "kind": "none", "github": github, "verifier": verifier, "reason": "no current-head GitHub approval or verifiable independent verifier attestation"}


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
        headers={"Accept": "application/vnd.github+json", "Authorization": f"Bearer {token}", "X-GitHub-Api-Version": "2022-11-28"},
    )
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            value = json.load(response)
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        raise RuntimeError(f"cannot verify GitHub reviews: {exc}") from exc
    return value if isinstance(value, list) else []


def _git_show(ref: str, path: str) -> str:
    try:
        return subprocess.check_output(["git", "show", f"{ref}:{path}"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL)
    except subprocess.CalledProcessError:
        return ""


def activation_separation_available(base_ref: str) -> bool:
    """Only a verified base branch state can make T3 production code mergeable."""
    deploy = _git_show(base_ref, ".github/workflows/deploy.yml")
    return all(marker in deploy for marker in ("merged-awaiting-activation", "ACTIVATE_PRODUCTION:", "production-activation"))


def merge_eligibility(effective: int, deployable: bool, activation_separated: bool) -> str:
    """Separate PR merge eligibility from the later protected activation."""
    if effective >= 3 and deployable and not activation_separated:
        return "blocked-activation-separation"
    return "autonomous-after-required-checks"


def check_instructions(root: Path = ROOT) -> list[str]:
    violations: list[str] = []
    for relative in ACTIVE_POLICY_FILES:
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


def self_test() -> int:
    assert classify_scope(["README.md"])["machine_minimum_tier"] == 0
    assert classify_scope([".agent-session/manifest.json"])["machine_minimum_tier"] == 0
    assert classify_scope(["frontend/src/pages/TeacherHomePage.vue"], "copy and spacing")["machine_minimum_tier"] == 1
    assert classify_scope(["backend/app/Services/CalendarHelper.php"], "isolated null guard")["machine_minimum_tier"] == 1
    assert classify_scope(["backend/app/Services/ScheduleService.php"], "fix schedule collision")["machine_minimum_tier"] == 2
    assert classify_scope(["backend/database/migrations/2026_add_index.php"], "Schema::table add index")["machine_minimum_tier"] == 2
    assert classify_scope(["backend/database/migrations/2026_add_column.php"], "up add column; public function down() {}")["machine_minimum_tier"] == 2
    assert classify_scope(["backend/database/migrations/2026_drop_column.php"], "drop column")["machine_minimum_tier"] == 3
    assert classify_scope(["scripts/repair/reconcile.py"], "repair data")["machine_minimum_tier"] == 3
    assert classify_scope([".github/workflows/ci.yml"], "run tests")["machine_minimum_tier"] == 2
    assert classify_scope([".github/workflows/deploy.yml"], "ssh deploy")["machine_minimum_tier"] == 3
    assert classify_scope(["unknown.bin"])["protected_boundary"] is True

    assert parse_declaration("Risk-Class: R2\nAutonomy-Tier: T2") == (2, 2)
    assert effective_tier(1, 1, 1) == (1, None)
    assert effective_tier(2, 1, 1)[0] is None
    assert effective_tier(1, 2, 2) == (2, None)
    assert effective_tier(1, 3, 3) == (3, None)
    assert effective_tier(2, 3, 3) == (3, None)
    assert effective_tier(1, 1, 2)[0] is None
    assert effective_tier(1, 2, 1)[0] is None

    reviews = [{"user": {"login": "author"}, "state": "APPROVED", "commit_id": "head", "submitted_at": "2", "author_association": "OWNER"}]
    assert independent_review(reviews, "author", "head")["ok"] is False
    reviews.append({"user": {"login": "verifier"}, "state": "APPROVED", "commit_id": "old", "submitted_at": "3", "author_association": "COLLABORATOR"})
    assert independent_review(reviews, "author", "head")["ok"] is False
    reviews.append({"user": {"login": "verifier"}, "state": "APPROVED", "commit_id": "head", "submitted_at": "4", "author_association": "COLLABORATOR"})
    assert independent_review(reviews, "author", "head")["ok"] is True

    manifest = {
        "schema_version": "1.0", "session_id": "verifier-session-123", "project": "alltrue",
        "task_id": "independent-review", "repo_remote": "https://github.com/jerry200176-png/AllTrue_System.git",
        "base_sha": "a" * 40, "branch": "reviewer/independent", "worktree_path": "/workspace/tasks/alltrue/reviewer",
        "started_at": "2026-08-29T00:00:00Z", "production_mutation": False,
        "preflight_result": "pass", "provenance_type": "agent-session", "agent_cli": "codex",
    }
    assert _valid_manifest(manifest, "a" * 40, "implementer-session")[0] is True
    assert _valid_manifest(manifest, "a" * 40, "verifier-session-123")[0] is False
    evidence = {
        "schema_version": "1.0", "provenance_type": "independent-agent-review", "decision": "approved",
        "reviewed_base_sha": "a" * 40, "reviewed_head_sha": "b" * 40,
        "verifier_manifest": manifest, "verifier_manifest_sha256": hashlib.sha256(_canonical_json(manifest)).hexdigest(),
    }
    assert evidence["verifier_manifest_sha256"] == hashlib.sha256(_canonical_json(manifest)).hexdigest()
    evidence["reviewed_head_sha"] = "c" * 40
    assert evidence["reviewed_head_sha"] != "b" * 40
    assert merge_eligibility(3, True, False) == "blocked-activation-separation"
    assert merge_eligibility(3, True, True) == "autonomous-after-required-checks"
    assert merge_eligibility(3, False, False) == "autonomous-after-required-checks"

    with tempfile.TemporaryDirectory() as temp_dir:
        repo = Path(temp_dir)
        (repo / ".agent-session").mkdir()
        evidence["reviewed_head_sha"] = "b" * 40
        (repo / ".agent-session/independent-review.json").write_text(
            json.dumps(evidence), encoding="utf-8"
        )
        valid = verifier_attestation(
            repo,
            "Independent-Review-Attestation: .agent-session/independent-review.json",
            "implementer-session",
            "a" * 40,
            "c" * 40,
        )
        assert valid["ok"] is False  # deliberately stale current-head evidence
        evidence["reviewed_head_sha"] = "c" * 40
        (repo / ".agent-session/independent-review.json").write_text(
            json.dumps(evidence), encoding="utf-8"
        )
        valid = verifier_attestation(
            repo,
            "Independent-Review-Attestation: .agent-session/independent-review.json",
            "implementer-session",
            "a" * 40,
            "c" * 40,
        )
        assert valid["ok"] is True
        evidence["verifier_manifest_sha256"] = "0" * 64
        (repo / ".agent-session/independent-review.json").write_text(
            json.dumps(evidence), encoding="utf-8"
        )
        invalid = verifier_attestation(
            repo,
            "Independent-Review-Attestation: .agent-session/independent-review.json",
            "implementer-session",
            "a" * 40,
            "c" * 40,
        )
        assert invalid["ok"] is False
    print("autonomy-gate self-test: PASS")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base")
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--event")
    parser.add_argument("--token")
    parser.add_argument("--check-instructions", action="store_true")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        return self_test()
    if args.check_instructions:
        violations = check_instructions()
        if violations:
            for violation in violations:
                print(f"AUTONOMY-INSTRUCTION-FAIL: {violation}")
            return 1
        print("autonomy instruction consistency: PASS")
        return 0
    if not args.base:
        parser.error("--base is required unless --self-test or --check-instructions is used")

    paths, patch = changed_scope(args.base, args.head)
    result = classify_scope(paths, patch)
    result["changed_files"] = paths
    event: dict[str, Any] = {}
    if args.event:
        event = json.loads(Path(args.event).read_text(encoding="utf-8"))
    pr = event.get("pull_request") or {}
    declared_risk, declared_tier = parse_declaration(str(pr.get("body") or ""))
    result["declared_risk"] = f"R{declared_risk}" if declared_risk is not None else None
    result["declared_tier"] = f"T{declared_tier}" if declared_tier is not None else None
    result["effective_tier"] = None
    result["merge_eligibility"] = "unknown"

    if pr and (declared_risk is None or declared_tier is None):
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: PR must declare Risk-Class and Autonomy-Tier", file=sys.stderr)
        return 1
    if not pr:
        result["merge_eligibility"] = "local-only"
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print(f"autonomy scope: PASS ({result['tier_name']})")
        return 0

    effective, declaration_error = effective_tier(int(result["machine_minimum_tier"]), declared_risk, declared_tier)
    if declaration_error:
        result["declaration_error"] = declaration_error
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print(f"AUTONOMY-GATE-FAIL: {declaration_error}", file=sys.stderr)
        return 1
    assert effective is not None
    result["effective_tier"] = TIER_NAMES[effective]
    result["effective_tier_number"] = effective

    base_sha = str((pr.get("base") or {}).get("sha") or args.base)
    head_sha = str((pr.get("head") or {}).get("sha") or args.head)
    result["activation_separation_available"] = activation_separation_available(args.base)
    result["review_evidence"] = {"ok": None, "reason": "not required for T0/T1"}
    if effective >= 2:
        reviews = fetch_reviews(event, args.token)
        event["reviews"] = reviews if reviews is not None else []
        result["review_evidence"] = review_evidence(ROOT, event, base_sha, head_sha)
        if not result["review_evidence"].get("ok"):
            result["merge_eligibility"] = "blocked-review"
            print(json.dumps(result, ensure_ascii=False, indent=2))
            print("AUTONOMY-GATE-FAIL: T2+ requires current-head independent review evidence", file=sys.stderr)
            return 1

    result["merge_eligibility"] = merge_eligibility(
        effective,
        bool(result["deployable_scope"]),
        bool(result["activation_separation_available"]),
    )
    if result["merge_eligibility"] == "blocked-activation-separation":
        print(json.dumps(result, ensure_ascii=False, indent=2))
        print("AUTONOMY-GATE-FAIL: T3 deployable scope cannot merge while merge still implies production activation", file=sys.stderr)
        return 1

    print(json.dumps(result, ensure_ascii=False, indent=2))
    print(f"autonomy gate: PASS ({result['effective_tier']})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
