#!/usr/bin/env python3
"""F14: report where in-app reports and GitHub issues disagree.

Read-only. Inputs are the bug-queue-dump artifact directory and
`gh issue list --state all --json number,title,body,state,labels,author`.
In-app ids missing from open/resolved (and <= max_id) are closed.

Usage:
  python3 scripts/inapp-issue-reconcile.py --dump <bug-queue-dump dir> --issues issues.json
"""
import argparse
import json
import re
from collections import defaultdict
from pathlib import Path

INAPP_REF = re.compile(r"in-app\s*#(\d+)|alltrue:bug_report:(\d+)", re.I)
SENTRY_SPAN = re.compile(r"\*\*Offending Spans\*\*\s*\|\s*([^|\n]+)")
# Logged suggestions (F12): the issue is the backlog, so it stays open after the in-app report closes.
LOGGED_LABEL = "in-app:logged"


def inapp_ids(issue):
    text = f"{issue.get('title', '')}\n{issue.get('body') or ''}"
    return sorted({int(a or b) for a, b in INAPP_REF.findall(text)})


def reconcile(open_bugs, resolved_bugs, max_id, issues):
    status = {b["id"]: b["status"] for b in open_bugs + resolved_bugs}

    def inapp_status(i):
        return status.get(i, "closed" if i <= max_id else "unknown")

    out = {"inapp_done_issue_open": [], "issue_closed_inapp_open": [], "sentry_duplicates": [], "unlabeled": []}
    spans = defaultdict(list)
    for issue in issues:
        ids = inapp_ids(issue)
        labels = {l["name"] for l in issue.get("labels", [])}
        is_open = issue.get("state", "OPEN").upper() == "OPEN"
        sts = {i: inapp_status(i) for i in ids}
        if ids and is_open and LOGGED_LABEL not in labels and all(s in ("resolved", "closed") for s in sts.values()):
            out["inapp_done_issue_open"].append({"issue": issue["number"], "inapp": sts})
        if ids and not is_open and any(s in ("new", "triaged", "in_progress") for s in sts.values()):
            out["issue_closed_inapp_open"].append({"issue": issue["number"], "inapp": sts})
        if is_open and not labels:
            out["unlabeled"].append(issue["number"])
        author = (issue.get("author") or {}).get("login", "")
        m = SENTRY_SPAN.search(issue.get("body") or "")
        if is_open and author.endswith("sentry") and m:
            spans[m.group(1).strip()].append(issue["number"])
    for span, numbers in spans.items():
        if len(numbers) > 1:
            keep, *dupes = sorted(numbers)
            out["sentry_duplicates"].append({"keep": keep, "duplicates": dupes, "span": span[:80]})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dump", required=True, type=Path)
    ap.add_argument("--issues", required=True, type=Path)
    a = ap.parse_args()
    meta = json.loads((a.dump / "meta.json").read_text())
    result = reconcile(
        json.loads((a.dump / "open-bugs.json").read_text()),
        json.loads((a.dump / "resolved-bugs.json").read_text()),
        int(meta["max_id"]),
        json.loads(a.issues.read_text()),
    )
    for key, rows in result.items():
        print(f"## {key}: {len(rows)}")
        for row in rows:
            print(f"- {json.dumps(row, ensure_ascii=False)}")


if __name__ == "__main__":
    main()
