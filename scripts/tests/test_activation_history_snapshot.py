"""#4385: complete snapshots and a strict, later recovery witness; no live API."""
import copy
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import unittest
from urllib.parse import parse_qs, urlsplit

from scripts.governance import autonomy_gate as gate

EPOCH = datetime(2026, 1, 1, tzinfo=timezone.utc)


def stamp(seconds):
    return None if seconds is None else (EPOCH + timedelta(seconds=seconds)).isoformat()


class HistoryAPI:
    def __init__(self, runs=None, jobs=None):
        self.runs = runs or [self.run(1)]
        self.jobs = jobs if jobs is not None else {(1, 1): [self.job(1, 1, 1, 100, 110)]}
        self.calls = []
        self.inventory_passes = 0
        self.after = None
        self.page_mutation = None

    @staticmethod
    def run(ident, attempts=1):
        return {"id": ident, "run_attempt": attempts, "status": "completed", "head_branch": "main",
                "conclusion": "success", "created_at": stamp(ident), "updated_at": stamp(ident + 1)}

    @staticmethod
    def job(run, attempt, ident, start, end, conclusion="success", status="completed"):
        return {"id": ident, "run_id": run, "run_attempt": attempt, "name": gate.DEPLOY_JOB_NAME,
                "status": status, "conclusion": conclusion,
                "started_at": stamp(start), "completed_at": stamp(end)}

    def __call__(self, path):
        self.calls.append(path)
        if "/ci.yml/" in path:
            return {"workflow_runs": [{"id": 900, "name": "CI — PHPUnit Tests", "status": "completed", "conclusion": "success"}]}
        page = int(parse_qs(urlsplit(path).query)["page"][0])
        if "/deploy.yml/" in path:
            if page == 1:
                self.inventory_passes += 1
            rows = self.runs if self.inventory_passes == 1 or self.after is None else self.after
            key = "workflow_runs"
        else:
            run = int(path.split("/runs/")[1].split("/")[0])
            attempt = int(path.split("/attempts/")[1].split("/")[0])
            rows, key = self.jobs[run, attempt], "jobs"
        response = {"total_count": len(rows), key: copy.deepcopy(rows[(page - 1) * 100:page * 100])}
        return self.page_mutation(path, response) if self.page_mutation else response

    def read(self):
        return gate.deploy_freeze_reason(repo="o/r", api=self)


class ActivationHistorySnapshotTest(unittest.TestCase):
    def test_complete_3574_runs_are_not_a_1000_record_prefix(self):
        runs = [HistoryAPI.run(i) for i in range(1, 3575)]
        jobs = {(r["id"], 1): [HistoryAPI.job(r["id"], 1, r["id"], None, None, "skipped")] for r in runs}
        jobs[1, 1] = [HistoryAPI.job(1, 1, 1, 100, 110)]
        api = HistoryAPI(runs, jobs)
        self.assertIsNone(api.read())
        self.assertEqual(api.inventory_passes, 2)
        self.assertEqual(sum("/attempts/" in p for p in api.calls), 3574)

    def test_sanitized_real_history_keeps_all_anomalies_but_has_a_later_witness(self):
        fixture = json.loads((Path(__file__).parent / "fixtures/activation-history-sanitized.json").read_text())
        runs = [{"id": i, "run_attempt": a, "status": s, "conclusion": c, "head_branch": branch,
                 "created_at": stamp(t0), "updated_at": stamp(t1), "run_started_at": stamp(t2)}
                for i, a, s, c, t0, t1, t2, branch in fixture["runs"]]
        jobs = {(r["id"], a): [] for r in runs for a in range(1, r["run_attempt"] + 1)}
        inversions = 0
        for run, a, ident, status, conclusion, start, end in fixture["jobs"]:
            jobs[run, a].append(HistoryAPI.job(run, a, ident, start, end, conclusion, status))
            inversions += conclusion == "cancelled" and start is not None and end is not None and start - end == 1
        original = copy.deepcopy(jobs)
        self.assertEqual(inversions, 26)
        self.assertIsNone(HistoryAPI(runs, jobs).read())
        self.assertEqual(jobs, original, "raw contradictory timestamps are never rewritten")

    def test_old_overlap_and_cancelled_envelope_require_a_strictly_later_success(self):
        runs = [HistoryAPI.run(i) for i in range(1, 5)]
        jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 30, "failure")],
                (2, 1): [HistoryAPI.job(2, 1, 2, 20, 40)],
                (3, 1): [HistoryAPI.job(3, 1, 3, 51, 50, "cancelled")],
                (4, 1): [HistoryAPI.job(4, 1, 4, 60, 70)]}
        self.assertIsNone(HistoryAPI(runs, jobs).read())
        for start in [40, 50, 51]:
            changed = copy.deepcopy(jobs); changed[4, 1][0]["started_at"] = stamp(start)
            with self.assertRaises(ValueError):
                HistoryAPI(runs, changed).read()

    def test_late_rerun_of_old_run_can_fail_after_the_newer_run(self):
        runs = [HistoryAPI.run(1, 2), HistoryAPI.run(2)]
        jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20)],
                (2, 1): [HistoryAPI.job(2, 1, 2, 30, 40)],
                (1, 2): [HistoryAPI.job(1, 2, 3, 50, 60, "failure")]}
        self.assertIsNotNone(HistoryAPI(runs, jobs).read())

    def test_unknown_old_record_is_not_superseded_by_a_later_success(self):
        for field, value in [("started_at", None), ("completed_at", "bad"),
                             ("started_at", "2026-01-01T00:00:00"), ("conclusion", None),
                             ("conclusion", "new-unknown"), ("status", "new-unknown"),
                             ("run_id", 99), ("run_attempt", 99), ("run_id", True), ("run_attempt", True)]:
            with self.subTest(field=field, value=value):
                jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20, "cancelled")],
                        (2, 1): [HistoryAPI.job(2, 1, 2, 100, 110)]}
                jobs[1, 1][0][field] = value
                with self.assertRaises(ValueError):
                    HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read()

    def test_only_a_one_second_cancelled_inversion_is_bounded(self):
        for conclusion, start, end in [("success", 21, 20), ("failure", 21, 20), ("cancelled", 22, 20)]:
            jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, start, end, conclusion)],
                    (2, 1): [HistoryAPI.job(2, 1, 2, 100, 110)]}
            with self.assertRaises(ValueError):
                HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read()

    def test_latest_failure_cancel_or_touching_success_keeps_hold(self):
        for conclusion in ["failure", "cancelled", "timed_out", "neutral", "action_required", "stale", "startup_failure"]:
            jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20)],
                    (2, 1): [HistoryAPI.job(2, 1, 2, 30, 40, conclusion)]}
            self.assertIsNotNone(HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read())
        for start, end in [(20, 30), (10, 20)]:
            jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20)],
                    (2, 1): [HistoryAPI.job(2, 1, 2, start, end)]}
            with self.assertRaises(ValueError):
                HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read()

    def test_active_or_inconsistent_nonexecuting_job_cannot_pass(self):
        for status, conclusion, start, end in [("in_progress", None, 1, None), ("queued", "success", None, None),
                                                ("queued", None, 1, None), ("completed", "skipped", None, 10)]:
            job = HistoryAPI.job(1, 1, 1, start, end, conclusion, status)
            try:
                result = HistoryAPI(jobs={(1, 1): [job]}).read()
            except ValueError:
                continue
            self.assertIsNotNone(result)

    def test_same_count_replacement_and_old_rerun_increment_are_detected(self):
        for changed in [[HistoryAPI.run(99)], [HistoryAPI.run(1, 2)]]:
            api = HistoryAPI(); api.after = changed
            with self.assertRaises(ValueError):
                api.read()

    def test_same_count_timestamp_change_during_collection_is_detected(self):
        api = HistoryAPI(); api.after = copy.deepcopy(api.runs); api.after[0]["updated_at"] = stamp(999)
        with self.assertRaises(ValueError):
            api.read()

    def test_skipped_placeholders_are_not_intervals_or_recovery_witnesses(self):
        for start, end in [(None, None), (50, 50), (10000, 5000)]:
            jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20, "failure")],
                    (2, 1): [HistoryAPI.job(2, 1, 2, start, end, "skipped")]}
            raw = copy.deepcopy(jobs)
            self.assertIsNotNone(HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read())
            jobs[3, 1] = [HistoryAPI.job(3, 1, 3, 30, 40)]
            self.assertIsNone(HistoryAPI([HistoryAPI.run(i) for i in [1, 2, 3]], jobs).read())
            self.assertEqual(jobs[2, 1], raw[2, 1])
        for field, value in [("started_at", None), ("completed_at", "bad"),
                             ("completed_at", "2026-01-01T00:00:00")]:
            job = HistoryAPI.job(1, 1, 1, 10, 20, "skipped"); job[field] = value
            with self.assertRaises(ValueError):
                HistoryAPI(jobs={(1, 1): [job]}).read()

    def test_main_ci_change_during_collection_keeps_hold(self):
        api = HistoryAPI(); calls = 0
        def changed(path):
            nonlocal calls
            response = api(path)
            if "/ci.yml/" in path:
                calls += 1
                if calls == 2:
                    response["workflow_runs"][0]["conclusion"] = "failure"
            return response
        self.assertIsNotNone(gate.deploy_freeze_reason(repo="o/r", api=changed))

    def test_non_main_success_is_risk_not_a_production_recovery_witness(self):
        runs = [HistoryAPI.run(1), HistoryAPI.run(2)]
        runs[1]["head_branch"] = "feature"
        jobs = {(1, 1): [HistoryAPI.job(1, 1, 1, 10, 20, "failure")],
                (2, 1): [HistoryAPI.job(2, 1, 2, 30, 40)]}
        self.assertIsNotNone(HistoryAPI(runs, jobs).read())
        runs.append(HistoryAPI.run(3)); jobs[3, 1] = [HistoryAPI.job(3, 1, 3, 50, 60)]
        self.assertIsNone(HistoryAPI(runs, jobs).read())
        runs[0]["head_branch"] = None
        with self.assertRaises(ValueError):
            HistoryAPI(runs, jobs).read()

    def test_duplicate_job_identity_and_api_error_keep_hold(self):
        jobs = {(1, 1): [HistoryAPI.job(1, 1, 7, 10, 20)],
                (2, 1): [HistoryAPI.job(2, 1, 7, 30, 40)]}
        with self.assertRaises(ValueError):
            HistoryAPI([HistoryAPI.run(1), HistoryAPI.run(2)], jobs).read()
        api = HistoryAPI()
        def denied(path):
            if "/attempts/" in path:
                raise PermissionError("sanitized API denial")
            return api(path)
        with self.assertRaises(PermissionError):
            gate.deploy_freeze_reason(repo="o/r", api=denied)

    def test_incomplete_or_duplicate_page_is_not_a_snapshot(self):
        for mutation in [lambda p, d: {**d, "total_count": d["total_count"] + 1},
                         lambda p, d: {**d, "workflow_runs": [d["workflow_runs"][0], d["workflow_runs"][0]], "total_count": 2} if "workflow_runs" in d else d]:
            api = HistoryAPI(); api.page_mutation = mutation
            with self.assertRaises(ValueError):
                api.read()


if __name__ == "__main__":
    unittest.main()
