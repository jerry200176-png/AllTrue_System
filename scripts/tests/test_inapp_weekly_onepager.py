import importlib.util
import unittest
from datetime import datetime, timezone
from pathlib import Path

SCRIPTS = Path(__file__).parents[1]
spec = importlib.util.spec_from_file_location("onepager", SCRIPTS / "inapp-weekly-onepager.py")
op = importlib.util.module_from_spec(spec)
spec.loader.exec_module(op)

WEEKLY = {
    "new": [401, 402, 403], "resolved": [390], "closed": [380, 381], "reopened": [350],
    "sla_overdue": [402],
    "open_by_age": {"lt7": [401, 402, 403], "d7_30": [360], "gt30": [300]},
}
SNAP = {"generated_at": "2026-10-12T01:00:00+00:00", "weekly": WEEKLY}
ISSUES = [
    {"number": 1, "title": "[in-app #401] x", "body": "", "author": {"login": "owner"}, "labels": [{"name": "area:billing"}], "comments": []},
    {"number": 2, "title": "[in-app #402] y", "body": "", "author": {"login": "app/github-actions"}, "labels": [{"name": "area:billing"}], "comments": []},
    {"number": 3, "title": "", "body": "SourceRef: alltrue:bug_report:390", "author": {"login": "owner"}, "labels": [{"name": "area:ui"}], "comments": []},
]
OWNER = "owner"
MONDAY = datetime(2026, 10, 12, 1, 0, tzinfo=timezone.utc)  # 09:00 Taipei


class OnePagerTest(unittest.TestCase):
    def test_title_is_taipei_iso_week(self):
        title, _ = op.render(SNAP, ISSUES, MONDAY, OWNER)
        self.assertEqual(title, "in-app 週報 2026-W42")
        # Sunday 23:00 UTC is already Monday in Taipei: next ISO week.
        self.assertEqual(op.iso_week(datetime(2026, 10, 11, 17, 0, tzinfo=timezone.utc)), "2026-W42")

    def test_body_has_every_section_with_ids_only(self):
        _, body = op.render(SNAP, ISSUES, MONDAY, OWNER)
        for needle in ("#401、#402、#403", "#390", "#380、#381", "超過 30 天：1 筆（#300）", "#402", "重開）：1 筆（#350）"):
            self.assertIn(needle, body)
        self.assertIn("帳務／繳費 2 筆", body)
        self.assertLess(body.index("帳務／繳費"), body.index("畫面操作"))

    def test_hostile_public_issues_are_ignored_and_never_echoed(self):
        hostile = [
            {"number": 9, "title": "[in-app #401] @everyone [click](http://evil) $(rm -rf /)", "body": "SourceRef: alltrue:bug_report:390",
             "author": {"login": "mallory"}, "labels": [{"name": "area:ui"}, {"name": "area:<script>x</script>"}], "comments": []},
            {"number": 10, "title": "", "body": "", "author": {"login": "owner"},
             "labels": [{"name": "area:@evil [x](http://evil)"}],
             "comments": [{"author": {"login": "mallory"}, "body": "SourceRef: alltrue:bug_report:402"}]},
        ]
        _, body = op.render(SNAP, ISSUES + hostile, MONDAY, OWNER)
        _, base = op.render(SNAP, ISSUES, MONDAY, OWNER)
        self.assertEqual(body, base)  # forged refs/labels change nothing
        for bad in ("@everyone", "evil", "script", "rm -rf", "mallory"):
            self.assertNotIn(bad, body)

    def test_empty_week_renders(self):
        empty = {"weekly": {k: [] for k in ("new", "resolved", "closed", "reopened", "sla_overdue")}}
        empty["weekly"]["open_by_age"] = {"lt7": [], "d7_30": [], "gt30": []}
        _, body = op.render(empty, [], MONDAY, OWNER)
        self.assertIn("本週新進：0 筆（無）", body)
        self.assertIn("三類問題：無", body)

    def test_malformed_snapshot_fails_closed(self):
        for bad in ({}, {"weekly": {**WEEKLY, "new": ["Alice"]}}, {"weekly": {**WEEKLY, "closed": [True]}},
                    {"weekly": {**WEEKLY, "open_by_age": None}}):
            with self.assertRaises(SystemExit):
                op.render(bad, ISSUES, MONDAY, OWNER)

def _issue(num, created, labels=(), inapp=None, author="owner"):
    return {"number": num, "title": f"[in-app #{inapp or num + 1000}] t", "body": "", "author": {"login": author},
            "createdAt": created, "labels": [{"name": n} for n in labels], "comments": []}


FAM_ISSUES = [
    _issue(11, "2026-09-03T01:00:00Z", ["rootfam:money"]),
    _issue(12, "2026-09-20T01:00:00Z", ["rootfam:money", "area:billing"]),
    _issue(13, "2026-10-02T01:00:00Z", ["rootfam:money"]),
    _issue(14, "2026-10-05T01:00:00Z", ["rootfam:ux-affordance"]),
    _issue(15, "2026-10-06T01:00:00Z", []),  # unlabelled
    _issue(16, "2026-10-07T01:00:00Z", ["rootfam:money", "rootfam:write-guard"]),  # conflict
    _issue(17, "2026-10-08T01:00:00Z", ["rootfam:bogus"]),  # unknown slug counts as unlabelled
    _issue(18, "2026-10-09T01:00:00Z", ["rootfam:money"], author="mallory"),  # untrusted: ignored
    {"number": 19, "title": "no in-app ref", "body": "", "author": {"login": "owner"},
     "createdAt": "2026-10-09T01:00:00Z", "labels": [{"name": "rootfam:money"}], "comments": []},
]


class FamilyTableTest(unittest.TestCase):
    def test_counts_by_family_and_month(self):
        t = op.family_table(FAM_ISSUES, MONDAY, OWNER)
        self.assertEqual(t["rows"]["rootfam:money"], {"2026-09": 2, "2026-10": 1})
        self.assertEqual(t["rows"]["rootfam:ux-affordance"], {"2026-10": 1})
        self.assertEqual(t["unlabelled"], {"2026-10": 2})
        self.assertEqual(t["conflict"], {"2026-10": 1})
        self.assertNotIn("rootfam:bogus", t["rows"])

    def test_repeat_family_last_30_days(self):
        t = op.family_table(FAM_ISSUES, MONDAY, OWNER)
        # money has #12 and #13 inside the 30-day window (#16 is a conflict, not counted): 2, so not yet a repeat.
        self.assertEqual(t["repeat"], [])
        more = FAM_ISSUES + [_issue(20, "2026-10-10T01:00:00Z", ["rootfam:money"]), _issue(21, "2026-10-11T01:00:00Z", ["rootfam:money"])]
        self.assertEqual(op.family_table(more, MONDAY, OWNER)["repeat"], ["rootfam:money"])

    def test_body_has_table_with_unlabelled_row_and_no_free_text(self):
        _, body = op.render(SNAP, ISSUES + FAM_ISSUES, MONDAY, OWNER)
        self.assertIn("根因家族 × 月份", body)
        self.assertIn("| rootfam:money | 2 | 1 |", body)
        self.assertIn("| 未標家族 | 0 | 2 |", body)
        self.assertIn("重複出現的家族（近 30 天 ≥ 3 筆）：0 類", body)
        self.assertNotIn("mallory", body)

    def test_slugs_match_labels_yml(self):
        text = (SCRIPTS.parent / ".github" / "labels.yml").read_text(encoding="utf-8")
        import re
        self.assertEqual(set(re.findall(r'name: "(rootfam:[a-z0-9-]+)"', text)), set(op.ROOTFAM))

    def test_month_uses_taipei_time(self):
        edge = [_issue(30, "2026-09-30T17:00:00Z", ["rootfam:money"]), _issue(31, "2026-09-30T15:59:00Z", ["rootfam:money"])]
        self.assertEqual(op.family_table(edge, MONDAY, OWNER)["rows"]["rootfam:money"], {"2026-09": 1, "2026-10": 1})

    def test_truncation_is_reported_when_list_hit_the_cap(self):
        issues = ISSUES + FAM_ISSUES
        _, capped = op.render(SNAP, issues, MONDAY, OWNER, cap=len(issues))
        self.assertIn(f"truncated at {len(issues)}", capped)
        _, roomy = op.render(SNAP, issues, MONDAY, OWNER, cap=len(issues) + 1)
        self.assertNotIn("truncated", roomy)
        _, nocap = op.render(SNAP, issues, MONDAY, OWNER)
        self.assertNotIn("truncated", nocap)

    def test_no_in_app_issues_still_renders_unlabelled_row(self):
        _, body = op.render(SNAP, [], MONDAY, OWNER)
        self.assertIn("| 未標家族 |", body)


if __name__ == "__main__":
    unittest.main()
