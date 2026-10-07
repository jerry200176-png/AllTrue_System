#!/usr/bin/env python3
"""Monday one-pager for the founder: plain-Chinese weekly in-app report (IDs only).

Inputs: the redacted bug-sla-weekly snapshot (production, IDs only) and
`gh issue list --state all --json number,title,body,comments,state,labels`
(only used to map in-app IDs to their `area:*` family label).
The repository is public, so only integers and label names ever reach the output.

Usage: inapp-weekly-onepager.py --snapshot S.json --issues I.json --out-dir DIR [--now ISO]
Writes DIR/title.txt and DIR/body.md. Exits non-zero on any malformed input (fail closed).
"""
import argparse
import importlib.util
import json
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

TAIPEI = timezone(timedelta(hours=8))
FAMILY_ZH = {
    "area:billing": "帳務／繳費", "area:attendance": "出缺勤", "area:calendar": "行事曆／排課",
    "area:ui": "畫面操作", "area:parent-portal": "家長端", "area:learning-records": "學習紀錄",
    "area:finance": "財務報表", "area:engagement": "互動通知",
}
SECTIONS = ("new", "resolved", "closed", "reopened")


def _load_reconcile():
    path = Path(__file__).with_name("inapp-issue-reconcile.py")
    spec = importlib.util.spec_from_file_location("inapp_issue_reconcile", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def int_list(value, name):
    if not isinstance(value, list) or any(type(x) is not int or x <= 0 for x in value):
        raise SystemExit(f"snapshot field {name} must be a list of positive integers")
    return sorted(set(value))


def parse_snapshot(snapshot):
    weekly = snapshot.get("weekly")
    if not isinstance(weekly, dict):
        raise SystemExit("snapshot has no weekly section")
    out = {k: int_list(weekly.get(k), k) for k in SECTIONS}
    out["sla_overdue"] = int_list(weekly.get("sla_overdue"), "sla_overdue")
    age = weekly.get("open_by_age")
    if not isinstance(age, dict):
        raise SystemExit("snapshot has no open_by_age")
    out["open_by_age"] = {k: int_list(age.get(k), k) for k in ("lt7", "d7_30", "gt30")}
    return out


def trusted_issues(issues, owner):
    """Public repo: anyone can open an issue quoting a SourceRef or title. Keep only issues the
    owner or this workflow's bot authored, and drop comments from anyone else."""
    ok = {owner, "app/github-actions", "github-actions"}
    out = []
    for issue in issues:
        if (issue.get("author") or {}).get("login") not in ok:
            continue
        comments = [c for c in issue.get("comments") or [] if (c.get("author") or {}).get("login") in ok]
        out.append({**issue, "comments": comments})
    return out


def families(issues, ids, owner):
    """in-app id -> whitelisted area label of the (first) trusted issue tracking it."""
    recon = _load_reconcile()
    wanted, found = set(ids), {}
    for issue in sorted(trusted_issues(issues, owner), key=lambda i: i["number"]):
        area = sorted(l["name"] for l in issue.get("labels", []) if l["name"] in FAMILY_ZH)
        for bug_id in recon.inapp_ids(issue):
            if bug_id in wanted and bug_id not in found and area:
                found[bug_id] = area[0]
    return found


def fmt(ids):
    return "、".join(f"#{i}" for i in ids) if ids else "無"


def iso_week(now):
    year, week, _ = now.astimezone(TAIPEI).isocalendar()
    return f"{year}-W{week:02d}"


def render(snapshot, issues, now, owner):
    data = parse_snapshot(snapshot)
    title = f"in-app 週報 {iso_week(now)}"
    week_ids = sorted(set(data["new"]) | set(data["resolved"]) | set(data["closed"]) | set(data["reopened"]))
    fam = families(issues, week_ids, owner)
    counts = Counter(fam.get(i, "未分類") for i in week_ids)
    top = counts.most_common(3)
    age = data["open_by_age"]
    open_total = sum(len(v) for v in age.values())
    lines = [
        f"本週重點（自動產生，只列編號，不含人名；編號對應 in-app 回報 ID）。",
        "",
        f"- 本週新進：{len(data['new'])} 筆（{fmt(data['new'])}）",
        f"- 本週已修好（等回報者確認）：{len(data['resolved'])} 筆（{fmt(data['resolved'])}）",
        f"- 本週已結案：{len(data['closed'])} 筆（{fmt(data['closed'])}）",
        f"- 還開著：共 {open_total} 筆",
        f"  - 7 天內：{len(age['lt7'])} 筆（{fmt(age['lt7'])}）",
        f"  - 7 到 30 天：{len(age['d7_30'])} 筆（{fmt(age['d7_30'])}）",
        f"  - 超過 30 天：{len(age['gt30'])} 筆（{fmt(age['gt30'])}）",
        f"- 超過處理時限、還沒人接手：{len(data['sla_overdue'])} 筆（{fmt(data['sla_overdue'])}）",
        "- 本週最常出現的三類問題：" + ("；".join(f"{FAMILY_ZH.get(k, k)} {n} 筆" for k, n in top) if top else "無"),
        f"- 之前修過、又被回報的（重開）：{len(data['reopened'])} 筆（{fmt(data['reopened'])}）",
        "",
        f"資料產生時間：{snapshot.get('generated_at', '未知')}。來源：bug-sla-weekly-report 工作流程。",
    ]
    return title, "\n".join(lines) + "\n"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--snapshot", required=True)
    ap.add_argument("--issues", required=True)
    ap.add_argument("--out-dir", required=True)
    ap.add_argument("--now", default=None)
    ap.add_argument("--owner", required=True, help="repository owner login (trusted issue author)")
    args = ap.parse_args()
    now = datetime.fromisoformat(args.now) if args.now else datetime.now(timezone.utc)
    snapshot = json.loads(Path(args.snapshot).read_text(encoding="utf-8"))
    issues = json.loads(Path(args.issues).read_text(encoding="utf-8"))
    title, body = render(snapshot, issues, now, args.owner)
    out = Path(args.out_dir)
    out.mkdir(parents=True, exist_ok=True)
    (out / "title.txt").write_text(title, encoding="utf-8")
    (out / "body.md").write_text(body, encoding="utf-8")
    print(title)


if __name__ == "__main__":
    main()
