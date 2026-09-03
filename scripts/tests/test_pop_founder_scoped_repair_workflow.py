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
    assert "curl -sS" in text and '-o "$output"' in text
def test_confirmation_and_sha_are_fail_closed():
    text = WORKFLOW.read_text()
    assert "DRY_RUN_HUANG_YIKUI_MATH_20260902" in text
    assert "APPROVE_HUANG_YIKUI_MATH_20260902" in text
    assert "8dda90fe1723c8fb93bde382e79e791aad5c83e5" in text
    assert "Founder gate passed" in text
