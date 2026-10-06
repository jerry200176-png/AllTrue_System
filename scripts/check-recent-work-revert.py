#!/usr/bin/env python3
"""Fail if a PR deletes code that another PR merged to main within the last N days.

Catches the "stale-copy" failure (2026-10-06 PR #3631 deleted #3615/#3618): an agent
edits old copies of files, so its diff against *current* origin/main removes new work.

Base = merge-base(head, --base).
Usage: check-recent-work-revert.py [--base origin/main] [--head HEAD] [--days 7]
Bypass (in presubmit): PR label `intentional-revert` + reason in the PR body.
Pure moves are skipped: a deleted line that reappears identically among the PR's added lines.
"""
import argparse
import difflib
import re
import subprocess
import sys
import time

PATHS = ["backend/app", "frontend/src", "scripts", ".github"]
MIN_LEN = 4  # ignore `}` / `end` / `);` style lines: no signal
MAX_ROWS = 60
EDIT_RATIO = 0.8  # similarity at which a -/+ pair in one hunk counts as an edit


def git(*args):
    return subprocess.run(["git", "-c", "core.quotepath=off", *args], check=True,
                          capture_output=True, text=True, errors="replace").stdout


def parse_diff(text):
    """-> ({path: [(old_lineno, text)]}, set(stripped added lines))

    A deleted line with a near-identical added line in the same hunk is an edit, not a
    removal, so it is dropped (ponytail: edits that rewrite a line >20% are still flagged).
    """
    deleted, added, path, old = {}, set(), None, 0
    hunk_del, hunk_add = [], []

    def flush():
        for n, t in hunk_del:
            if not any(difflib.SequenceMatcher(None, t, x).quick_ratio() >= EDIT_RATIO
                       and difflib.SequenceMatcher(None, t, x).ratio() >= EDIT_RATIO
                       for x in hunk_add):
                deleted.setdefault(path, []).append((n, t))
        hunk_del.clear()
        hunk_add.clear()

    for line in text.splitlines():
        if line.startswith("--- ") or line.startswith("diff ") or line.startswith("@@"):
            flush()
            if line.startswith("--- "):
                path = line[6:] if line.startswith("--- a/") else None
            elif line.startswith("@@"):
                old = int(re.match(r"@@ -(\d+)", line)[1])
        elif line.startswith("+++ "):
            continue
        elif line.startswith("-") and path:
            hunk_del.append((old, line[1:]))
            old += 1
        elif line.startswith("+"):
            added.add(line[1:].strip())
            hunk_add.append(line[1:])
    flush()
    return deleted, added


def blame(base, path):
    """-> {lineno: (sha, committer_time, summary)} at `base`."""
    out, res, cur = git("blame", "--line-porcelain", base, "--", path), {}, None
    for line in out.splitlines():
        m = re.match(r"([0-9a-f]{40}) \d+ (\d+)", line)
        if m:
            cur = [m[1], 0, ""]
            lineno = int(m[2])
        elif line.startswith("committer-time "):
            cur[1] = int(line.split()[1])
        elif line.startswith("summary "):
            cur[2] = line[8:]
        elif line.startswith("\t"):
            res[lineno] = tuple(cur)
    return res


def find_hits(base, head, days, now=None):
    now = now or time.time()
    # Diff from the merge-base: what merging this PR would actually remove. A branch merely
    # behind main is not a revert (git keeps main's lines). Blamed commits are ancestors of
    # the merge-base, so they can never be this PR's own commits.
    base = git("merge-base", base, head).strip()
    deleted, added = parse_diff(git("diff", "-U0", "--no-renames", base, head, "--", *PATHS))
    hits = []
    for path, lines in sorted(deleted.items()):
        lines = [(n, t) for n, t in lines
                 if len(t.strip()) >= MIN_LEN and t.strip() not in added]
        if not lines:
            continue
        bl = blame(base, path)
        for n, text in lines:
            sha, ts, summary = bl.get(n, ("", 0, ""))
            if sha and now - ts <= days * 86400:
                pr = re.search(r"\(#(\d+)\)\s*$", summary)
                hits.append((path, n, sha[:9], f"#{pr[1]}" if pr else "?", text.strip()[:60]))
    return hits


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="origin/main")
    ap.add_argument("--head", default="HEAD")
    ap.add_argument("--days", type=int, default=7)
    a = ap.parse_args()
    hits = find_hits(a.base, a.head, a.days)
    if not hits:
        print(f"OK: no lines merged in the last {a.days} days are removed by this PR")
        return 0
    print(f"{'file':44} {'line':>5} {'commit':9} {'PR':>6}  deleted text")
    for p, n, sha, pr, t in hits[:MAX_ROWS]:
        print(f"{p[-44:]:44} {n:>5} {sha:9} {pr:>6}  {t}")
    if len(hits) > MAX_ROWS:
        print(f"... and {len(hits) - MAX_ROWS} more")
    print(f"\n{len(hits)} line(s): this PR removes code merged recently by another PR - "
          "rebuild from current main, or add label `intentional-revert` with a reason in the PR body")
    return 1


if __name__ == "__main__":
    sys.exit(main())
