#!/usr/bin/env python3
"""Fail when a key rule is *defined* anywhere but its one owning doc (#4954).

Each RULES row is (rule id, owning file, regex for a definition). The regexes
match the wording that states the rule (a limit bound to what it counts, a
per-priority target, a tier used as its own table row or bullet), so links and
passing mentions of a number stay allowed. Historical records are not scanned.

Usage: python3 scripts/check-single-source-rules.py
Explained in docs/sop/DELIVERY_LIFECYCLE.md, section 單一出處檢查.
"""
from pathlib import Path
import re
import subprocess
import sys

FSD = "docs/governance/FOUNDER_STANDING_DECISIONS.md"
RULES = [
    # 700 next to what it counts ("logic lines", "只算", "含增刪"...), or used as a reject threshold.
    ("pr-logic-line-limit", FSD,
     r"700[^\n]{0,40}(?:logic lines|只算|含增刪|不算|excluded)|(?:拒絕|reject)\W{0,6}700"),
    ("pr-total-line-limit", FSD, r"1,?500\s*(?:行|lines?)"),
    ("triage-sla", "docs/sop/DELIVERY_LIFECYCLE.md",
     r"P[0-3]\s*(?:=|＝|:|：|≤|<=|<)\s*\d+\s*(?:h\b|小時|hours?)"),
    # "| **R0 / T0** |", "| T0/R0 |", "| T0 Docs-only |" table rows, or "- T3：..." bullets.
    # ponytail: a bare "| R1 |" row is not flagged (it is also the red-line table's id); add when a doc defines tiers that way.
    ("risk-tier-definitions", "docs/governance/RISK_BASED_MERGE_POLICY.md",
     r"(?m)^\|\s*\**(?:R[0-3]\s*/\s*T[0-3]|T[0-3]\s*/\s*R[0-3]|T[0-3] [A-Z][a-z-]+)\b"
     r"|^\s*[-*]\s+\**`?[RT][0-3]`?\**\s*[：:=]"),
]

# Wording that contradicts the owner's logic/total split; forbidden everywhere, owner included.
FORBIDDEN = [
    ("pr-size-contradiction",
     r"(?i)≤\s*700\W{0,4}hard|\bhard\W{1,3}700\b|no self-exemption"
     r"|(?:建議|推薦|recommended)[^\n]{0,20}≤\s*400|≤\s*400\s*行[^\n]{0,6}(?:建議|推薦)"),
]

# Dated history, evidence and vendored text: they record what was true then, not the current rule.
SKIP = (
    "docs/archive/", "docs/changes/", "docs/plans/", "docs/incidents/", "docs/audits/",
    "docs/research/", "docs/reviews/", "docs/proposals/", "docs/truefit/", "docs/radars/",
    "operations/closeout/", "frontend/vendor-modules/",
    "docs/CHANGELOG", "docs/governance/DECISION_RECORD_", "docs/governance/GOVERNANCE_CHANGELOG.md",
)


def find_violations(docs):
    """docs maps repo-relative path -> text. Returns one message per problem."""
    out = []
    for rule, owner, pattern in RULES:
        rx = re.compile(pattern)
        if not rx.search(docs.get(owner, "")):
            out.append(f"{owner}: {rule}: the owner no longer defines this rule; fix RULES or the owner")
        for path, text in sorted(docs.items()):
            if path == owner or path.startswith(SKIP):
                continue
            lines = sorted({text.count("\n", 0, m.start()) + 1 for m in rx.finditer(text)})
            out += [f"{path}:{n}: {rule} is defined here; only {owner} may define it, link to it instead" for n in lines]
    for rule, pattern in FORBIDDEN:
        rx = re.compile(pattern)
        for path, text in sorted(docs.items()):
            if path.startswith(SKIP):
                continue
            out += [f"{path}:{text.count(chr(10), 0, m.start()) + 1}: {rule}: contradicts {FSD}; link to it instead"
                    for m in rx.finditer(text)]
    return out


def main():
    root = Path(subprocess.check_output(["git", "rev-parse", "--show-toplevel"], text=True).strip())
    names = subprocess.check_output(
        ["git", "-C", str(root), "ls-files", "-z", "--", "*.md", "*.mdc"], text=True
    ).split("\0")
    docs = {n: (root / n).read_text(encoding="utf-8", errors="replace") for n in names if n and (root / n).is_file()}
    problems = find_violations(docs)
    for p in problems:
        print(p)
    if problems:
        print(f"single-source-rules: FAIL ({len(problems)}); see docs/sop/DELIVERY_LIFECYCLE.md 單一出處檢查")
        return 1
    print(f"single-source-rules: OK ({len(RULES)} rules, {len(docs)} docs)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
