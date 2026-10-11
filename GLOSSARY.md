# Glossary

## Contract Money Verdict

The single answer to "what does this contract (StudentClass) owe and what has been applied": amount owed, amount applied,
status (free / unbilled / unpaid / partial / paid / review_required), the current billing period, and per-lesson coverage.

**Truth rule (Founder 1A, 2026-10-07):** invoices and their Payment rows decide. The legacy `StudentClass.Paid` flag counts
only as a fallback when the course has no non-void invoice at all. Stored `Invoice.PaidAmount` is never the source of an applied amount.

Implemented by `BillingPayableResolver` + `InvoiceAmountReconciliationService`; `ContractMoneyState` keeps status labels (plus the waived guard and `hasActivePayment`, which are not money-amount logic).
Pinned today by `tests/Feature/Billing/ContractMoneyVerdictCharacterizationTest.php`.

## Effective Teacher

The teacher who actually taught (or will teach) one lesson occurrence — a substitute, a rescheduled slot's teacher or a
makeup's LearningRecord teacher, else the contract teacher (`StudentClass.TeacherID`). Pay, stats, LR attribution and
the calendar all read it; nothing re-derives it. Resolved only by `SubstituteScheduleService::teacherForOccurrence()` /
`teachersForOccurrences()` (SQL: `substituteTeacherSql()`). See `docs/architecture/DESIGN_TD076_TRACK_B_SUBSTITUTE.md`.

## Scheduling

**Series**:
一門合約（StudentClass）約定的固定上課規則（星期＋時段＋起訖），用來展開出各堂 Occurrence；本身不是一堂課。
_Avoid_: 模板, template, recurrence, 課表規則

**Occurrence**:
一門合約的某一堂課，不論是否已物化、是否改期、請假或代課；它的身分在一生中不變。
_Avoid_: 場次, slot, schedule, session（session 專指已物化的那一列）

**Original start**:
一個 Occurrence 在 Series 中原本排定的日期與開始時間；改期不會改變它，是 Occurrence 身分的一部分。補課與手動排的堂沒有 Original start。
_Avoid_: 原時段, first slot, anchor time

**Current slot**:
一個 Occurrence 目前實際排定的日期與開始時間；改期只改變它。
_Avoid_: 用 Current slot 當身分

**Occurrence identity**:
Series 中的 Occurrence 以「合約＋Original start」識別；沒有 Original start 的 Occurrence 以它的 Session 識別。
_Avoid_: course|date|time key, occurrence key（指目前時段時）

**Exception**:
對單一 Occurrence 的覆寫（改期、請假、代課、取消），以該 Occurrence 的 Original start 指向它，不修改 Series。
_Avoid_: 例外鏈, chain, override row

**Projected occurrence**:
由 Series 推算、尚未物化的 Occurrence（課程查找的「預排」）；與物化後是同一個 Occurrence identity。
_Avoid_: 預排堂 ID, virtual session

**Session**:
一個 Occurrence 的物化紀錄，點名、評量、扣堂都掛在它上面。
_Avoid_: ClassSession row（說話時）, 堂次列

**Extra occurrence**:
不是由 Series 展開、而是單獨加上的 Occurrence（補課、手動排一堂）；沒有 Original start。
_Avoid_: makeup row, 加堂列

**Move**:
改變一個 Occurrence 的 Current slot（調課）；不產生新的 Occurrence。
_Avoid_: reschedule chain, 改期鏈

**Supersede**:
把多餘、重複的 Exception 標成不再有效（不刪除、可回復）。
_Avoid_: 刪除重複, cleanup delete

**History pin**:
記錄一個已上課 Occurrence 當時由哪位老師授課，讓日後更換合約老師不會改寫歷史。
_Avoid_: 代課（pin 不是代課）
