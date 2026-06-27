---
owner: Principal Architect
status: normative
review_cycle: quarterly
last_reviewed: 2026-06-27
derives_from: CONSTITUTION.md (Article V)
---

# Business Fact Ownership Registry

> **Constitution Article V:** every business fact has exactly **one** owning context (its sole writer). A fact not listed here is an *undocumented-ownership defect*. A fact with two writers is a *duplicated-ownership defect* (the current legacy state, tracked below with a retirement target).
>
> Columns: **Owner** = the only context allowed to write the fact · **SoR (target)** = authoritative store after migration · **Current writers** = today's reality · **Status** = `single` / `contested→target` (debt) · **Debt** = `docs/TECH_DEBT.md` id.

| # | Business fact | Owner (single) | SoR (target) | Current writers (reality) | Status | ADR | Debt |
|---|---|---|---|---|---|---|---|
| F-01 | Identity of a person | Identity | `Party` | `Teacher` + `User` (type=T) | contested→`Party` | [0006](adr/0006-single-party-identity.md) | TD-IDN |
| F-02 | Campus / org structure | Identity | `Campus`,`UserCampus` | same | single | — | — |
| F-03 | Subject / room / competency | Curriculum | `Subject`,`rooms`,`teacher_subjects` | same | single | — | — |
| F-04 | Contract terms (rate, mode, purchased units) | Enrollment | `Contract` (`StudentClass` terms cols) | `StudentClass` (mixed w/ F-05/07/08) | contested→decompose | [0010](adr/0010-decompose-studentclass.md) | TD-SC |
| F-05 | When a class occurs (occurrence) | Scheduling | `Occurrence` | `schedules` **and** `ClassSession` (dual) | contested→`Occurrence` | [0001](adr/0001-occurrence-single-sor.md) | TD-SCHED |
| F-06 | Who teaches a session | Scheduling | `Occurrence.instructor` | `StudentClass.TeacherID` + `schedules` substitute | contested→`Occurrence` | [0001](adr/0001-occurrence-single-sor.md),[0004](adr/0004-cross-context-events.md) | TD-SCHED |
| F-07 | Sessions remaining (units) | Billing | `SessionLedger` (append-only) | `StudentClass.Remaining*` + ledgers | contested→ledger | [0002](adr/0002-billing-ledger-sor.md) | TD-BILL |
| F-08 | Is it paid / amount | Billing | `Invoice`/`Payment` | `Invoice/Payment` **and** `StudentClass.Paid/Charge` | contested→ledger | [0002](adr/0002-billing-ledger-sor.md) | TD-BILL |
| F-09 | Attendance (presence) | Attendance | `AttendanceEvent` (`*SingIn`,`PendingSwipe`) | same | single | — | — |
| F-10 | Delivery / evaluation of a session | Delivery | `SessionRecord` (`LearningRecord`) | `LearningRecord` (+ materialized on read) | contested→event | [0007](adr/0007-read-models-off-write-path.md) | TD-READ |
| F-11 | Teacher compensation | Payroll | `payroll_*` (projection) | same (read model) | single | — | — |
| F-12 | Engagement (XP/rank/badge) | Engagement | `user_engagement*`,`user_badges` | same | single | — | — |
| F-13 | Messages / notifications | Communication | `chat_*`,`Notifications` | same | single | — | — |
| F-14 | Parent feedback / LINE binding | Parent Portal | `parent_feedback*`,`student_line_bindings` | same | single | — | — |
| F-15 | Bug reports | Support | `bug_report_*` | same | single | — | — |
| F-16 | Deployment admission (change→prod) | CI Decision Kernel | branch protection + fitness | + §139 break-glass (secondary) | contested→reconcile | [0009](adr/0009-emergency-reconcile.md) | TD-DEPLOY |

## Contested facts (the only allowed duplicates, each with a retirement target)
F-04, F-05, F-06, F-07, F-08, F-10, F-16. Each MUST reach `single` via Expand/Contract (Constitution Article VIII, Handbook FE-2). During transition the legacy column is a **read-only projection**, never a second writer.

## Enforcement
- New fact → add a row here in the same PR (or the PR is a documented-ownership violation).
- `scripts/arch-fitness-check.mjs` FIT-1 ratchets the worst contested writer (occurrence creation) toward 1.
- Quarterly review re-validates every `single` claim and advances every `contested→target`.
