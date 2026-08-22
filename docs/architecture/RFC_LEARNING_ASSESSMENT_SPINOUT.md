# RFC: Learning Assessment + Question Bank Spinout

**Status:** Proposed — Founder decision recorded, execution plan for review
**Date:** 2026-08-22
**Risk class:** R2 (new system), R1 for anything touching AllTrue's live tables
**Supersedes (partially):** [`RFC_LEARNING_ASSESSMENT_MVP.md`](RFC_LEARNING_ASSESSMENT_MVP.md) — see §9
**Related:** issue #1934 (CLOSED), PRs #1936/#1938/#1941/#1943/#1944/#1947/#1948/#1949/#1950/#1953/#1954
**Reviewer audience:** Codex (active in this repo 2026-08-21/22), Founder

---

## 0. Decision being recorded

Two Founder/director decisions, already made, not re-litigated here:

1. **學習檢測 + 題庫管理 becomes its own system**, deployable and sellable to a
   分校 that does **not** buy AllTrue's 教務行政系統.
2. **AllTrue enters feature freeze** per 主任 — bugfix-only. Assessment must stop
   growing inside the monolith.

This RFC is the execution plan for (1) given the state of the code as of commit
`529bcb49`.

---

## 1. What actually exists today (measured, not assumed)

Contrary to the "Slice 1 only" framing in `RFC_LEARNING_ASSESSMENT_MVP.md`, the
whole vertical shipped to `main` between 2026-08-21 02:21Z and 09:14Z. That RFC
says (lines 12–14) it "intentionally postpones question-level online attempts
until the result workflow has been used with one real subject." Online attempts
(#1949, #1950), auto-grading, and the parent-portal projection (#1953) all merged
within roughly seven hours of the data contract (#1936). **We are not spinning out
greenfield; we are spinning out a shipped feature.**

### Backend (`backend/`)

| Path | LOC | Note |
|---|---:|---|
| `app/Http/Controllers/AssessmentController.php` | 816 | definitions, results, attempts, remediation, review/void, summary |
| `app/Http/Controllers/QuestionBankController.php` | 290 | bank + item authoring, review, provenance |
| `app/Services/AssessmentAttemptService.php` | 294 | snapshot/attempt/answer/grading; all `DB::table(...)` on assessment-owned tables only |
| `app/Models/Assessment*.php`, `app/Models/Question*.php` | 336 | 7 models |

Routes: `backend/routes/api.php:654–703` — 31 endpoints under `/api/v1`, split into
read / write / director-only middleware groups.

### Schema — 10 tables, 5 migrations

`backend/database/migrations/`:

- `2026_08_20_200000_create_assessment_tables.php` → `assessments`, `assessment_results`, `assessment_audit_logs`
- `2026_08_21_100000_create_assessment_remediation_actions_table.php` → `assessment_remediation_actions`
- `2026_08_21_120000_create_question_bank_tables.php` → `question_banks`, `question_bank_items`, `question_bank_audit_logs`
- `2026_08_21_130000_create_assessment_attempt_tables.php` → `assessment_question_snapshots`, `assessment_attempts`, `assessment_answers`
- `2026_08_21_140000_add_provenance_to_question_bank_items.php` → columns only

**The single most important finding for this spinout:** none of these tables declare
a database-level foreign key to an AllTrue table. Every reference to AllTrue is a
bare integer column — `campus_id`, `student_id`, `student_class_id`, `subject_id`,
`created_by_user_id`, `recorded_by_user_id`, `reviewed_by_user_id`, `actor_user_id`
(e.g. `2026_08_20_200000_create_assessment_tables.php:14–16, 24, 39–40, 47–48, 65–66`;
`2026_08_21_120000_create_question_bank_tables.php:14–15, 19, 43–44, 60–61`).

The `RFC_LEARNING_ASSESSMENT_MVP.md` "Migration and rollback" section called this
out deliberately ("independent of legacy tables by foreign-key-like IDs"). That
decision, made for rollback safety, is what makes this spinout cheap. **A
`mysqldump` of these 10 tables is a self-contained database.**

### Application-level coupling to AllTrue — 5 call sites

Grep for `Student::` / `StudentClass::` across the assessment code returns exactly:

- `AssessmentController.php:26` — `classOptions()`: `StudentClass` joined to `student` on `CampusID`, teacher-filtered on `TeacherID`
- `AssessmentController.php:310` — `students()`: roster for an assessment
- `AssessmentController.php:384` — `storeAttempt()`: validates `student_id` belongs to the assessment's campus
- `AssessmentController.php:466` — `storeResult()`: same validation
- `AssessmentController.php:686` — `resolveStudentClass()`: shared auth helper

`QuestionBankController.php` and `AssessmentAttemptService.php` reference **zero**
AllTrue models. The question bank is already fully standalone except for auth.

These five sites all answer the same two questions: *does this student exist and
belong to this campus*, and *does this teacher teach this student*. That is the
entire roster contract. It is one interface, not a web of dependencies.

### Auth coupling

`backend/app/Http/Middleware/AttachAuthUser.php:29–60` resolves a Bearer token via
`AuthToken` → `User`, then derives campus scope from `UserCampus` (`UserID`/`CampusID`/
`Approved`). The assessment controllers consume only the resulting request attributes
`auth_user`, `auth_role`, `auth_campus_ids`, `auth_teacher_id`. Roles are
`super_admin` / `director` / `teacher` (`AttachAuthUser.php:70–80`).

This is a narrow, reimplementable contract — four request attributes and three roles.

### Reverse dependency — AllTrue reads assessment data

`backend/app/Http/Controllers/ParentPortalController.php:983, 1040, 1046–1110`
(`buildParentAssessmentProgress`) queries `AssessmentResult` and
`assessment_remediation_actions` directly with Eloquent, filtered to reviewed
results of published assessments. This is the **only** direction where AllTrue
depends on assessment code, and it is the only piece of the cutover that is not a
pure delete. See §6.

### Frontend

- `frontend/src/pages/AssessmentPage.vue` (366 lines), `QuestionBankPage.vue` (61 lines)
- `frontend/src/lib/assessmentRunner.js` (20), `parentAssessmentProgress.js` (24) + their `.test.js`
- Mounted in `frontend/src/App.vue:354–355`, lazy-imported at `486–487`, gated on
  `isDirector || isTeacher` and `active === 'assessments' | 'question-banks'`

No Vue Router — `src/router/` does not exist; App.vue is a manual `active` switch.
This matters: extracting the UI is a copy of two SFCs, not untangling a route tree.

### Tests

`backend/tests/Feature/AssessmentApiTest.php`, `AssessmentSchemaTest.php`,
`QuestionBankApiTest.php`, `ParentAssessmentProgressTest.php`. These port to the new
repo largely as-is once the auth harness is replaced (the tests rely on the
`X-User-Id` / `X-User-Role` dev-only header path, `AttachAuthUser.php:20–24`).

### Production topology (constrains hosting, §8)

`.github/workflows/deploy.yml:36, 96` — GitHub-hosted `ubuntu-latest` runner SSHs
(`:108`) into a **single Raspberry Pi** serving `daan.lifenet.com.tw` (`:320, 334`).
There is one production host, one MySQL, one domain. **A 分校 without AllTrue cannot
be served from that box** — it is the Daan campus's server. This is the concrete
infrastructure reason the spinout cannot be "just another vhost on the Pi", and it
is the biggest genuinely undecided item in this plan.

---

## 2. System boundary — what moves, what stays

### Moves to the new system (working name: **Assess**)

- 題庫: `question_banks`, `question_bank_items`, `question_bank_audit_logs`
- 檢測定義 + 結果: `assessments`, `assessment_results`, `assessment_audit_logs`
- 線上作答: `assessment_question_snapshots`, `assessment_attempts`, `assessment_answers`
- 補救追蹤: `assessment_remediation_actions`
- 主任審核 (`review`/`void`) and all audit-log writes
- `AssessmentController`, `QuestionBankController`, `AssessmentAttemptService`, 7 models
- `AssessmentPage.vue`, `QuestionBankPage.vue`, `assessmentRunner.js`

### Stays in AllTrue — unchanged, forever

`ClassSession`, `StudentSignIn`, attendance, `LearningRecord` approval, billing sync,
scheduling/reschedule (TD-076), payroll, LINE, RFID, PII. The boundary line in
`RFC_LEARNING_ASSESSMENT_MVP.md:24–34` — "assessment results are never an implicit
attendance, LearningRecord approval, or billing event" — is unchanged and is now
enforced by *process separation* rather than by developer discipline. That is a
strict improvement.

### Stays in AllTrue but becomes a consumer

The parent portal's assessment progress card
(`ParentPortalController.php:1046–1110`). Parents log into AllTrue; a linked campus
should not send them to a second portal. §6 covers how this keeps working.

### Explicitly NOT moving

`Student`, `StudentClass`, `Campus`, `User`, `Teacher`, `UserCampus`, `AuthToken`
stay in AllTrue and remain AllTrue's system of record for campuses that use AllTrue.

---

## 3. Data ownership — the standalone-campus problem

**Decision: Assess owns its own lightweight learner/campus/user records. AllTrue is
an optional upstream, never a required one.**

The alternative (a shared identity service) is rejected: it would require standing up
a third deployable and changing AllTrue's live auth path
(`AttachAuthUser.php`) during a feature freeze. That is exactly the class of change
the 主任 just froze, and it makes the standalone case *depend* on infrastructure
built for the linked case — backwards priorities.

### Assess's own tables

```
campuses     id, name, code, timezone, created_at, ...
users        id, campus_id, name, email, password_hash, role, active, ...
             role ∈ (admin, director, teacher)          -- mirrors AllTrue's three roles
learners     id, campus_id, display_name, external_ref, external_source, ...
```

`learners` is deliberately thin. It holds a display name, a campus, and an optional
external reference. It holds **no PII beyond a name** in v1 — no phone, no address,
no parent contact, no birthdate. A standalone campus can populate it from a CSV.

### The link, when AllTrue is present

```
learners.external_source   ∈ (NULL, 'alltrue')
learners.external_ref      -- AllTrue Student.ID as a string, NULL when locally created
UNIQUE (campus_id, external_source, external_ref)   -- partial/NULL-tolerant
```

One nullable column pair, not a join table. There is exactly one possible upstream
in the foreseeable future; a polymorphic `identity_links` table with one row type is
speculative. If a second upstream ever appears, the `external_source` column already
carries the discriminator.

Assess's `assessments.learner_id` etc. point at `learners.id` — **Assess's own key,
never AllTrue's**. This is the crucial change from the shipped schema, where
`assessment_results.student_id` is AllTrue's `Student.ID`
(`2026_08_20_200000_create_assessment_tables.php:39`). Migration of existing rows is
a mechanical remap, covered in §6.

Same shape for `campuses`: `external_ref` holds AllTrue's `CampusID` when linked.

### Why this is the right call, stated plainly

A campus with no AllTrue has no student table anywhere else. Assess must be able to
create a learner from nothing. Once it can do that, "sync from AllTrue" is just a
second way to populate the same table, not a different data model. Anything else
means two code paths through every query.

---

## 4. Integration when both systems are in use

**Decision: one-way, pull-based roster sync. Assess polls AllTrue. Nothing else.**

### Mechanism

AllTrue exposes one new read-only endpoint:

```
GET /api/v1/integration/roster
Header: X-API-KEY: <key>
→ { data: [ { student_id, name, campus_id, classes: [ { student_class_id, subject, teacher_id } ] } ] }
```

This reuses machinery that already exists rather than building new:

- `backend/app/Http/Middleware/ApiKeyAuth.php` already authenticates
  `X-API-KEY` against `ApiClient.ApiKeyHash` (sha256) and, critically, already sets
  `api_campus_id` from `ApiClient.CampusID` (`ApiKeyAuth.php:27`). **Campus isolation
  for the integration is already solved** — an API key is scoped to one campus by
  construction. Today it guards exactly one route,
  `attendance/swipe` (`routes/api.php:275–276`); this adds a second.
- The query itself is the one already written at
  `AssessmentController.php:24–41` (`classOptions`) — student + class + subject +
  teacher, campus-filtered, `Stop = 0` filtered. It moves out of the controller into
  a service per ADR-003 rule 1, and becomes the integration endpoint's body.

On the Assess side: one Laravel scheduled command, `assess:sync-roster`, hourly per
linked campus. Upsert on `(campus_id, external_source, external_ref)`. Rows that
disappear upstream are marked `inactive`, never deleted — historical assessment
results must not lose their learner.

### Why pull, not webhooks or an event stream

- AllTrue is in feature freeze. A webhook emitter means touching
  `StudentClassController` (a protected controller under ADR-003's `DB::` ratchet)
  and adding a retry/DLQ story. A pull endpoint touches nothing that exists.
- Hourly staleness is acceptable. A 分校 that enrolls a student and wants to test
  them within the hour can add the learner in Assess directly; the sync's upsert key
  will reconcile it on the next pass if the operator supplies the AllTrue ID. (Open
  question O-6 below: whether we even need that manual path in v1.)
- The team maintains this stack alone. The team already knows what event
  infrastructure costs; a roster of a few thousand students does not earn it.

### Stack for Assess

Laravel + MySQL + Vue 3, same major versions as AllTrue, same `docker/` layout,
GitHub Actions CI mirroring `ci.yml`. **No new languages, no new datastore, no
queue worker in v1** (the sync command runs from cron/scheduler synchronously). The
argument for keeping the stack identical is not aesthetic: it means the four existing
feature tests, the controller/service layering rules in ADR-003, and the deploy
workflow shape all port over instead of being reinvented.

---

## 5. Auth

**Decision: Assess has its own login, always. No SSO in v1.**

- Assess ships its own `users` table with `password_hash`, its own token issuance,
  and its own middleware that sets the same four request attributes the assessment
  controllers already consume — `auth_user`, `auth_role`, `auth_campus_ids`,
  `auth_teacher_id`. Because the controllers only ever read those attributes, **the
  816-line `AssessmentController` needs no authorization rewrite**; it needs a new
  middleware behind it.
- Roles stay the existing three (`director`, `teacher`, plus an `admin` replacing
  `super_admin`), because the shipped permission matrix in
  `RFC_LEARNING_ASSESSMENT_MVP.md:36–47` is expressed in those terms and the code
  branches on `$this->role($request) === 'teacher'`
  (`AssessmentController.php:31, 313, 694`).
- **Do not port the header-auth affordance.** `AttachAuthUser.php:20–24` allows
  `X-User-Id` / `X-User-Role` in `local`/`testing` only, guarded by an explicit
  security-audit comment after a prior auth-bypass finding. Assess's tests should
  authenticate by issuing a real token in the test setup instead. Porting the
  affordance ports the risk.
- A linked campus's staff will have two logins. This is accepted for v1. It is
  annoying and it is not a correctness problem.

SSO (OIDC from AllTrue, or Assess accepting an AllTrue Bearer token) is deferred to
Phase 4 and only if a linked campus actually complains. Note that AllTrue's tokens
are opaque rows in `auth_tokens` looked up by exact match
(`AttachAuthUser.php:31–33`), not JWTs — so cross-system verification would require
a token-introspection endpoint on AllTrue. That is a real cost, and another reason
to wait for demand.

Parents never log into Assess in v1. See §6.

---

## 6. What NOT to build in v1

The team shipped a data contract, an API, a staff workspace, remediation tracking,
a question bank, online attempts, auto-grading, provenance, and a parent portal
projection in about seven hours on 2026-08-21 — and spent 2026-08-22 on a production
credential-rotation incident. The scope discipline below is the direct lesson.

**Cut from v1:**

| Cut | Why | Add when |
|---|---|---|
| Roster sync from AllTrue (all of §4) | The whole point of v1 is proving a campus with **no** AllTrue can use this. Sync serves the linked case. | Phase 3, when a linked campus asks |
| SSO / token introspection | §5 | Phase 4, on complaint |
| Parent portal in Assess | Standalone campuses have no parent portal today and are not asking for one | Phase 4+ |
| Remediation actions (`assessment_remediation_actions`) | Shipped, but downstream of a result workflow nobody has used for a full term yet | Phase 2, after one real term |
| CSV question import | Manual authoring proves the model first | Phase 2 |
| Multi-tenant single deployment | See O-1; per-campus instances are simpler until instance count hurts | when instance count > ~3 |
| Queue workers, event bus, DLQ | Nothing in v1 is async | never, probably |

**v1 = the smallest thing a non-AllTrue campus can actually run:**

1. Admin creates a campus + staff users (seeder/CLI is fine, no UI).
2. Staff import or type in learners (CSV → `learners`, or a plain form).
3. Teacher authors a question bank and items; director reviews to `ready`.
4. Director creates an assessment, attaches questions, publishes.
5. Student takes it online; auto-grading runs; director reviews the attempt.
6. Director sees a summary. Audit rows exist for every state change.

Steps 3–6 are **already written** — they are `QuestionBankController` (290 lines,
zero AllTrue coupling), `AssessmentAttemptService` (294 lines, zero AllTrue
coupling), and the parts of `AssessmentController` that are not the five roster
call sites. Steps 1–2 are the only genuinely new backend code, and they are two
tables and a CSV parse.

---

## 7. Migration path for the shipped Slice 1 work

The shipped code is an asset, not a liability, because of the no-foreign-keys
decision (§1). The plan is **copy out, then delete in place** — never a live dual-write.

### Phase 1 — extract (new repo, AllTrue untouched)

1. `git log --follow` the 10 migrations + 7 models + 2 controllers + 1 service into
   the new repo, preserving history. AllTrue's copies stay live and untouched.
2. Rewrite the 5 coupling sites (`AssessmentController.php:26, 310, 384, 466, 686`)
   against `learners` instead of `Student`/`StudentClass`. Concretely: `CampusID` →
   `learners.campus_id`; the `TeacherID` teacher-scope filter becomes a
   `learner_teachers` assignment or, simpler for v1, drops to campus scope only —
   see O-5.
3. Rename the columns that carry AllTrue's identity: `student_id` → `learner_id`
   across `assessment_results`, `assessment_attempts`, `assessments.student_class_id`
   → dropped. Do this as a fresh consolidated migration in the new repo, not as a
   rename chain — nobody has this data yet outside AllTrue.
4. Port the four feature tests with a real-token auth harness (§5).

### Phase 2 — migrate the Daan campus's live data (only when it exists)

If Daan has accumulated real assessment rows by cutover time — **verify before
assuming; this RFC cannot see production data**:

1. `mysqldump` the 10 tables. No FK ordering problems, by construction.
2. Insert one `campuses` row with `external_ref = <Daan CampusID>`.
3. `INSERT INTO learners (campus_id, display_name, external_source, external_ref)
   SELECT ... FROM alltrue.Student WHERE CampusID = ...` — one-time, from the dump.
4. Remap `student_id` → `learner_id` by joining on `external_ref`.
5. Load into Assess, reconcile row counts per table, keep the dump.

This is a reviewable SQL script, per the established practice that production data
changes need an audit trail rather than admin-panel clicks.

### Phase 3 — delete from AllTrue

Reverse order of dependency:

1. Hide the nav entries (`App.vue:354–355`) behind a flag; confirm no traffic.
2. Delete routes `routes/api.php:654–703`, the two controllers, the service, the
   7 models, the two SFCs, the two lib files.
3. **The parent portal is the one thing that cannot just be deleted.** Options,
   in order of laziness:
   - **(a) Freeze it.** Leave `assessment_results` in AllTrue's DB as a read-only
     historical table; `ParentPortalController.php:1046–1110` keeps working on
     frozen data; new results only appear in Assess. Zero integration work, and
     parents stop seeing new assessment progress in AllTrue.
   - **(b) Pull-back endpoint.** Assess exposes a per-learner reviewed-results
     endpoint; `buildParentAssessmentProgress` fetches it instead of querying
     Eloquent, with a cache and a null-safe fallback so an Assess outage degrades
     the parent portal card to empty rather than 500-ing the whole portal page.
   - **(c) Delete the card.**

   **Recommendation: (a) for cutover, (b) only if a linked campus's parents notice.**
   (b) means a new outbound HTTP dependency in a page AllTrue's parents load
   constantly, during a feature freeze. That is a bad trade for one progress card.
   This one genuinely needs a Founder call — see O-4.

4. Drop the tables only after a retention window (suggest one full term), per the
   rollback discipline already established for this feature.

---

## 8. Sequencing

### Phase 0 — decide (blocks everything)

Ships: answers to O-1, O-2, O-3. Nothing is coded.
Deferred: everything.

### Phase 1 — Assess runs standalone

Ships: new repo; `campuses`/`users`/`learners`; own auth middleware exposing the
four request attributes; ported question bank + assessment + attempt code with the
5 coupling sites rewritten; CSV/manual learner intake; ported tests green; one
deployable instance (dev/staging, not necessarily production).
Explicitly deferred: any AllTrue integration, remediation, parent access, SSO,
production hosting for a real 分校.
Done = a seeded campus with no AllTrue connection completes step 1→6 of §6.

### Phase 2 — first real standalone campus

Ships: production hosting per O-1; backup + restore drill (AllTrue has
`backup-restore-test.yml`; mirror it — do not ship a system with no restore test);
CSV question import; remediation actions if the pilot asks.
Deferred: everything about AllTrue.
Done = one 分校 runs a real 檢測 end to end on their own instance.

### Phase 3 — link Daan / migrate off AllTrue

Ships: AllTrue's `GET /api/v1/integration/roster` behind `api_key`; Assess's
`assess:sync-roster`; data migration per §7 Phase 2; AllTrue deletion per §7 Phase 3.
Deferred: SSO, parent portal integration (option (a) stands).
Done = assessment code no longer exists in AllTrue's `main`; Daan staff use Assess.

### Phase 4 — only on demand

SSO, parent access, cross-campus reporting. Each requires a named campus asking.

---

## 9. Why not just leave it as an AllTrue module

`RFC_LEARNING_ASSESSMENT_MVP.md` was right for the requirement it had. It scoped
Assessment as an additive bounded context beside `LearningRecord`, with no schema
changes to legacy tables and no billing coupling — and the implementation honored
that (no foreign keys, five well-contained coupling sites, a service layer per
ADR-003). Nothing in that RFC is being called a mistake.

What changed is a requirement it never had: **a 分校 that does not buy the 教務行政
系統 must still be able to buy 檢測 + 題庫.** No amount of internal module hygiene
satisfies that, because the unit of sale and the unit of deployment are the same
thing here — AllTrue's production is one Raspberry Pi serving one domain for the
Daan campus (`deploy.yml:36, 108, 320`). Shipping assessment to a campus that does
not use AllTrue would mean deploying AllTrue's scheduling, billing, payroll, PII,
LINE, and RFID surfaces to a campus that wants none of it, and then supporting them.

The second change is the engineering north star's guidance: AllTrue has exactly
one sanctioned architecture line (TD-076 occurrence identity), and the 主任 has now
put the rest in feature freeze. Assessment is a growing product surface — question
banks want import, tagging, analytics, adaptive delivery. That growth curve is
incompatible with bugfix-only. The spinout is what lets both statements be true at
once.

The `RFC_LEARNING_ASSESSMENT_MVP.md` boundary rules survive intact in the new
system, now enforced by a process boundary instead of code review. Its lifecycle
state machines, its permission matrix, its audit-transaction rule, and its
`max_score_snapshot` reasoning all carry over unchanged. This RFC changes where
the code lives, not what it does.

---

## 10. Open questions — need Founder or Codex input

I cannot decide these from the repo.

**O-1 (Founder, blocking Phase 2) — Hosting for a standalone campus.**
AllTrue is one self-hosted Pi. A 分校 without AllTrue has no Pi, no
`daan.lifenet.com.tw`, no SSH target. Options: a Pi per campus (matches the current
operational model, multiplies backup/patch burden by N), one shared cloud instance
with campus scoping (cheapest to operate, requires real multi-tenancy from day one,
and puts several campuses' data in one blast radius), or cloud-per-campus. This
determines whether §3's per-instance model or a tenant column is correct, so it
blocks the schema.

**O-2 (Founder, blocking Phase 1) — New GitHub repo now, or a directory first?**
A new repo gets a clean CI and no accidental coupling, but this repo's governance
lives here — provenance checks, control-plane enforcement, docs-integrity, the bug
SOP. A new repo needs those ported or deliberately dropped, and agent sessions
there will have no guardrails until it is. Recommendation, weakly held: new repo,
port only `ci.yml` + `deploy.yml` + `backup-restore-test.yml` + `AGENTS.md` in
Phase 1.

**O-3 (Founder) — Pricing and packaging.** Whether Assess is sold per-campus,
per-student, or bundled changes whether we need usage metering in the schema. If
metering is ever needed, adding it later means backfilling counts nobody recorded.
This is cheap to decide now and expensive to retrofit.

**O-4 (Founder + Codex) — Parent portal at cutover.** §7 Phase 3 item 3, options
(a)/(b)/(c). Does Daan's parent-facing assessment progress card need to keep
showing *new* results after the spinout? This shipped very recently, so someone
wanted it. If yes, (b) is the only answer and it adds an outbound HTTP call to
AllTrue's parent portal during a feature freeze.

**O-5 (Codex) — Teacher-to-learner scope in a standalone campus.** The shipped code
scopes teachers via `StudentClass.TeacherID`
(`AssessmentController.php:31, 313, 694`), which is AllTrue's enrollment table. A
standalone campus has no enrollments. Either Assess grows a `learner_teachers`
assignment table, or v1 teachers see all learners in their campus. The second is
smaller and probably fine for a single-campus cram school, but it is a real
permission relaxation versus what ships today and should be an explicit choice, not
a side effect.

**O-6 (Codex) — Manual learner creation in a linked campus.** If a campus uses both
systems and staff can also create learners directly in Assess, we get learners with
`external_ref IS NULL` that will never reconcile with AllTrue, and the roster sync
has to decide whether to match them by name (do not) or leave them orphaned. The
lazy answer is to make learner creation read-only for linked campuses — sync is the
only source. Confirm that is acceptable before Phase 3.

**O-7 (Codex, non-blocking) — Naming.** "Assess" is a placeholder in this document.
The 分校-facing product name affects the repo name, the API namespace, and the
migration column names in §7 Phase 1, so it is cheaper to pick before Phase 1 than
after.

---

## 11. Verification bar for Phase 1

- All four ported feature tests green against the new auth harness, with no
  `X-User-Id` header path in any environment.
- One test proves a learner created with `external_source IS NULL` completes the
  full §6 step 1→6 flow — this is the standalone guarantee, and it should fail
  loudly if anyone reintroduces an AllTrue dependency.
- A grep gate in CI: no reference to `Student`, `StudentClass`, `UserCampus`,
  `AuthToken`, or `CampusID` (AllTrue's PascalCase legacy columns) anywhere in the
  Assess repo. This is the cheapest possible enforcement of the boundary and it
  catches the exact regression that would undo the spinout.
- Migration up/down clean on both SQLite and MySQL, as today.
- AllTrue's test suite untouched and green throughout Phases 1 and 2 — AllTrue does
  not change until Phase 3.
