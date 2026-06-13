#!/usr/bin/env python3
"""#731 前端單元測試覆蓋率 → GitHub Actions step summary 表格。

從 vitest 的 json-summary（coverage-summary.json）讀 total 指標，
輸出 traffic-light badge 表格到 $GITHUB_STEP_SUMMARY，並印一行到 stdout。

抽成獨立檔（原內嵌於 ci.yml heredoc）：YAML block scalar 要求縮排，
但 heredoc 會把縮排原樣傳給 Python 造成 IndentationError；外置腳本兩者皆免。

用法：python3 scripts/coverage-step-summary.py <coverage-summary.json>
"""
import json
import os
import sys


def pct(total, key):
    return round(total.get(key, {}).get("pct", 0), 1)


def badge(value, warn=70, ok=80):
    if value >= ok:
        return "\U0001f7e2"  # 🟢
    if value >= warn:
        return "\U0001f7e1"  # 🟡
    return "\U0001f534"      # 🔴


def main():
    summary_path = sys.argv[1] if len(sys.argv) > 1 else "coverage/unit/coverage-summary.json"
    with open(summary_path) as f:
        data = json.load(f)
    total = data.get("total", {})

    lines = pct(total, "lines")
    stmts = pct(total, "statements")
    funcs = pct(total, "functions")
    branches = pct(total, "branches")

    out = os.environ.get("GITHUB_STEP_SUMMARY", "/dev/null")
    with open(out, "a") as s:
        s.write("### \U0001f9ea Frontend Unit Coverage (design-system)\n\n")
        s.write("| Metric | Coverage |\n|---|---|\n")
        s.write(f"| {badge(lines)} Lines | {lines}% |\n")
        s.write(f"| {badge(stmts)} Statements | {stmts}% |\n")
        s.write(f"| {badge(funcs)} Functions | {funcs}% |\n")
        s.write(f"| {badge(branches, 60, 70)} Branches | {branches}% |\n")
        s.write("\n> Thresholds: lines/statements/functions ≥ 70 %, branches ≥ 60 %  \n")
        s.write("> Bump thresholds in `vitest.config.js` when coverage improves.\n")

    print(f"Coverage: lines={lines}% stmts={stmts}% funcs={funcs}% branches={branches}%")


if __name__ == "__main__":
    main()
