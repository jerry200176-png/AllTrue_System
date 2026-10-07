"""F14 reconcile: each mismatch class is detected; logged suggestions and epics of open work are not."""
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "reconcile", Path(__file__).resolve().parents[1] / "inapp-issue-reconcile.py"
)
reconcile_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reconcile_mod)


def issue(n, title, state="OPEN", labels=(), body="", author="jerry"):
    return {"number": n, "title": title, "state": state, "body": body,
            "labels": [{"name": l} for l in labels], "author": {"login": author}}


def test_reconcile_classes():
    open_bugs = [{"id": 10, "status": "triaged"}]
    resolved = [{"id": 11, "status": "resolved"}]
    span = "| **Offending Spans** | db - select * from `X` |"
    issues = [
        issue(1, "[in-app #11] fixed", labels=["bug"]),                     # resolved, issue open -> flag
        issue(2, "[in-app #9] old", labels=["bug"]),                         # closed (<= max_id), open -> flag
        issue(3, "[in-app #9] logged idea", labels=[reconcile_mod.LOGGED_LABEL]),  # logged suggestion -> keep
        issue(4, "[in-app #10] still open", labels=["bug"]),
        issue(9, "[in-app #9] reviewed", labels=[reconcile_mod.FROZEN_LABEL]),  # frozen -> keep                 # in-app open -> fine
        issue(5, "[in-app #10] closed early", state="CLOSED", labels=["bug"]),  # issue closed, in-app open -> flag
        issue(6, "no labels"),                                               # unlabeled
        issue(7, "N+1 Query", body=span, author="app/sentry", labels=["x"]),
        issue(8, "N+1 Query", body=span, author="sentry-io[bot]", labels=["x"]),
    ]
    out = reconcile_mod.reconcile(open_bugs, resolved, 12, issues)
    assert [r["issue"] for r in out["inapp_done_issue_open"]] == [1, 2]
    assert [r["issue"] for r in out["issue_closed_inapp_open"]] == [5]
    assert out["unlabeled"] == [6]
    assert out["sentry_duplicates"] == [{"keep": 7, "duplicates": [8], "span": "db - select * from `X`"}]
    assert out["inapp_without_issue"] == []  # 10 and 11 are both tracked


def test_untracked_reports_and_unknown_refs():
    out = reconcile_mod.reconcile([{"id": 5, "status": "triaged"}], [{"id": 6, "status": "resolved"}], 6,
                                  [issue(1, "[in-app #6] tracked", labels=["x"]), issue(2, "[in-app #99] typo", labels=["x"])])
    assert out["inapp_without_issue"] == [5]
    assert [r["issue"] for r in out["unknown_inapp_ref"]] == [2]


def test_body_source_ref_counts():
    out = reconcile_mod.reconcile([], [{"id": 3, "status": "resolved"}], 3,
                                  [issue(1, "title only", labels=["bug"], body="SourceRef alltrue:bug_report:3")])
    assert [r["issue"] for r in out["inapp_done_issue_open"]] == [1]


def test_free_text_mentions_and_multi_title_refs():
    resolved = [{"id": 3, "status": "resolved"}, {"id": 4, "status": "resolved"}]
    out = reconcile_mod.reconcile([{"id": 5, "status": "triaged"}], resolved, 5, [
        issue(1, "[epic] long-term work", labels=["x"], body="related to in-app #3"),  # mention only -> ignored
        issue(2, "[in-app #3/#4] two reports", labels=["x"]),                          # both done -> flag
        issue(3, "[in-app #4/#5] mixed", labels=["x"]),                                # #5 still open -> fine
        issue(4, "[in-app #3] epic", labels=["type:epic"]),                            # epic -> kept open on purpose
        dict(issue(5, "shared issue", labels=["x"]), comments=[{"body": "SourceRef alltrue:bug_report:4"}]),  # comment ref
    ])
    assert [r["issue"] for r in out["inapp_done_issue_open"]] == [2, 5]



def test_cross_reference_sourcerefs_are_not_ownership():
    # F14 gap 2026-10-07: "Related earlier SourceRef" / "Cross-SourceRef update" mentions blocked auto-close.
    iss = dict(issue(1, "[in-app #363][critical intake] slot", labels=["x"],
                     body="SourceRef: alltrue:bug_report:363 (In-App #363).\nRELATED: alltrue:bug_report:359 / #3204"),
               comments=[{"body": "Related earlier SourceRef: alltrue:bug_report:136. evidence"},
                         {"body": "Cross-SourceRef update for `alltrue:bug_report:365` (read-only)"},
                         {"body": "**SourceRef:** `alltrue:bug_report:370`\nshared issue second report"}])
    assert reconcile_mod.inapp_ids(iss) == [363, 370], reconcile_mod.inapp_ids(iss)

if __name__ == "__main__":
    test_reconcile_classes()
    test_body_source_ref_counts()
    test_free_text_mentions_and_multi_title_refs()
    test_untracked_reports_and_unknown_refs()
    test_cross_reference_sourcerefs_are_not_ownership()
    print("test_inapp_issue_reconcile.py: ok")
