#!/usr/bin/env python3
"""Tests for scripts/check-single-source-rules.py (run: python3 scripts/tests/test_single_source_rules.py)."""
import importlib.util
import pathlib
import unittest

SRC = pathlib.Path(__file__).resolve().parents[1] / "check-single-source-rules.py"
spec = importlib.util.spec_from_file_location("single_source", SRC)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
OWNER_OF = {rule: owner for rule, owner, _ in mod.RULES}

OWNERS = {
    "docs/governance/FOUNDER_STANDING_DECISIONS.md": "3. **PR 大小**：700 行上限只算邏輯程式（測試、文件不算），另設全部 1500 行上限。",
    "docs/sop/DELIVERY_LIFECYCLE.md": "| 2 | 分診 | `triage-lead` | P0 ≤ 4 小時、P1 ≤ 24 小時 |",
    "docs/governance/RISK_BASED_MERGE_POLICY.md": "| **R0 / T0** | Docs | Required checks |",
}

# Text that restates a rule (all seen in this repo before #4954).
DEFINITIONS = [
    ("pr-logic-line-limit", "- Presubmit size: 700 logic lines (tests, docs, generated and baseline excluded)"),
    ("pr-logic-line-limit", "- **PR size**：presubmit CHECK 2 硬上限 **700 行**（含增刪，排除 lock/data）。"),
    ("pr-logic-line-limit", "❌ 拒絕：> 700 行 → 拆成多個 PR"),
    ("pr-total-line-limit", "and 1500 lines in all; split, don't stack."),
    ("triage-sla", "| `triage_sla.targets_hours` | P0 = 4h, P1 = 24h, P2 = 168h |"),
    ("risk-tier-definitions", "| T0/R0 | Required checks and docs gates | No-op |"),
    ("risk-tier-definitions", "| R2/T2 | Exact-target required CI |"),
    ("risk-tier-definitions", "| T3 Safety-critical | auth、PII | PLAN/ARCH |"),
    ("risk-tier-definitions", "- T0：docs-only / 規則文字 / README 展示。"),
]

# Links and passing mentions that must stay allowed.
MENTIONS = [
    "- Presubmit size limits: defined only in [`FOUNDER_STANDING_DECISIONS.md`](x) 2026-10-28（二）item 3.",
    "a changed-line budget taken from what is left of the PR's 700-line presubmit limit",
    "PR 是否過大（presubmit ≤700 行）、是否卡 review",
    "多收 NT$ 1,500（舊資料）；`GET /api/v1/class-sessions` | ≤ 800ms | ≤ 1500ms |",
    "分診 SLA 見 `DELIVERY_LIFECYCLE.md`；P0 是最高優先級。",
    "| R0/R1 engineering delivery | Exact production SHA |",
    "| R1 | 要修改 `/home/admin/` 內既有檔 | ❌ 停 |",
    "| T1 | Existence enumeration | 4 |",
    "| 快速 | R0、R1 | `triage-lead` 分診 |",
    "- 嚴格車道（R3）另由新 context 的 `security-data-reviewer` 獨立審查。",
]


class T(unittest.TestCase):
    def check(self, extra):
        return mod.find_violations({**OWNERS, **extra})

    def test_owners_alone_pass(self):
        self.assertEqual(self.check({}), [])

    def test_definition_outside_owner_fails(self):
        for rule, text in DEFINITIONS:
            with self.subTest(text=text):
                self.assertEqual(self.check({"docs/X.md": "intro\n" + text}), [
                    f"docs/X.md:2: {rule} is defined here; only {OWNER_OF[rule]} may define it, link to it instead"])

    def test_contradicting_size_wording_fails(self):
        for text in ["G1 keeps Presubmit **≤700** hard gate (no self-exemption).", "（hard 700；", "建議：單一 PR ≤ 400 行"]:
            with self.subTest(text=text):
                self.assertTrue(any("pr-size-contradiction" in v for v in self.check({"docs/X.md": text})))

    def test_links_and_mentions_pass(self):
        for text in MENTIONS:
            with self.subTest(text=text):
                self.assertEqual(self.check({"docs/X.md": text}), [])

    def test_one_message_per_line(self):
        out = self.check({"docs/X.md": "P0 = 4h, P1 = 24h, P2 = 168h"})
        self.assertEqual(len(out), 1, out)

    def test_history_is_not_scanned(self):
        for path in ("docs/plans/p.md", "docs/changes/c.md", "docs/archive/a.md", "docs/governance/DECISION_RECORD_x.md"):
            with self.subTest(path=path):
                self.assertEqual(self.check({path: DEFINITIONS[0][1]}), [])

    def test_owner_that_stops_defining_fails(self):
        out = self.check({"docs/sop/DELIVERY_LIFECYCLE.md": "分診 SLA：見別處"})
        self.assertEqual(out, ["docs/sop/DELIVERY_LIFECYCLE.md: triage-sla: the owner no longer defines this rule; "
                               "fix RULES or the owner"])


if __name__ == "__main__":
    unittest.main()
