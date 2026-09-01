#!/usr/bin/env python3
"""Build the monthly technical-health scorecard.

The report is deliberately read-only.  GitHub data is fetched through ``gh``
and git history is inspected locally; no issue, repository, or production
state is changed.  The output is suitable for a monthly review and keeps
missing evidence explicit instead of treating it as zero.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import tempfile
import zipfile
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterable


ROOT = Path(__file__).resolve().parents[2]


def flatten_pages(value: Any) -> list[dict[str, Any]]:
    """Flatten the nested arrays emitted by ``gh api --paginate --slurp``."""
    if not isinstance(value, list):
        return []
    rows: list[dict[str, Any]] = []
    for page in value:
        if isinstance(page, list):
            rows.extend(row for row in page if isinstance(row, dict))
        elif isinstance(page, dict):
            rows.append(page)
    return rows


def gh_json(repo: str, endpoint: str, *, paginate: bool = False) -> Any:
    command = ["gh", "api"]
    if paginate:
        command.extend(["--paginate", "--slurp"])
    command.extend([endpoint, "-H", "Accept: application/vnd.github+json"])
    try:
        result = subprocess.run(command, capture_output=True, text=True, check=False, timeout=30)
    except subprocess.TimeoutExpired as exc:
        raise RuntimeError(f"gh api timed out after 30s: {endpoint}") from exc
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "gh api failed")
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"invalid GitHub JSON from {endpoint}: {exc}") from exc


def ci_failure_rate(runs: Iterable[dict[str, Any]], limit: int = 30) -> dict[str, Any]:
    completed = [
        run
        for run in list(runs)[:limit]
        if run.get("status") == "completed"
        and run.get("conclusion") not in (None, "skipped")
    ]
    failures = [run for run in completed if run.get("conclusion") != "success"]
    rate = round(len(failures) / len(completed) * 100, 1) if completed else None
    return {
        "sample_size": len(completed),
        "failures": len(failures),
        "failure_rate_pct": rate,
        "status": "ok" if completed else "unavailable",
    }


def parse_frontend_coverage(root: Path) -> dict[str, float] | None:
    for path in root.rglob("coverage-summary.json"):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            lines = data.get("total", {}).get("lines", {}).get("pct")
            if isinstance(lines, (int, float)):
                return {"lines_pct": round(float(lines), 1)}
        except (OSError, json.JSONDecodeError):
            continue
    return None


def parse_backend_coverage(root: Path) -> dict[str, float] | None:
    import xml.etree.ElementTree as ET

    for path in root.rglob("coverage.xml"):
        try:
            metrics = ET.parse(path).find(".//project/metrics")
            if metrics is None:
                continue
            statements = int(metrics.get("statements", "0"))
            covered = int(metrics.get("coveredstatements", "0"))
            if statements:
                return {"statements_pct": round(covered / statements * 100, 1)}
        except (OSError, ET.ParseError, ValueError):
            continue
    return None


def download_coverage(repo: str, run_id: int, artifact_name: str, target: Path) -> None:
    artifacts = gh_json(repo, f"/repos/{repo}/actions/runs/{run_id}/artifacts")
    artifact = next(
        (
            item
            for item in artifacts.get("artifacts", [])
            if item.get("name") == artifact_name and not item.get("expired")
        ),
        None,
    )
    if not artifact:
        return
    with target.open("wb") as output:
        try:
            result = subprocess.run(
                [
                    "gh",
                    "api",
                    f"/repos/{repo}/actions/artifacts/{artifact['id']}/zip",
                    "-H",
                    "Accept: application/vnd.github+json",
                ],
                stdout=output,
                stderr=subprocess.PIPE,
                check=False,
                timeout=30,
            )
        except subprocess.TimeoutExpired as exc:
            target.unlink(missing_ok=True)
            raise RuntimeError("coverage artifact download timed out after 30s") from exc
    if result.returncode != 0:
        target.unlink(missing_ok=True)
        raise RuntimeError(result.stderr.decode(errors="replace").strip() or "artifact download failed")


def coverage_for_run(repo: str, run_id: int, work: Path) -> dict[str, Any]:
    extracted = work / str(run_id)
    extracted.mkdir()
    found = False
    for artifact_name in ("frontend-unit-coverage", "junit-test-results"):
        archive = work / f"{run_id}-{artifact_name}.zip"
        try:
            download_coverage(repo, run_id, artifact_name, archive)
        except RuntimeError:
            continue
        if not archive.exists():
            continue
        found = True
        try:
            with zipfile.ZipFile(archive) as bundle:
                bundle.extractall(extracted)
        except zipfile.BadZipFile:
            continue
    if not found:
        return {"status": "unavailable", "run_id": run_id}
    frontend = parse_frontend_coverage(extracted)
    backend = parse_backend_coverage(extracted)
    return {
        "status": "ok" if frontend or backend else "unavailable",
        "run_id": run_id,
        "frontend": frontend,
        "backend": backend,
    }


def distinct_successful_runs(runs: Iterable[dict[str, Any]], limit: int = 2) -> list[dict[str, Any]]:
    selected: list[dict[str, Any]] = []
    seen_revisions: set[str] = set()
    for run in runs:
        revision = run.get("head_sha")
        if (
            run.get("status") == "completed"
            and run.get("conclusion") == "success"
            and revision
            and revision not in seen_revisions
        ):
            selected.append(run)
            seen_revisions.add(revision)
        if len(selected) == limit:
            break
    return selected


def coverage_trend(repo: str, runs: list[dict[str, Any]], work: Path) -> dict[str, Any]:
    successful = distinct_successful_runs(runs)
    snapshots = [coverage_for_run(repo, int(run["id"]), work) for run in successful]
    for snapshot, run in zip(snapshots, successful):
        snapshot["head_sha"] = run.get("head_sha")
    current = snapshots[0] if snapshots else {"status": "unavailable"}
    previous = snapshots[1] if len(snapshots) > 1 else {"status": "unavailable"}
    result: dict[str, Any] = {"current": current, "previous": previous}
    current_lines = (current.get("frontend") or {}).get("lines_pct")
    previous_lines = (previous.get("frontend") or {}).get("lines_pct")
    if current_lines is not None and previous_lines is not None:
        result["frontend_lines_delta_pct"] = round(current_lines - previous_lines, 1)
    else:
        result["frontend_lines_delta_pct"] = None
    return result


def baseline_count(text: str) -> int:
    return sum(1 for line in text.splitlines() if re.fullmatch(r"\s{2,}-\s*", line))


def git_text(*args: str, cwd: Path = ROOT) -> str:
    result = subprocess.run(["git", *args], cwd=cwd, capture_output=True, text=True, check=False)
    return result.stdout if result.returncode == 0 else ""


def phpstan_delta(root: Path, as_of: datetime, days: int) -> dict[str, Any]:
    path = root / "backend/phpstan-baseline.neon"
    try:
        current = baseline_count(path.read_text(encoding="utf-8"))
    except OSError:
        return {"status": "unavailable"}
    cutoff = (as_of - timedelta(days=days)).isoformat()
    revision = git_text("rev-list", "-1", f"--before={cutoff}", "HEAD", "--", str(path.relative_to(root))).strip()
    if not revision:
        return {"status": "ok", "current_entries": current, "delta": None, "reference": None}
    old = git_text("show", f"{revision}:backend/phpstan-baseline.neon", cwd=root)
    previous = baseline_count(old) if old else None
    return {
        "status": "ok",
        "current_entries": current,
        "reference_entries": previous,
        "delta": current - previous if previous is not None else None,
        "reference_revision": revision,
    }


def open_tech_debt(issues: Iterable[dict[str, Any]]) -> dict[str, Any]:
    candidates: list[dict[str, Any]] = []
    for issue in issues:
        if "pull_request" in issue:
            continue
        labels = {label.get("name") for label in issue.get("labels", [])}
        priority = next((p for p in ("priority:p1", "priority:p2") if p in labels), None)
        if "type:tech-debt" not in labels or priority is None:
            continue
        candidates.append(
            {
                "number": issue.get("number"),
                "title": issue.get("title", ""),
                "priority": priority,
                "created_at": issue.get("created_at"),
                "url": issue.get("html_url"),
                "next_step": "revalidate issue body/evidence before changing status",
            }
        )
    candidates.sort(key=lambda item: (0 if item["priority"] == "priority:p1" else 1, item["created_at"] or ""))
    return {"count": len(candidates), "roadmap_candidates": candidates[:2]}


def recurrence_families(text: str) -> dict[str, Any]:
    marker = "# 🔁 復發家族"
    section = text.split(marker, 1)[1] if marker in text else ""
    families = re.findall(r"^\|\s*\*\*(F\d+)\b", section, re.MULTILINE)
    return {"count": len(families), "families": families}


def hot_files(log_text: str, limit: int = 10) -> dict[str, Any]:
    counts: Counter[str] = Counter()
    bugfix = False
    commit_files: set[str] = set()

    def flush_commit() -> None:
        if bugfix:
            counts.update(commit_files)

    for line in log_text.splitlines():
        if line.startswith("commit\0"):
            flush_commit()
            subject = line.split("\0", 1)[1]
            bugfix = bool(re.search(r"\b(fix|hotfix|bug|regression|repair)\b", subject, re.IGNORECASE))
            commit_files = set()
            continue
        if bugfix and line.startswith(("backend/app/", "frontend/src/")):
            commit_files.add(line)
    flush_commit()
    rows = [{"path": path, "bug_fix_commits": count} for path, count in counts.most_common()]
    return {"window": "90 days", "files": rows[:limit], "over_five": [row for row in rows if row["bug_fix_commits"] > 5]}


def build_report(repo: str, root: Path, as_of: datetime, output_dir: Path) -> dict[str, Any]:
    run_payload = gh_json(
        repo,
        f"/repos/{repo}/actions/workflows/ci.yml/runs?branch=main&per_page=100&exclude_pull_requests=true",
    )
    runs = run_payload.get("workflow_runs", []) if isinstance(run_payload, dict) else []
    issues = flatten_pages(gh_json(repo, f"/repos/{repo}/issues?state=open&per_page=100", paginate=True))
    with tempfile.TemporaryDirectory(prefix="technical-health-scorecard-") as temporary:
        coverage = coverage_trend(repo, runs, Path(temporary))
    lessons = (root / "docs/AI_REGRESSION_LESSONS.md").read_text(encoding="utf-8")
    log = git_text("log", "--since=90 days ago", "--format=commit%x00%s", "--name-only", cwd=root)
    report = {
        "schema": 1,
        "scorecard": "technical_health",
        "as_of": as_of.isoformat().replace("+00:00", "Z"),
        "repository": repo,
        "revision": git_text("rev-parse", "HEAD", cwd=root).strip(),
        "metrics": {
            "ci_failure_rate": ci_failure_rate(runs),
            "coverage": coverage,
            "phpstan_baseline": phpstan_delta(root, as_of, 30),
            "open_p1_p2_tech_debt": open_tech_debt(issues),
            "recurrence_families": recurrence_families(lessons),
            "hot_files": hot_files(log),
        },
        "evidence_policy": "GitHub labels are candidate filters only; each roadmap item requires current issue/body/runtime revalidation before status changes.",
    }
    output_dir.mkdir(parents=True, exist_ok=True)
    month = as_of.strftime("%Y-%m")
    json_path = output_dir / f"technical-health-scorecard-{month}.json"
    md_path = output_dir / f"technical-health-scorecard-{month}.md"
    latest_path = output_dir / "technical-health-scorecard-latest.md"
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    markdown = render_markdown(report)
    md_path.write_text(markdown, encoding="utf-8")
    latest_path.write_text(markdown, encoding="utf-8")
    report["output"] = {"json": str(json_path), "markdown": str(md_path), "latest": str(latest_path)}
    return report


def render_markdown(report: dict[str, Any]) -> str:
    metrics = report["metrics"]
    ci = metrics["ci_failure_rate"]
    coverage = metrics["coverage"]
    phpstan = metrics["phpstan_baseline"]
    debt = metrics["open_p1_p2_tech_debt"]
    recurrence = metrics["recurrence_families"]
    hot = metrics["hot_files"]
    lines = [
        "# Technical Health Scorecard",
        "",
        f"- as_of: `{report['as_of']}`",
        f"- revision: `{report['revision']}`",
        "- scope: read-only monthly engineering health review",
        "",
        "| Metric | Current evidence | Interpretation |",
        "|---|---:|---|",
        f"| CI failure rate (last 30 completed main runs) | {ci['failure_rate_pct'] if ci['failure_rate_pct'] is not None else 'unavailable'}% ({ci['failures']}/{ci['sample_size']}) | completed CI runs only; unavailable is not zero |",
        f"| Frontend line coverage trend | {coverage.get('current', {}).get('frontend', {}).get('lines_pct', 'unavailable')}% / delta {coverage.get('frontend_lines_delta_pct', 'unavailable')} pp | latest two successful CI artifacts |",
        f"| PHPStan baseline | {phpstan.get('current_entries', 'unavailable')} entries / delta {phpstan.get('delta', 'unavailable')} | compared with ~30-day git reference |",
        f"| Open P1/P2 technical debt | {debt['count']} | candidate count; labels do not prove blocker truth |",
        f"| Recurrence families | {recurrence['count']} ({', '.join(recurrence['families'])}) | registry in `AI_REGRESSION_LESSONS.md` |",
        f"| Hot source files (>5 bug-fix commits / 90d) | {len(hot['over_five'])} | production source paths only |",
        "",
        "## Roadmap candidates",
        "",
    ]
    candidates = debt["roadmap_candidates"]
    if candidates:
        for candidate in candidates:
            url = candidate.get("url") or f"https://github.com/{report['repository']}/issues/{candidate['number']}"
            lines.append(f"1. [#{candidate['number']} — {candidate['title']}]({url}) ({candidate['priority']}) — revalidate current evidence, then choose the next bounded payoff.")
    else:
        lines.append("- No P1/P2 technical-debt candidates were returned; investigate the data source before treating this as zero.")
    lines.extend(
        [
            "",
            "## Hot files",
            "",
        ]
    )
    if hot["files"]:
        lines.extend(f"- `{row['path']}` — {row['bug_fix_commits']} bug-fix commits" for row in hot["files"])
    else:
        lines.append("- unavailable")
    lines.extend(
        [
            "",
            "> Roadmap candidates are not closure evidence. Re-check the issue body, current code, production SHA, and required owner decision before changing any issue status.",
            "",
        ]
    )
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", default=None, help="owner/repository; defaults to GH_REPO or GITHUB_REPOSITORY")
    parser.add_argument("--output-dir", type=Path, default=ROOT / "docs/radars/runs")
    parser.add_argument("--as-of", help="UTC ISO timestamp, useful for reproducible reports")
    args = parser.parse_args()
    repo = args.repo or __import__("os").environ.get("GH_REPO") or __import__("os").environ.get("GITHUB_REPOSITORY")
    if not repo:
        print("missing --repo, GH_REPO, or GITHUB_REPOSITORY", file=sys.stderr)
        return 2
    as_of = datetime.fromisoformat(args.as_of.replace("Z", "+00:00")) if args.as_of else datetime.now(timezone.utc)
    if as_of.tzinfo is None:
        as_of = as_of.replace(tzinfo=timezone.utc)
    try:
        report = build_report(repo, ROOT, as_of, args.output_dir)
    except RuntimeError as exc:
        print(f"scorecard unavailable: {exc}", file=sys.stderr)
        return 1
    print(render_markdown(report))
    print(f"Wrote {report['output']['json']}")
    print(f"Wrote {report['output']['markdown']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
