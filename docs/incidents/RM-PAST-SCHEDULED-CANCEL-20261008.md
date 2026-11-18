# Repair Manifest — 過期仍「預排」的三堂 2026-10-08

Status: POP operation `cancel-past-scheduled-course2942-20261008`; dry-run only until Founder approves the printed digest.
Risk: R3/T3. Founder decision 2026-10-08 (GitHub #3797), source in-app #341 / GitHub #3199.

## Purpose

StudentClass 2942 ended early (`contract_amended`). Its ClassSession rows 27142 (2026-09-03), 27143 (2026-09-10) and
27144 (2026-09-17) are past but still `scheduled`, so the course shows over-scheduled. The repair sets exactly these
three to `cancelled`. Only `ClassSession.Status` changes; Charge, Paid, SessionCount and every Invoice/Payment are not written.

## Preconditions (plan, and again under lock)

Read-only Pi SELECT 2026-10-08: each row `scheduled`, belongs to course 2942, expected date, student campus 15, date before today,
and zero StudentSingIn / LearningRecord / session_deduction_ledger rows (a session with activity is never cancelled).
Any drift returns one error code per session id (`status_`, `identity_`, `campus_`, `not_past_`, `activity_`, `missing_`) and aborts.

## Dry-run

Dispatch `.github/workflows/pop-past-scheduled-cancel.yml` with `mode=dry-run`, `confirm=DRY_RUN_PAST_SCHEDULED_CANCEL_20261008`
and the deployed backend SHA. Expect `ok=true state=before`, counts `{sessions:3,to_cancel:3}`, the sha256 `digest` and the ids-only manifest.
`state=after` means already applied.

## Approval / execute / verify

`mode=approve` with `APPROVE_PAST_SCHEDULED_CANCEL_20261008` approves `founder-go-past-scheduled-cancel-20261008`, bound to the deployed SHA.
Pi-local POP runs one transaction (lock sessions + course, re-check, update exactly 3 rows, strict audit event
`pop.past_scheduled_sessions_cancel`). Verify: all three `cancelled`, course Charge/Paid/SessionCount unchanged.

## Rollback

Stored snapshot restores `scheduled` for rows still `cancelled` with no activity; otherwise the row is skipped and
the result is `ok=false, partial=true`. As with other POP repairs this is a new Founder-approved request, not a button.

## Second case: course 3380 (in-app #550 / GitHub #5361)

Same strategy, same per-session checks, selected by `decision_reference` = `cancel-past-scheduled-course3380-20261118`
(catalog operation of the same id; an unknown reference returns `case_parameters_mismatch`). StudentClass 3380 is closed
(waived) with 7 past `scheduled` rows: 32305, 32306, 32307, 32308, 32309, 32310, 32311, campus 16. Status
scheduled to cancelled only. Dispatch the workflow with `case=3380`, confirm `DRY_RUN_PAST_SCHEDULED_CANCEL_20261118` /
`APPROVE_PAST_SCHEDULED_CANCEL_20261118`, approval reference `founder-go-past-scheduled-cancel-20261118`. Expect counts
`{sessions:7,to_cancel:7}`. The 2942 case (`case=2942`, default) is unchanged. The data change runs later through POP, not in the PR.

### Native recovery for 3380 only

Cancellation and its execute snapshot/receipt co-commit. A failed receipt retains no cancellation;
the scheduler does not retry a failed request without another explicit Owner approval. An already
cancelled case is a no-op with no undo authority. The older 2942 executor recovery gap is not expanded.

For post-success recovery, the Owner runs the **same workflow**, `case=3380`, `mode=rollback`,
confirmation `ROLLBACK_PAST_SCHEDULED_CANCEL_20261118`, and the **original execute SHA still deployed**.
Its fresh production-activation gate submits the distinct reference
`founder-go-past-scheduled-cancel-rollback-20261118`. It reuses the original request and successful
snapshot; it does not replan or execute over SSH. The existing Pi scheduler consumes the signed
rollback intent. An execute-purpose token cannot authorize it. The workflow verifies that request's
`rolled_back` receipt, Pi-local actor, exact SHA and rollback reference. A later deployed revision
requires separately reviewed recovery; this adapter does not repin an old approval.

Rows with changed identity, campus or downstream activity are skipped and reported as partial;
successful restores and the failed receipt co-commit. The request then waits for a new Owner gate,
bound to the next rollback attempt. No activity is deleted; no partial retry runs automatically.
