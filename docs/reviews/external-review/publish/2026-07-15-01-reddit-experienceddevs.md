# Publish — Reddit `r/ExperiencedDevs`

> **ERS-001 / QR-005** · Copy-paste ready · 2026-07-15  
> After posting: paste URL into [`../drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](../drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) and [`../SCORECARD.md`](../SCORECARD.md).

---

## Title

Multi-branch **tutoring center** SaaS: prepaid lesson packages + attendance-based debit — where should the calendar "hold" live?

---

## Body (markdown)

We operate a multi-branch **after-school / cram-school (補習班) management system** in Taiwan — same product, four campuses, ~hundreds of active students per branch.

Two billing models coexist:

1. **Monthly / pay-per-session** — revenue follows attendance (or approved learning records).
2. **Prepaid packages** — families pay upfront for N sessions or N minutes; ops need to see **upcoming weeks on the calendar** for scheduling and parent communication.

### Current design

- **Authoritative debit** happens at **attendance / approval**, via a minute-based ledger — **not** when a calendar row is created.
- Calendar truth = **materialized session rows** (weekly recurring slots + exceptions for leave / reschedule).
- We're **consolidating fragmented materialization** into a **single write path** (unique slot index, one upsert service). That fixes duplicate rows and drift — but it **doesn't** answer: *when does prepaid entitlement become a calendar reservation?*

### The pain (prepaid mode)

- If we **don't** materialize forward: directors see **"paid but empty calendar"** (stranded prepaid).
- If we **materialize** weekly slots ahead without a cap: calendar can **show more sessions than remaining package balance** (oversell / ghost slots).
- Nightly audits catch stranded balance — useful, but not a product invariant.

Mature tutoring CRMs (e.g. Teachworks, Tutorbase-style products) often **debit or block at schedule/booking time**. We've mapped that extreme vs our attendance-only extreme. What's missing is a **reusable middle state** when **all** of these are true:

- Weekly fixed time slots  
- **Finite** prepaid balance  
- Debit still at **attendance**  
- Directors need **forward visibility** (not just "balance > 0, book ad hoc")

Public docs rarely cover **hold → commit → release** when cancel / leave / no-show — without double-debiting or leaving ghost slots.

### Questions

1. If you separate **calendar hold** from **committed debit**, where does hold live — DB row, ledger pending entry, or UI-only cap?
2. On **cancel / leave / no-show**, how do you release holds without overselling or over-displaying?
3. Anyone shipped **forward materialization with a hard cap** under **attendance-only** billing — what broke in prod?
4. If you had to pick one invariant: **generate fewer calendar rows** vs **generate more and validate at attendance** — how did you choose?

No customer PII. Happy to share more context in comments. Not looking for "which tutoring software is best" — looking for **state-machine / entitlement timing** war stories.

---

## Suggested flair

`Architecture` or `Question`

## Subreddit

Primary: **r/ExperiencedDevs**  
Alternate: r/softwarearchitecture
