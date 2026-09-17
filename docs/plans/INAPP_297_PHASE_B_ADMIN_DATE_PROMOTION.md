# In-App #297 Phase-B Plan — Admin-date automatic grade promotion

**Status:** PLAN ONLY — implementation not authorized by this document  
**GitHub:** #2906 / in-app #297  
**Depends on:** Phase-A already production tip (`grade_promotion_*` tables + staff preview/confirm UI)  
**Risk class (proposed):** R2 product + scheduler — likely **Founder review** before impl (annual student-grade mutation)

## Outcomes

- On a configured administrative promotion date, eligible students are proposed (and optionally confirmed) for grade promotion without requiring a director to open the Students UI that day.
- Preserve Phase-A semantics: preview → confirm, idempotent per student/season, H3 graduates without course Stop in the promotion step.
- Reminder/notification path is optional Phase-B.1; do not couple to TrueFit.

## Non-outcomes

- No production data backfill of historical grades
- No TrueFit activation
- No auth/identity model changes (#299 remains parked)
- No billing/session deduction changes
- No Daan staging prerequisite for this Plan (staging when available is optional evidence later)

## Proposed design (for review)

1. **Trigger:** Laravel scheduler job keyed to `config/grade_promotion.php` admin date (Asia/Taipei), with dry-run default.
2. **Authority:** Reuse existing GradePromotion preview/confirm services; do not invent a second writer.
3. **Safety:** Job must fail closed if preview returns zero/ambiguous campus scope; require explicit `GRADE_PROMOTION_AUTO_CONFIRM=false` default (preview+notify only) until Founder GO for auto-confirm.
4. **Evidence:** SchedulerEvidence ledger entry per run; no PII in logs.
5. **Rollback:** Disable flag / remove schedule entry; Phase-A manual UI remains.

## Success criterion

- Plan merged as docs-only after review.
- Separate Impl PR only after Plan approval (and Founder GO if auto-confirm is in scope).

## Open questions for Founder / Product

1. Is Phase-B **preview+staff reminder only**, or **auto-confirm**?
2. Campus scope: all campuses vs director-selected allowlist?
3. Failure notification channel (in-app bug comment vs staff update vs email)?

## Implementation non-start

Workers must not open an Impl PR from this Plan until the questions above are decided and this Plan is marked APPROVED.
