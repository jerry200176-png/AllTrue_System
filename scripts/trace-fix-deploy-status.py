#!/usr/bin/env python3
"""Produce a read-only issue -> PR -> release -> deploy -> runtime trace.

The report is deliberately evidence-oriented: a missing release, deploy run,
or in-app Phase-C record is printed as *not verified*, never inferred from a
merged PR or a GitHub label. It uses only GitHub read APIs, local git metadata,
and the public non-secret deployment manifest.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from urllib.request import Request, urlopen


DEFAULT_REPO = "jerry200176-png/AllTrue_System"
DEFAULT_PRODUCTION_URL = "https://daan.lifenet.com.tw"
ISSUE_REFERENCE_TEMPLATE = r"(?<![0-9])#{}(?![0-9])"


class TraceError(RuntimeError):
    """Raised when an evidence source cannot be read."""


def command(*args: str, check: bool = True) -> str:
    result = subprocess.run(args, text=True, capture_output=True)
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise TraceError(f"command failed: {' '.join(args)}\n{detail}")
    return result.stdout


def gh_json(*args: str) -> object:
    try:
        return json.loads(command("gh", *args))
    except (json.JSONDecodeError, TraceError) as exc:
        raise TraceError(f"GitHub evidence unavailable: {exc}") from exc


def fetch_local_refs() -> None:
    # Fetch only refs needed for ancestry checks. This changes local metadata,
    # never GitHub or production state.
    command("git", "fetch", "origin", "main", "--quiet", check=False)
    command("git", "fetch", "origin", "--tags", "--quiet", check=False)


def issue_data(repo: str, issue: int) -> dict:
    value = gh_json(
        "issue", "view", str(issue), "--repo", repo,
        "--json", "title,state,closedAt,url",
    )
    if not isinstance(value, dict):
        raise TraceError("GitHub issue response was not an object")
    return value


def linked_prs(repo: str, issue: int) -> list[dict]:
    value = gh_json(
        "pr", "list", "--repo", repo, "--state", "all", "--limit", "1000",
        "--search", f"#{issue}", "--json",
        "number,title,body,state,mergedAt,mergeCommit,url",
    )
    if not isinstance(value, list):
        raise TraceError("GitHub pull-request response was not a list")
    pattern = re.compile(ISSUE_REFERENCE_TEMPLATE.format(issue))
    matches = []
    for pr in value:
        if not isinstance(pr, dict):
            continue
        text = f"{pr.get('title') or ''}\n{pr.get('body') or ''}"
        if pattern.search(text):
            matches.append(pr)
    return sorted(matches, key=lambda item: item.get("number", 0))


def landed_commit(pr_number: int) -> str | None:
    # Squash merge metadata can point at a pre-squash commit. The first-parent
    # main log is the commit that actually landed in this repository.
    output = command(
        "git", "log", "origin/main", "--first-parent", "--format=%H",
        f"--grep=(#{pr_number})", "-i", check=False,
    )
    commits = [line.strip() for line in output.splitlines() if line.strip()]
    return commits[-1] if commits else None


def pull_request_files(repo: str, pr_number: int) -> list[str]:
    value = gh_json("pr", "view", str(pr_number), "--repo", repo, "--json", "files")
    if not isinstance(value, dict):
        return []
    files = value.get("files")
    if not isinstance(files, list):
        return []
    return [item.get("path", "") for item in files if isinstance(item, dict)]


def deployment_manifest(production_url: str) -> dict:
    request = Request(
        f"{production_url.rstrip('/')}/deployment.json",
        headers={"User-Agent": "AllTrue-ops-readonly/1.0"},
    )
    try:
        with urlopen(request, timeout=20) as response:
            value = json.load(response)
    except (OSError, json.JSONDecodeError) as exc:
        raise TraceError(f"public deployment manifest unavailable: {exc}") from exc
    if not isinstance(value, dict):
        raise TraceError("public deployment manifest was not an object")
    return value


def is_ancestor(commit: str, target: str) -> bool | None:
    if not re.fullmatch(r"[0-9a-fA-F]{40}", commit or ""):
        return None
    if not re.fullmatch(r"[0-9a-fA-F]{40}", target or ""):
        return None
    for value in (commit, target):
        if subprocess.run(["git", "cat-file", "-e", f"{value}^{{commit}}"], capture_output=True).returncode != 0:
            return None
    return subprocess.run(
        ["git", "merge-base", "--is-ancestor", commit, target],
        capture_output=True,
    ).returncode == 0


def release_evidence(repo: str, commit: str) -> tuple[int, list[dict]]:
    """Return release-tag count and a bounded list of release links."""
    result = subprocess.run(
        ["git", "tag", "--contains", commit], text=True, capture_output=True
    )
    containing = {line.strip() for line in result.stdout.splitlines() if line.strip()}
    if not containing:
        return 0, []
    value = gh_json("release", "list", "--repo", repo, "--limit", "1000", "--json", "tagName,publishedAt")
    releases = [
        item for item in value if isinstance(item, dict) and item.get("tagName") in containing
    ] if isinstance(value, list) else []
    releases.sort(key=lambda item: item.get("publishedAt") or "", reverse=True)
    return len(releases), releases[:5]


def deploy_runs(repo: str, commit: str) -> list[dict]:
    value = gh_json(
        "run", "list", "--repo", repo, "--workflow", "deploy.yml",
        "--commit", commit, "--limit", "20",
        "--json", "databaseId,status,conclusion,event,createdAt,updatedAt,url",
    )
    return value if isinstance(value, list) else []


def status(value: bool | None) -> str:
    if value is None:
        return "NOT_VERIFIED (commit unavailable locally)"
    return "LIVE" if value else "NOT_LIVE"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("issue", type=int, help="GitHub issue number")
    parser.add_argument("--repo", default=DEFAULT_REPO)
    parser.add_argument("--production-url", default=DEFAULT_PRODUCTION_URL)
    args = parser.parse_args()

    try:
        fetch_local_refs()
        issue = issue_data(args.repo, args.issue)
        prs = linked_prs(args.repo, args.issue)
        manifest = deployment_manifest(args.production_url)
    except TraceError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    print(f"=== Trace issue #{args.issue} ===")
    print(f"title: {issue.get('title', '')}")
    print(f"github_state: {issue.get('state', 'UNKNOWN')}")
    print(f"issue_url: {issue.get('url', '')}")
    print()
    print("=== Linked PR evidence ===")
    if not prs:
        print("NO_LINKED_PR: no exact #issue reference found in PR title/body")
    else:
        for pr in prs:
            number = int(pr["number"])
            merged = pr.get("state") == "MERGED" and pr.get("mergedAt")
            commit = landed_commit(number) if merged else None
            files = pull_request_files(args.repo, number) if merged else []
            channels = []
            if any(path.startswith("frontend/") for path in files):
                channels.append("frontend")
            if any(path.startswith(("backend/", "scripts/", ".github/workflows/")) for path in files):
                channels.append("backend-or-ops")
            if not channels:
                channels.append("non-runtime-or-unknown")
            print(f"PR #{number}: state={pr.get('state')} merged_at={pr.get('mergedAt') or 'none'}")
            print(f"  url: {pr.get('url', '')}")
            print(f"  landed_commit: {commit or 'NOT_VERIFIED'}")
            print(f"  changed_channels: {','.join(channels)}")
            if commit:
                try:
                    release_count, releases = release_evidence(args.repo, commit)
                except TraceError:
                    release_count, releases = -1, []
                if release_count < 0:
                    print("  release_evidence: NOT_VERIFIED (GitHub release query failed)")
                elif releases:
                    print(f"  release_evidence: {release_count} release(s); latest matching tags:")
                    for release in releases:
                        tag = release.get("tagName", "")
                        print(f"    {tag} https://github.com/{args.repo}/releases/tag/{tag}")
                else:
                    print("  release_evidence: NONE")
                try:
                    runs = deploy_runs(args.repo, commit)
                except TraceError:
                    runs = []
                    print("  deploy_runs: NOT_VERIFIED (GitHub Actions query failed)")
                if runs:
                    for run in runs:
                        print(
                            "  deploy_run: "
                            f"{run.get('databaseId')} status={run.get('status')} "
                            f"conclusion={run.get('conclusion') or 'none'} url={run.get('url', '')}"
                        )
                else:
                    print("  deploy_runs: NONE_FOR_LANDED_COMMIT")
            else:
                print("  release_evidence: NOT_VERIFIED")
                print("  deploy_runs: NOT_VERIFIED")

    print()
    print("=== Production runtime evidence ===")
    backend = str(manifest.get("backend_sha") or "")
    frontend = str(manifest.get("frontend_build_sha") or manifest.get("frontend_sha") or "")
    print(f"deployment_manifest: {args.production_url.rstrip('/')}/deployment.json")
    print(f"backend_sha: {backend or 'MISSING'}")
    print(f"frontend_build_sha: {frontend or 'MISSING'}")
    print(f"deployed_at: {manifest.get('deployed_at') or 'MISSING'}")
    for pr in prs:
        if pr.get("state") != "MERGED" or not pr.get("mergedAt"):
            continue
        commit = landed_commit(int(pr["number"]))
        if commit:
            print(f"commit {commit} -> backend: {status(is_ancestor(commit, backend))}; frontend: {status(is_ancestor(commit, frontend))}")

    print()
    print("=== In-app Phase C evidence ===")
    print("NOT_VERIFIED: this read-only trace cannot infer app status; require target-correct Phase-C evidence before marking resolved")
    print()
    print("=== Decision ===")
    print("MERGE_AND_RUNTIME_EVIDENCE_REPORTED; GitHub issue closure and in-app resolved state remain separate decisions")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
