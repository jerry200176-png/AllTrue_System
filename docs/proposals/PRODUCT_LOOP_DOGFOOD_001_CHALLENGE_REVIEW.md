# PRODUCT_LOOP_DOGFOOD_001 — Independent CubeLV Challenge Review

**Reviewed artifact:** `docs/proposals/PRODUCT_LOOP_DOGFOOD_001_INAPP_290_CALENDAR_COURSE_SESSION_EDITING.md`
**Reviewed commit:** `6b70ca51` (PR #3040, merged to `main` 2026-09-17)
**Review commit scope:** docs-only. No app code, no schema, no production change.
**Review method:** read-only inspection of the `github-cache` bare repo at `FETCH_HEAD`
(`6b70ca51`). GitHub Issues API unavailable from this surface — all cross-issue
state claims below are marked UNOBSERVED where they depend on issue-tracker reads.
**Reviewer position:** CONDITIONAL_AGREE — the proposal's Phase 0–1 slice is sound
and evidence-backed on the points verifiable from git; Plan GO should be gated on
the four findings below. Implementation remains unauthorized.

---

## 1. Verified claims (OBSERVED from bare repo @ 6b70ca51)

| Proposal claim | Verification |
|----------------|--------------|
| Course create writes via `POST /api/v1/class-sessions/batch` | OBSERVED: `backend/routes/api.php:669` — `Route::post('class-sessions/batch', [ClassSessionController::class, 'batchStore'])` |
| Phase-1 writers `add-session` / `manual-sessions` exist | OBSERVED: `api.php:596-597` (`add-session`, `add-session/check`), `api.php:559-560` (`manual-sessions`, `manual-sessions/check`) |
| `CalendarSessionEditModal` is single-session only | OBSERVED: component emits `leave / reschedule / substitute(-v2) / cancel / delete-exception / cancel-makeup / teacher-change` — no full-plan edit action |
| Calendar red line: no Charge/Invoice/Payment change from calendar UX | OBSERVED: `docs/MODULE_CALENDAR_SCHEDULE_UX.md` §4 — 「不新增資料庫欄位、不改 Charge／Invoice／Payment 真相」 (+ merge-semantics + leave/reschedule guards) |
| ADR-004 / ADR-005 / RFC occurrence identity exist | OBSERVED: `docs/ADR_004_atomic_reschedule_boundary.md`, `docs/ADR_005_scheduling_named_command_boundaries.md`, `docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`, `RescheduleSessionService.php` present |
| Prior revert warns against calendar contract writes (#1927) | OBSERVED: commit `f9e23bc1` — "revert calendar reassign-contract UI + CourseManagement read-only-lens first slice" |
| No delivered fix for in-app #290 | OBSERVED (git-scoped): `log -S "bug_report:290"` returns only the proposal commit itself; no merged PR title references delivering #290 / #2800 |

## 2. Findings (require resolution before Plan GO)

### F1 — Dedup table cites issue states CubeLV cannot observe (NON_BLOCKING for proposal, BLOCKING for Plan GO)

The dedup table asserts states for GH `#2808`, `#2905`, `#2906`, `#2908`, `#2179`,
`#1922`, `#2002` and in-app `#292`/`#296`/`#297`/`#299`/`#302`/`#303`/`#247`
(e.g. "`#292` already has Founder NO-GO/deferral", "`#296` already shipped").
From this surface, issue-tracker state is **UNOBSERVED** (Issues API not readable
here; only the `#290` dump `34739959709` and git history are primary evidence).
`#296`-shipped is corroborated from git (`55c2b641`, PR #3015 merged); the rest
are inherited from the proposal author's evidence, not independently re-verified.

**Ask:** an Ubuntu-side reviewer with Issues read access confirms the cited states
of `#2808 / #2905 / #2906 / #2908 / #2179` before Founder Plan GO. If any cited
issue is open and overlapping, the dedup table must be amended — not the proposal's
conclusion, just its citations.

### F2 — "No CHANGELOG claims delivery" not independently checked (NON_BLOCKING, one-grep fix)

"No CHANGELOG / merged PR claims delivery" was verified for PR titles via
pickaxe search, but CHANGELOG content was not grepped for `#2800` / `#290`.

**Ask:** reviewer runs `git log --all -S "#2800" -- CHANGELOG*` (or equivalent) at
Plan-GO time. Expected result: no delivery claim. If a claim exists, proposal §
"No delivered fix" must be revised.

### F3 — Phase-1 "billing unchanged" guard is named but not specified (BLOCKING for Phase-1 GO; Phase-0 unaffected)

The proposal correctly identifies the red line (`syncSessionChargeForTimeChange`
can adjust `StudentClass.Charge` on hour-mode time edits) and says Phase 0–1 will
"block or redirect" such edits. But it does not name **the guard condition**: which
endpoint fires the charge sync, how the calendar client detects hour-mode vs
count-mode before calling, or which error code the user sees.

**Ask:** Plan GO for Phase 1 must include either (a) the exact guard (endpoint +
condition + UX copy), or (b) an explicit narrowing: Phase 1 ships **create-only**
via `add-session`/`manual-sessions` with cancel deferred to a Phase-1b review.
Phase 0 (read-only) needs no guard and can be approved independently.

### F4 — Host-surface decision must be explicit at Plan GO (AMEND-risk, not a veto)

The proposal retains course-mgmt-hosted (recommended) vs SmartCalendar-hosted
(Alternative A) as a genuine trade-off, while citing `#1922`'s "calendar as
read-only lens" direction as a constraint. These two statements pull in opposite
directions: if `#1922` end-state keeps the calendar read-only permanently, the
editor must live in course-mgmt **permanently**, not "first".

**Ask:** Founder picks the host surface explicitly at Plan GO (recommended:
course-mgmt-hosted, permanent unless `#1922` is re-decided). Alternative A should
not remain open-ended past Plan GO or Phase 0 will be built in the wrong host.

## 3. Accepted without change

- Phase 0–1 vs deferred Phase 2–3 split: correct blast-radius control. Full
  recurrence editing (Phase 3) without Phase 0–1 production evidence would be R3;
  the proposal's R1/R2 self-grading is consistent with the observed code.
- Alternatives A–D dismissal rationale: A (precedent `f9e23bc1`), B (SoT fork),
  D (semantic risk) are each grounded in observed artifacts. C (docs-only) is a
  judgment call, accepted.
- Non-scope list (auth/RFID, billing mutation, history repair, Restate/CP
  expansion): aligns with dogfood selection rules; no objection.
- Unknowns §1–5: honestly stated; Unknown #1 (reporter screenshots) is the
  highest-value missing evidence for the host-surface decision (F4).

## 4. Recommendation to Founder

- **Phase 0 (read-only calendar lens):** GO-ready after F1 confirmation (mechanical).
- **Phase 1 (writes via existing APIs):** GO only with F3 guard specified; otherwise
  narrow to create-only or defer.
- **Host surface (F4):** decide at the same Plan GO; do not leave Alternative A open.
- **Not requested:** implementation, migration, production activation.

---

## Provenance & limits of this review

- Evidence: bare-repo reads only (`git show / grep / log -S` at `6b70ca51`).
- UNOBSERVED: GitHub issue states/comments/labels, Actions run `34739959709`
  artifact content, in-app `#290` attachments/screenshots, production behavior at
  campus 16, CI/Checks status of any PR.
- No inference was made from commit dates, PR numbers, or merge state about
  deployment or delivery. Deployment/production verification is
  `EXTERNAL_OBSERVER_REQUIRED`.
