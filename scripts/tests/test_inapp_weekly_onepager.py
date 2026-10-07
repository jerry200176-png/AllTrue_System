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


if __name__ == "__main__":
    unittest.main()
