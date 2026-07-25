# Closure Packet — Issue #1401 (Parent-portal cross-student PII exposure)

**Status: NOT CLOSED.** This packet documents current state for the
Founder's closure decision — it does not close the issue itself.
No student name, phone, LINE user ID, token, or raw record appears
anywhere in this document.

## 1. Technical root cause

- LINE webhook binding accepted a student name or ID alone, with no proof
  of a parent relationship (no contact-phone check) — anyone who knew or
  guessed a student's name could bind their own LINE account to that
  student.
- Parent LINE login (`POST /api/v1/parent/login-line`, formerly accepting
  a raw `line_user_id`) trusted a client-supplied identity with zero
  server-side verification — any client could claim to be any LINE
  identity.

## 2. Affected authorization boundary

Parent-portal access to student data reachable via a LINE binding:
binding creation, parent login, sibling-switching, dashboard sibling
lists, notification preferences, and outbound LINE pushes (tuition
reminders, learning-feedback replies).

## 3. Fix and deployed commit evidence

- Fix: PR [#1400](https://github.com/jerry200176-png/AllTrue_System/pull/1400), commit `7e757c9be3494dd9a8aa4d6808d04b6026fb7ca3`, merged 2026-07-24.
- Requires phone-verified binding or server-validated LIFF access token
  (validated against LINE's own Profile API); adds `verified_at` /
  `verification_method`; gates every access-granting query on
  `StudentLineBinding.verified_at`; expires all pre-existing parent
  sessions on migration.
- **Production deployment independently verified this session** (not
  merely re-stated from the issue thread): `git merge-base --is-ancestor`
  confirms the fix commit is an ancestor of the currently-deployed
  production commit `8b4a30f1` (deployed 2026-07-24T22:13:56+08:00,
  ~1h23m after the fix); production health endpoint returns `ok`.

## 4. Adjacent-endpoint audit

Reviewed every other parent-facing controller for the same "trusts a
client-supplied/guessable identifier without server-side proof" pattern:
`ParentFeedbackController::store` (always writes to the session's own
bound student, no attacker-controlled ID param exists), 
`LearningRecordFeedbackController::parentShow/parentUpsert/parentReply`
(explicitly checks the record's owning student against the session's
StudentID, tested), `ParentPortalController::billingHistory`/
`requestLeave` (both scope strictly to the session's own StudentID). No
unfixed instance of the #1401 pattern found elsewhere.

## 5. Regression coverage

- Existing suite re-run independently, twice this engagement (once
  pre-merge, once again on `main` post-merge 2026-07-25): 37 tests / 107
  assertions across `ParentLoginLineTest`, `LineWebhookBindingTest`,
  `ParentPortalLoginIsolationTest`, `FeedbackPushNotifierTest` — all pass.
- Added and **merged to `main`** (PR #1422, superseding the original
  PR #1415 after a branch-naming-convention CI fix — same content):
  `Issue1401CrossFamilyLineIsolationTest`, proving two separate families,
  both fully verified (not unverified, not same-phone-only), still cannot
  see or switch to each other's student. **Re-confirmed passing on `main`
  itself** (2/2 tests, 9 assertions) after merge.
- Combined relevant suite: 56 tests / 195 assertions, all green, on `main`.

## 6. Production impact-audit status — RUN 2026-07-25

Merged to `main` as PR #1423 (superseding the original PR #1416 — same
content, branch-naming fix only) and **triggered once**, per Founder
approval, via `workflow_dispatch` from `main`. Aggregate-only results
below — no student name, phone, LINE user ID, token, or raw row was ever
produced by this run.

| # | Question | Result |
|---|---|---|
| 1 | Unverified/legacy binding count (total) | **175** |
| 2 | ...by campus only | campus 9: 71 · campus 11: 6 · campus 13: 1 · campus 15: 21 · campus 16: 35 · campus 17: 41 |
| 3 | LINE identities spanning multiple families (all bindings, verified+unverified) | **2** |
| 3 | ...same, counting only currently-verified bindings | **0** |
| 4 | Historical switch-student access beyond validated boundary | **insufficient-logs** — no switch-history table, `switchStudent()` has no `Log::` call in the codebase; structural gap, not a zero-result query |
| 5 | Notification delivery through an unverified/cross-family binding | **insufficient-logs** — `feedback_push_log` has no binding reference; tuition-reminder logs are per-campus counts only |
| 6 | First/last possible affected timestamps | Data lower bound: unverified bindings range 2026-04-16 18:49 to 2026-07-24 20:17. Independently, git history shows the vulnerable code existed from at least 2026-04-10 to the 2026-07-24 fix (~3.5 months), and may predate this repo's own git history. |
| 7 | Are available logs sufficient for a reliable conclusion? | **No** — items 4 and 5 are structurally unanswerable regardless of this run's other results. |

**Interpretation (session assessment, not an automated verdict)**: item 3's
"2 identities span multiple families" among all-time bindings is a real,
non-zero signal — but it is **not** proof of malicious cross-family
access. A plausible innocent explanation exists and cannot be ruled out
without manual, PII-handling review: a single real family whose two
children have two *different* registered contact phone numbers on file
(e.g. one parent's number per child) would hash to two different "family"
keys under this method even though it is one family. That the same check
returns **0** among *currently-verified* bindings is the more operationally
important number — it means the live containment (PR #1400) is holding:
no cross-family pairing has been re-verified as legitimate since the fix.

**Overall classification: `insufficient-logs`.** Not `confirmed-impact`
(no positive proof of malicious cross-family access — the 2 flagged
identities have an equally plausible benign explanation that only a
manual, PII-handling review of those 2 specific historical bindings could
resolve) and not `no-evidence-found` (a real, non-zero, unexplained signal
exists and must not be waved away as clean). Items 4 and 5 make a fully
confident conclusion impossible regardless.

## 7. Evidence retention location

- Public, PII-free technical evidence: this document, and
  `reports/2026-07-25/` in the `portfolio-ops` control-plane repository.
- The 2026-07-25 impact-audit run's aggregate output (§6): the triggering
  GitHub Actions job log only — no artifact upload, nothing written to
  disk beyond the ephemeral runner. Copy it into `03-impact-audit/` in the
  restricted evidence structure before it ages out of GitHub's retention
  window (see `docs/incidents/1401-evidence-retention-index.md`).
- Private incident evidence (access/notification log review findings,
  affected-family counts if ever determined with confidence, legal
  correspondence): **must be retained outside this public repository**,
  per issue #1401's own guardrails. Location and custodian: Founder to
  designate — not yet established.

## 8. Notification decision template (for the Founder — not pre-filled)

- [ ] Decision: notify affected families — yes / no / defer pending more evidence
- [ ] If yes: which families (requires the private, non-PII-in-repo evidence review above)
- [ ] Notification channel and content owner
- [ ] Legal/compliance review completed before sending
- [ ] Support workflow for re-binding / questions from notified families
- [ ] Timeline

## 9. Explicit closure criteria

Issue #1401 may close only once **all** of the following hold:

1. Code containment merged and deployed — ✅ done (§3).
2. Adjacent-endpoint audit performed — ✅ done (§4).
3. Regression coverage proving cross-family isolation — ✅ done (§5), and
   confirmed passing on `main` post-merge.
4. Production impact audit run (not just designed) and its output
   reviewed by the Founder — ✅ **run 2026-07-25** (§6); output is
   `insufficient-logs` overall, with one flagged, unresolved signal (2
   cross-family LINE identities among all-time bindings) requiring human
   judgment — the Founder still needs to review and decide how to treat
   this, so "run" is done but "reviewed/accepted" is not yet closed out.
5. Notification decision made (§8) and, if notification is chosen,
   initiated — ❌ **not done**. This session does not decide it.
6. Private incident evidence retained outside this repository per the
   issue's own guardrails, with a designated location/custodian — ❌ **not
   established** (sanitized index prepared in a companion PR; the actual
   restricted folder and evidence placement is a human action).
7. Provenance-blocked PRs resolved — ✅ **done**: the original PRs
   (#1414/#1415/#1416) hit a real branch-naming-convention CI failure
   (unrelated to provenance), were superseded by identically-content
   PRs (#1421/#1422/#1423) on correctly-named branches, and all three
   merged to `main` cleanly with every required check green, including
   Agent Session Provenance passing legitimately (via the repo's existing
   `main`-baseline `human-authored.json`, not fabricated by this session).

**Current readiness: 4 of 7, with item 4 partially open pending Founder
review of the flagged signal. Not ready to close.**
