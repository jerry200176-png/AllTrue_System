import datetime as dt
import importlib.util
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = yaml.safe_load((ROOT / ".github/workflows/secret-rotation-reminder.yml").read_text())
spec = importlib.util.spec_from_file_location("credential_expiry", ROOT / "scripts" / "credential-expiry.py")
ce = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ce)

TODAY = dt.date(2026, 10, 21)


def reg(lead, *dates):
    return {"lead_days": lead, "credentials": [{"name": f"C{i}", "expires_on": d} for i, d in enumerate(dates)]}


class CredentialExpiryTest(unittest.TestCase):
    def test_window_follows_lead_days(self):
        r = reg(14, "2026-11-04", "2026-11-05")  # +14 due, +15 not
        self.assertEqual([d["name"] for d in ce.evaluate(r, TODAY)["due"]], ["C0"])
        r["lead_days"] = 15
        self.assertEqual([d["name"] for d in ce.evaluate(r, TODAY)["due"]], ["C0", "C1"])

    def test_expired_is_due_and_sorted_first(self):
        out = ce.evaluate(reg(14, "2026-11-01", "2026-10-01"), TODAY)["due"]
        self.assertEqual([d["name"] for d in out], ["C1", "C0"])
        self.assertLess(out[0]["days_left"], 0)

    def test_null_date_is_undated_not_due(self):
        out = ce.evaluate(reg(14, None, "2027-01-01"), TODAY)
        self.assertEqual(out["undated"], ["C0"])
        self.assertEqual(out["due"], [])

    def test_title_is_stable_per_credential_and_date(self):
        t = ce.evaluate(reg(14, "2026-11-01"), TODAY)["due"][0]["title"]
        self.assertEqual(t, "[security] Credential C0 expires 2026-11-01")

    def test_committed_registry_parses_and_holds_no_values(self):
        out = subprocess.run([sys.executable, str(ROOT / "scripts" / "credential-expiry.py")], capture_output=True, text=True, check=True)
        self.assertIn("due", json.loads(out.stdout))
        text = (ROOT / "docs" / "ops" / "credential-expiry.yml").read_text()
        for bad in ("password:", "secret:", "token:", "BEGIN "):
            self.assertNotIn(bad, text.lower() if bad != "BEGIN " else text)

    def test_weekly_buffer_boundaries(self):
        dates = [(TODAY + dt.timedelta(days=n)).isoformat() for n in (14, 20, 21)]
        self.assertEqual([d["name"] for d in ce.evaluate(reg(14, *dates), TODAY, 7)["due"]], ["C0", "C1"])

    def test_weekly_first_alert_preserves_lead_across_calendar_boundaries(self):
        # Every weekday, including leap day, month end, and year end.
        for monday in (dt.date(2028, 2, 7), dt.date(2026, 12, 7)):
            for offset in range(7):
                expiry = monday + dt.timedelta(days=21 + offset)
                for lead in (14, 30):
                    runs = [monday + dt.timedelta(days=7 * n) for n in range(-3, 6)]
                    first = next(day for day in runs if ce.evaluate(reg(lead, expiry.isoformat()), day, 7)["due"])
                    self.assertGreaterEqual((expiry - first).days, lead)
                    self.assertLessEqual((expiry - first).days, lead + 6)

    def test_invalid_registry_fails_without_partial_json(self):
        for data in (None, {"credentials": {}}, reg(-1), reg(True), reg(14, "bad-date"),
                     {"credentials": [{"name": "C"}, {"name": "C"}]}):
            with self.subTest(data=data), tempfile.TemporaryDirectory() as tmp:
                path = Path(tmp) / "registry.yml"
                path.write_text(yaml.safe_dump(data))
                out = subprocess.run([sys.executable, str(ROOT / "scripts/credential-expiry.py"), str(path), TODAY.isoformat()], capture_output=True, text=True)
                self.assertNotEqual(out.returncode, 0)
                self.assertEqual(out.stdout, "")
        for interval in (0, -1, True):
            with self.assertRaises(ValueError):
                ce.evaluate(reg(14), TODAY, interval)

    def test_cli_offline_clock_and_polling_buffer(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "registry.yml"
            path.write_text(yaml.safe_dump(reg(14, "2026-11-10", "2026-11-11")))
            out = subprocess.run([sys.executable, str(ROOT / "scripts/credential-expiry.py"), str(path), TODAY.isoformat(), "--poll-interval-days", "7"], capture_output=True, text=True, check=True)
            self.assertEqual([d["days_left"] for d in json.loads(out.stdout)["due"]], [20])


class ReminderWorkflowTest(unittest.TestCase):
    def selected_jobs(self, event, schedule="", mode=""):
        values = {"github.event_name": event, "github.event.schedule": schedule, "inputs.mode": mode}
        selected = []
        for name, job in WORKFLOW["jobs"].items():
            expression = job["if"].removeprefix("${{").removesuffix("}}").strip()
            expression = re.sub(r"github\.event_name|github\.event\.schedule|inputs\.mode", lambda m: repr(values[m[0]]), expression)
            if eval(expression.replace("&&", " and ").replace("||", " or "), {"__builtins__": {}}):
                selected.append(name)
        return selected

    def test_dispatch_and_schedule_select_exactly_one_job(self):
        for event, schedule, mode, expected in (
            ("workflow_dispatch", "", "expiry", ["expiry"]),
            ("workflow_dispatch", "", "quarterly", ["remind"]),
            ("workflow_dispatch", "", "unknown", []),
            ("workflow_dispatch", "", "", []),
            ("schedule", "0 1 * * 1", "", ["expiry"]),
            ("schedule", "0 16 1 */3 *", "", ["remind"]),
            ("push", "", "", ["validate"]),
        ):
            with self.subTest(event=event, schedule=schedule, mode=mode):
                self.assertEqual(self.selected_jobs(event, schedule, mode), expected)
        self.assertEqual(WORKFLOW[True]["workflow_dispatch"]["inputs"]["mode"]["default"], "expiry")

    def run_job(self, job="expiry", pages="[[]]", fail_lookup=False, registry=None):
        # Execute the workflow's real sender shell in a fixture tree. The only gh
        # on PATH records argv and reads local JSON; no token or network is used.
        with tempfile.TemporaryDirectory() as tmp:
            fixture = Path(tmp)
            (fixture / "scripts").mkdir()
            (fixture / "docs/ops").mkdir(parents=True)
            shutil.copy(ROOT / "scripts/credential-expiry.py", fixture / "scripts")
            (fixture / "docs/ops/credential-expiry.yml").write_text(yaml.safe_dump(reg(14, "2000-01-01") if registry is None else registry))
            bin_dir = fixture / "bin"
            bin_dir.mkdir()
            gh = bin_dir / "gh"
            gh.write_text(f"#!{sys.executable}\n" + '''import json, os, sys
with open(os.environ["GH_STUB_LOG"], "a") as log:
    log.write(json.dumps(sys.argv[1:]) + "\\n")
if sys.argv[1] == "api":
    if os.environ["GH_STUB_FAIL"] == "1":
        sys.exit(1)
    print(os.environ["GH_STUB_PAGES"])
elif sys.argv[1:3] != ["issue", "create"]:
    sys.exit("unexpected gh invocation")
''')
            gh.chmod(0o755)
            python = fixture / "credential-expiry-venv/bin/python"
            python.parent.mkdir(parents=True)
            python.write_text(f'#!/bin/sh\nexec {shlex.quote(sys.executable)} "$@"\n')
            python.chmod(0o755)
            env = {"PATH": f"{bin_dir}:{os.defpath}", "RUNNER_TEMP": tmp,
                   "GITHUB_REPOSITORY": "example/test", "GITHUB_STEP_SUMMARY": str(fixture / "summary"),
                   "GH_STUB_LOG": str(fixture / "gh.log"), "GH_STUB_FAIL": str(int(fail_lookup)), "GH_STUB_PAGES": pages}
            shell = WORKFLOW["jobs"][job]["steps"][-1]["run"].replace("${{ github.repository }}", "example/test")
            result = subprocess.run(["bash", "-c", shell], cwd=fixture, env=env, capture_output=True, text=True)
            calls = [json.loads(line) for line in (fixture / "gh.log").read_text().splitlines()] if (fixture / "gh.log").exists() else []
            return result, calls

    def test_closed_issue_on_later_page_prevents_duplicate(self):
        title = "[security] Credential C0 expires 2000-01-01"
        result, calls = self.run_job(pages=json.dumps([[{"title": "unrelated"}], [{"title": title, "state": "closed"}]]))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0], ["api", "--paginate", "--slurp", "repos/example/test/issues?state=all&per_page=100"])

    def test_exact_match_ignores_similar_titles_other_dates_and_prs(self):
        title = "[security] Credential C0 expires 2000-01-01"
        pages = [[{"title": title + " extra"}, {"title": title.replace("C0", "C01")},
                  {"title": title.replace("2000-01-01", "2000-01-02")}, {"title": title, "pull_request": {}}]]
        result, calls = self.run_job(pages=json.dumps(pages))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[1][calls[1].index("--title") + 1], title)

    def test_lookup_failures_stop_all_issue_writes(self):
        for pages, fail in (("[[]]", True), ("bad-json", False), ('[{"message":"error"}]', False), ("[]", False)):
            with self.subTest(pages=pages, fail=fail):
                result, calls = self.run_job(pages=pages, fail_lookup=fail)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(len(calls), 1)

    def test_invalid_registry_stops_before_github(self):
        result, calls = self.run_job(registry=reg(14, "not-a-date"))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])

    def test_undated_registry_never_calls_github(self):
        result, calls = self.run_job(registry=reg(14, None))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, [])

    def test_quarterly_sender_still_creates_only_quarterly_reminder(self):
        result, calls = self.run_job(job="remind")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertTrue(calls[0][calls[0].index("--title") + 1].startswith("[security] Quarterly secret rotation reminder — "))


if __name__ == "__main__":
    unittest.main()
