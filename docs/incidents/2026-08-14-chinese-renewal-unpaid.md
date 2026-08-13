# Repair Manifest — unpaid 國文續購 8 堂

**Risk-Class:** R3  
**Date:** 2026-08-14  
**Founder instruction:** 堂數仍 8、金額不變、尚未入帳；不要移 8/5。

## Exact scope

| Student | ID | Source 國文 batch | Forbidden math/payment batches |
|---|---|---|---|
| 張正甯 | 374 | 1681 | 2649 |
| 張正樂 | 373 | 1682 | 2606, 1324 |

Contract to create for each student:

- Official product path: `POST /api/v1/student-classes/{source}/purchase-batch`
- `sessions=8`
- `start_date=2026-08-19` (next 國文 Wednesday after 2026-08-14)
- `mode=new_purchase`
- `Paid=0`, `Pay=0`
- `Charge` must equal the source batch charge
- Do **not** record payment, invoice, or receipt
- Do **not** transfer session `23157` / `27156` (2026-08-05)

## Execution gate

1. Dispatch or PR CI runs **dry-run** and must print `PLAN_OK` for both students.
2. Apply requires the exact string `APPROVE_CHINESE_RENEWAL_UNPAID_8_SESSIONS_20260814`.
3. On a pull request, apply also requires label `apply-chinese-renewal-unpaid`.
4. Duplicate unpaid 8-session batches with the same start date are skipped, not doubled.

## Recovery

- Snapshot path is printed as `SNAPSHOT=...` before apply.
- New batches remain unpaid; they can be stopped through the normal course-management pause/settle flow if created in error.
- 8/5 sessions must still be on 1681 / 1682 after this operation.

## Follow-up (not this operation)

Official purchase-batch materializes 8 future sessions. Moving 8/5 onto the new batch later needs one free slot and separate payment evidence.
