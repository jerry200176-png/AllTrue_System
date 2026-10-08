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
