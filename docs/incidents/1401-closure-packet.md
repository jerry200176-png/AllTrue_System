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

- Existing suite re-run independently this session: 37 tests / 107
  assertions across `ParentLoginLineTest`, `LineWebhookBindingTest`,
  `ParentPortalLoginIsolationTest`, `FeedbackPushNotifierTest` — all pass.
- Added (PR [#1415](https://github.com/jerry200176-png/AllTrue_System/pull/1415), not merged): `Issue1401CrossFamilyLineIsolationTest`,
  proving two separate families, both fully verified (not unverified, not
  same-phone-only), still cannot see or switch to each other's student —
  the one combination not covered by the existing suite.
- Combined relevant suite: 56 tests / 195 assertions, all green.

## 6. Production impact-audit status

Design and mechanism prepared (PR [#1416](https://github.com/jerry200176-png/AllTrue_System/pull/1416), read-only,
**not triggered** — requires separate explicit Founder approval to run).
Expected answerability, determined by code/schema inspection before any
run:

| Question | Answerable? |
|---|---|
| Unverified/legacy binding count (total, by campus) | Yes |
| Cross-family LINE identity detection | Yes (via hashed contact-phone comparison, counts only) |
| First/last possible affected timestamps | Partially — data lower bound plus an independently-derived git-history fact: the vulnerable code existed from at least 2026-04-10 to the 2026-07-24 fix (~3.5 months), and may predate this repo's own git history |
| Historical switch-student access beyond validated boundary | **No** — `ParentSession` has no switch-history table and `switchStudent()` has zero `Log::` calls in the codebase; this is a structural gap, not a query returning zero |
| Notification delivery through an unverified/cross-family binding | **No** — `feedback_push_log` has no binding reference (send-dedupe log only); tuition-reminder logs are per-campus counts only |

**This means a full `confirmed-impact` vs. `no-evidence-found` determination is not achievable even after running the audit** — items 4 and 5 above will read `insufficient-logs` regardless of outcome. Do not treat a clean result on the answerable items as proof no historical exposure occurred.

## 7. Evidence retention location

- Public, PII-free technical evidence: this document, and
  `reports/2026-07-25/` in the `portfolio-ops` control-plane repository.
- Any aggregate audit output from PR #1416 (if and when run): the
  triggering GitHub Actions job log only — no artifact upload, nothing
  written to disk beyond the ephemeral runner.
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
3. Regression coverage proving cross-family isolation — ✅ done (§5).
4. Production impact audit run (not just designed) and its output
   reviewed by the Founder — ❌ **not done**; PR #1416 is prepared but not
   triggered.
5. Notification decision made (§8) and, if notification is chosen,
   initiated — ❌ **not done**.
6. Private incident evidence retained outside this repository per the
   issue's own guardrails, with a designated location/custodian — ❌ **not
   established**.
7. Provenance-blocked PRs (#1414, #1415) resolved one way or another —
   either merged via the Founder's manual action, or via the
   founder-exception path in PR #1417 once reviewed — so this incident's
   containment work isn't left permanently un-mergeable.

**Current readiness: 3 of 7. Not ready to close.**
