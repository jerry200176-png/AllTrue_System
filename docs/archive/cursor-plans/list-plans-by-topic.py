#!/usr/bin/env python3
"""依檔名關鍵字將 *.plan.md 分桶；可寫入索引檔或輸出 JSON。"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

# 順序有意義：先匹配較具體的主題
RULES: list[tuple[str, list[str]]] = [
    ("繳費／學收／催繳／核帳", [
        r"繳費|催繳|學收|payment|session_payment|paid.status|核帳|顯示當期繳費|月報|package.remaining|剩餘堂數|建立課程繳費|繳費單|繳費清除|幽靈課程"
    ]),
    ("調課／代課／排課／行事曆", [
        r"代課|調課|substitute|reschedule|smartcalendar|calendar_|排課|課表|weekday|連假|請假調課|同日|幽靈時段|容量|false.positive|fix_cancel_session|fix_course_start_date_session|single_session_duration|duplicate-course|智慧排課|課堂缺失|日檢視"
    ]),
    ("出缺勤／點名／手機", [
        r"出缺勤|點名|attendance|補點名|已上|merge_excused|請假後|mobile_attendance|老師手機"
    ]),
    ("學習評量／學習紀錄", [
        r"評量|learning.record|learning.fullflow|核准評量|週考|匯出|一眼辨識|行事曆評量"
    ]),
    ("薪資／兼職", [
        r"薪資|兼職|payroll|parttime|同層級重疊|單堂費用|月結制|多科共用方案"
    ]),
    ("LINE／家長／綁定", [
        r"^line|LINE|liff|parent|家長|student.line|multi.parent|binding"
    ]),
    ("課程管理／契約／堂次", [
        r"課程管理|course_management|course_start|schedules_post|契約|堂次|加購|編輯課程|單堂加課|續報"
    ]),
    ("主任／分校／權限", [
        r"主任|跨校|分校|luzhou|teacher.scope|large.branch"
    ]),
    ("基礎建設／效能／備份", [
        r"github|db_optimization|opcache|pi.storage|websocket|staff.chat|drift|auto_update|internal.chat|內部聊天"
    ]),
    ("其他／通用修正", [
        r".*"
    ]),
]


def collect_plan_paths(root: Path, include_archive: bool) -> list[Path]:
    if include_archive:
        return sorted(p for p in root.rglob("*.plan.md") if p.is_file())
    return sorted(root.glob("*.plan.md"))


def _classify_impl(paths: list[Path], plans_root: Path) -> dict[str, list[str]]:
    buckets: dict[str, list[str]] = {label: [] for label, _ in RULES}
    for f in paths:
        try:
            rel = str(f.relative_to(plans_root))
        except ValueError:
            rel = f.name
        name = f.name
        for label, pats in RULES:
            if any(re.search(p, name, re.I) for p in pats):
                buckets[label].append(rel)
                break
    for label in buckets:
        buckets[label].sort()
    return buckets


def render_markdown(buckets: dict[str, list[str]], generated_at: str, scope_note: str) -> str:
    lines = [
        "# Plan 主題索引（自動生成）",
        "",
        f"> 產生時間（UTC）：{generated_at}",
        f"> 範圍：{scope_note}",
        "> **請勿手動編輯本檔。** 更新請執行：`python3 list-plans-by-topic.py --write-index`",
        "",
    ]
    for label, items in buckets.items():
        if not items:
            continue
        lines.append(f"## {label} ({len(items)})")
        lines.append("")
        for x in items:
            lines.append(f"- `{x}`")
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def main() -> None:
    parser = argparse.ArgumentParser(description="依檔名關鍵字分類 Cursor plan 檔")
    parser.add_argument(
        "--all",
        action="store_true",
        help="含 archive/ 底下所有 *.plan.md（相對路徑顯示）",
    )
    parser.add_argument(
        "--write-index",
        metavar="FILE",
        nargs="?",
        const="TOPIC-INDEX.md",
        default=None,
        help="寫入 Markdown 索引（預設：TOPIC-INDEX.md）",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="輸出 JSON 至 stdout（與 --write-index 併用時仍會印出）",
    )
    parser.add_argument(
        "-q", "--quiet",
        action="store_true",
        help="不將分類 Markdown 印到 stdout（仍會寫入 --write-index 指定檔）",
    )
    parser.add_argument(
        "--print",
        dest="print_md",
        action="store_true",
        help="與 --write-index 併用時仍將分類 Markdown 印到 stdout",
    )
    args = parser.parse_args()

    root = Path(__file__).resolve().parent
    paths = collect_plan_paths(root, include_archive=args.all)
    buckets = _classify_impl(paths, root)

    scope = "plans/ 根目錄 active *.plan.md" if not args.all else "plans/ 底下全部 *.plan.md（含 archive）"
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")

    if args.json:
        print(json.dumps({"generated_at_utc": now, "scope": scope, "buckets": buckets}, ensure_ascii=False, indent=2))

    # 預設印出分類；若只寫索引不重複刷屏，除非加 --print
    show_md = not args.quiet and not args.json
    if show_md and (args.write_index is None or args.print_md):
        print(render_markdown(buckets, now, scope).replace("# Plan 主題索引（自動生成）", "# 主題分類（stdout）", 1))

    if args.write_index is not None:
        out = root / args.write_index
        body = render_markdown(buckets, now, scope)
        out.write_text(body, encoding="utf-8")
        if not args.quiet:
            print(f"Wrote {out}", file=sys.stderr)


if __name__ == "__main__":
    main()
