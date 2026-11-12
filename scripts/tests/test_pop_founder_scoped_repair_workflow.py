import re
import unittest
from pathlib import Path
WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "pop-founder-scoped-repair.yml"
def test_founder_repair_workflow_is_exactly_scoped_and_protected():
    text = WORKFLOW.read_text()
    for required in (
        "environment:\n      name: production-activation",
        "permissions:\n  contents: read",
        "course-contract-repair",
        '"student_id":30,"campus_id":9,"subject_id":66',
        '"source_student_class_id":2531',
        '"target_student_class_id":3379',
        '"preserve_session_ids":[24712,21907]',
        '"transfer_session_ids":[26552,21910,24805,26006,29478]',
        '"expected_source_charge":4400',
        '"expected_target_charge":5200',
        '"source_charge":2200',
        '"target_charge":6500',
        '"target_invoice_id":1601',
        "founder-go-huang-yikui-math-20260902",
        "/draft",
        "/dry-run",
        "/approvals",
        "/api/v1/pop/machine/operations",
        "/home/admin/backend/storage/app/private/pop-machine.key",
        "unset TOKEN",
        'rm -f "$WORK_DIR"/*',
    ):
        assert required in text, required
    assert 'where("u.type", "S")' in text
    assert 'where("t.expires_at", ">", now())' in text
    assert 'X-POP-MACHINE-KEY: $MACHINE_KEY' in text
    assert 'Authorization: Bearer $TOKEN' in text
    assert "pop:execute-approved" not in text
    assert "id-token: write" not in text
    assert "actions: write" not in text
    assert "GITHUB_ENV" not in text
    assert 'echo "$TOKEN"' not in text
    assert 'echo "$response"' not in text
    assert "reason_codes=" in text
    assert "plan_probe=exception" in text
    assert "database_query_exception" in text
    assert "PARAMETERS_JSON=\"$PARAMETERS_JSON\" php artisan tinker" in text
    assert "curl -sS" in text and '-o "$output"' in text
def test_confirmation_and_sha_are_fail_closed():
    text = WORKFLOW.read_text()
    assert "DRY_RUN_STUDENT_30_MATH_20260902" in text
    assert "APPROVE_STUDENT_30_MATH_20260902" in text
    # #2409 rebound the workflow to this backend SHA; every place that names a backend SHA must name the same one
    # (the look-behind skips action pins such as `checkout@<sha>`).
    assert re.findall(r"(?<![0-9a-f@])[0-9a-f]{40}(?![0-9a-f])", text) == ["98aadbbb380712fa389bce9f5a5806cbf14fa0f8"] * 4
    assert '[[ "$INPUT_SHA" == "$EXPECTED_SHA" ]] || {' in text
    assert "Founder gate passed" in text


class PopFounderScopedRepairWorkflowTest(unittest.TestCase):
    """The checks above were plain functions that no runner collected; unittest discovery runs them through here."""

    def test_founder_repair_workflow_is_exactly_scoped_and_protected(self):
        test_founder_repair_workflow_is_exactly_scoped_and_protected()

    def test_confirmation_and_sha_are_fail_closed(self):
        test_confirmation_and_sha_are_fail_closed()


if __name__ == "__main__":
    unittest.main()
