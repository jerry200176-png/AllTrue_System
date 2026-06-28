> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# AllTrue Engineering Governance Audit — 2026-06-27

> **Auditor role:** Principal Engineer / Engineering Excellence Lead  
> **Scope:** Full repository (Phases 1–10)  
> **Constraint:** No CI executed (GitHub Actions minutes exhausted); static analysis + code review only  
> **Production behavior:** Not modified during this audit

---

> **Superseded findings:** Deploy authority conclusions in this audit (ADR-001 / `deploy.yml` DISABLED) are **invalid** after contract consolidation. **Execution per I1:** committed [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).

## Executive Summary

AllTrue has **strong incident-driven governance** (P0 rules, AI_REGRESSION_LESSONS, control plane contract) but **implementation debt** in scheduling/billing domains. **Historical note:** this audit predates CONTRACT-ONLY consolidation; ignore ADR-001 deploy-disable recommendations below.

| Dimension | Score (1–5) | Industry benchmark | Gap |
|-----------|-------------|-------------------|-----|
| Architecture | **2.5** | Stripe/Uber 4+ | Triple scheduling model, god controllers |
| Testing | **3.5** | Google 4+ | Strong Feature tests; webhooks/E2E gaps |
| Documentation | **3.0** | GitHub 4+ | Rich SOP layer; deploy path drift |
| Operations | **3.5** | Amazon 4+ | Backup/rollback mature; observability thin |
| Maintainability | **2.5** | Shopify 4+ | 5k–6k LOC files |
| Reliability | **3.0** | Netflix 4+ | Deduction idempotency, session dupes |
| Scalability | **2.5** | Uber 4+ | Unbounded tuition alert reads |
| Security | **2.0** | Stripe 4+ | Header auth bypass (Critical) |
| Developer Experience | **3.0** | GitHub 4+ | Onboarding fragmented |

**Stop-the-line:** Issue **#970** — `X-User-Id` header impersonation must be fixed before any other security hardening is meaningful.

---

## Phase 1 — Regression Report

### P1 regressions (new, not in open in-app #931–943)

| ID | Finding | Root cause | GitHub |
|----|---------|------------|--------|
| R-01 | **ClassSession creation fragmented** (10+ `ClassSession::create` sites, no unique slot index) | No materialization service; check-then-insert | #957 |
| R-02 | **ApprovalSessionSyncService** binds LR to wrong session when duplicates exist | Fallback `orderBy id desc` across ambiguous matches | #958 |
| R-03 | **Payment truth divergence** — course list vs tuition alerts | AlertController uses `Paid===1` only; StudentClass uses OR with invoice | #959 |
| R-04 | **SessionDeductionService idempotency race** | Non-unique ledger index; check-then-insert | #960 |
| R-05 | **Calendar `dedupeByStudentSlot`** hides overlapping courses | Key excludes `student_course_id` | #961 |

### P2 regressions

| ID | Finding | GitHub |
|----|---------|--------|
| R-06 | ApprovalSessionSync not atomic with deduction | #962 |
| R-07 | `ScheduleController.destroy` orphans ClassSessions | #963 |
| R-08 | Monthly GET auto-materialize TOCTOU | #957 (same epic) |
| R-09 | `EnrollmentService` force bypass amplifies duplicate slots | #957 |
| R-10 | `StudentClassController` read-path counter drift (display ≠ DB) | #964 |

### Related open bugs (pre-existing, not re-filed)

#931–#943, #920 — in-app regression family; triage separately.

---

## Phase 2 — Architecture Report

### Domain model

```
StudentClass (contract) ──► ClassSession (materialized)
        │                           ▲
        └── schedules (exceptions) ─┘
```

**Problem:** Three write authorities with no ADR. Controllers own cross-cutting logic:

| File | LOC | Concern |
|------|-----|---------|
| `StudentClassController.php` | ~5,156 | CRUD + billing + projection |
| `CourseManagement.vue` | ~6,226 | UI god page |
| `ClassSessionController.php` | ~2,671 | Index joins + materialize |
| `FinanceController.php` | ~2,252 | Reports + exports |
| `LearningRecordController.php` | ~2,838 | LR + session side effects |

**Positive boundaries:** `SessionDeductionService`, `calendarOccurrenceMerge.js` (G-007), `EnrollmentService` (partial).

### Architectural debt → Issues

| Item | Issue |
|------|-------|
| ADR-002 Scheduling write authority | #965 |
| Epic: Decompose god controllers | #966 |
| ADR-003 Layering / dependency rules | #967 |
| Frontend API client + composable split | #968 |
| Attendance effect unification (TD-012) | #969 |

### DDD / Clean Architecture assessment

| Pattern | Status |
|---------|--------|
| Bounded contexts | **Weak** — scheduling/billing/attendance intertwined |
| Repository pattern | **Partial** — Eloquent in controllers |
| Domain events | **Minimal** — 2 events; observers likely unregistered (TD-065) |
| CQRS | **Read/write split emerging** — SessionProjectionReadService (good) |
| Hexagonal ports | **None** — direct HTTP→DB |

---

## Phase 3 — Engineering Process Report

### Strengths

- P0 incident memory (6 production accidents documented)
- PR template, branch protection, presubmit 700-line gate
- ADR-001 single deploy authority (decision correct)
- Role-based agent orchestration (AGENTS.md)
- Rollback runbook + post-merge smoke script

### Gaps (issues already open unless noted)

| Gap | Status | Issue |
|-----|--------|-------|
| Blameless postmortem template | Open | #873 |
| DORA dashboard | Open | #872 |
| Merge queue | Open | #871 |
| CI HA / runner SPOF | Open | #870 |
| Feature flags production | Open | #869 |
| Staging environment | Open | #868 |
| Service catalog / RACI | Open | #893 |
| **Deploy doc drift vs ADR-001** | **Fixed this audit** | docs batch |
| Platform enforcement not live on GitHub | Open | #939, #875 |

### Branch / release

- GitHub Flow with feature branches ✓
- Release tags via CHANGELOG ✓
- **Gap:** No release train cadence (#897)

---

## Phase 4 — Documentation Report

### Updated this audit

| File | Change |
|------|--------|
| `docs/reviews/ENGINEERING_AUDIT_2026-06-27.md` | This report |
| `docs/INDEX.md` | Deploy workflow row → ADR-001 |
| `README.md` | Deploy section → ADR-001 |
| `docs/SOP_MATURITY.md` | Audit handoff note |
| `docs/TECH_DEBT.md` | TD-067/068/069 registered |

### Still needs work (Issues)

| Gap | Issue |
|-----|-------|
| REF_API_ROUTES.md missing | #992 |
| WSL2 guide deploy diagram | #993 |
| 30-min onboarding package | #898 (existing) |
| ADR-002/003 authorship | #953, #955 |

### Duplication risk

`engineering-system.md` + 6 layer docs + `current-engineering-sop.md` — acceptable if read-order enforced; consolidate github-ruleset stubs (#896 area).

---

## Phase 5 — Testing Report

### Coverage snapshot

| Layer | Tests | Sources | Notes |
|-------|-------|---------|-------|
| Backend Feature | 184 | 212 app files | Strong integration |
| Backend Unit | 3 | — | **Critically thin** |
| Frontend unit | 40 | ~184 JS | Calendar well tested |
| E2E Playwright | 1 spec | — | Teacher + director smoke only |

### Critical path coverage

| Path | Status |
|------|--------|
| RFID swipe | ✅ Strong |
| Tuition alerts | ✅ Strong |
| Session deduction | ✅ Good |
| Enrollment overlap | ✅ Good |
| Parent name+phone login | ✅ Good |
| **LINE webhook** | ❌ Zero tests → #980 |
| **Parent login-line** | ❌ Zero tests → #981 |
| **Telegram webhook** | ❌ Zero tests → #982 |
| E2E parent portal | ❌ Missing → #983 |

### Missing infrastructure

- Mutation testing: none
- Load testing: none (SLO unverified under load)
- Visual regression: deferred (TD-021)

---

## Phase 6 — Security Report

### Critical

**#970 — `X-User-Id` header impersonation** (`AttachAuthUser.php` L17–33): Any client sending `X-User-Id` bypasses Bearer token validation. Combined with `X-User-Role`, enables privilege escalation.

### High

| Finding | Issue |
|---------|-------|
| Unauthenticated `POST /debug-log` | #971 |
| `DebugSwipeRfidRequest` on every swipe | #971 |
| Parent login PII in logs | #972 |
| LINE webhook skips signature if secret empty | #973 |
| Telegram webhook no secret validation | #973 |

### Medium

| Finding | Issue |
|---------|-------|
| Feature flags / policy routes unauthenticated | #974 |
| Engagement mutation routes outside role groups | #974 |
| Super-admin campus API echoes secrets | #975 |
| `backend/.env` not gitignored | #976 |
| `create_admin.php` hardcoded creds | #976 |
| Dependency CVEs (guzzle, Laravel 8 EOL) | #977 |
| BugReportsPage wrong token from localStorage | #978 |
| RoomController cross-campus mutations | #979 |

### Positive controls

- Global API throttle 200/min
- Swipe-rfid 30/min + campus bearer
- Parent login 5/10 min
- SecurityHardeningTest suite
- PIN middleware on sensitive finance routes

---

## Phase 7 — Performance Report

| Finding | Severity | Issue |
|---------|----------|-------|
| `AlertController::tuition` unbounded `->get()` | P1 | #984 |
| `ClassSessionController::teacherTrust` correlated subquery | P2 | #985 |
| `ParentPortalController` N+1 teacher names | P2 | #986 |
| `StudentsExport` full table memory load | P2 | #987 |
| `FinanceController::subjectUnits` unbounded sessions | P2 | #988 |
| Hot endpoints no server-side cache | P3 | #989 |

**Positive:** TD-062 calendar window cache; query-count test for auto-materialize; DatabasePerfTest index checklist (extend per #990).

---

## Phase 8 — Engineering Maturity Scores

Scoring: 1 = ad-hoc, 3 = industry adequate for team size, 5 = industry-leading.

| Dimension | Score | Rationale |
|-----------|-------|-----------|
| Architecture | 2.5 | Good chokepoints exist but domain boundaries blurred |
| Testing | 3.5 | Feature coverage strong; unit/E2E/webhook gaps |
| Documentation | 3.0 | Comprehensive SOP; drift on deploy until this audit |
| Operations | 3.5 | Backup, rollback, health; weak centralized logging |
| Maintainability | 2.5 | Multi-thousand-line files block safe change |
| Reliability | 3.0 | Idempotency and duplicate-session risks |
| Scalability | 2.5 | Pi adequate today; unbounded queries at growth |
| Security | 2.0 | Critical auth bypass; otherwise reasonable middleware |
| Developer Experience | 3.0 | INDEX/MemPalace good; onboarding incomplete |

---

## Phase 9 — Issue Summary

### New issues created (this audit): #957–#995 (39 issues)

| Label cluster | Count | Numbers |
|---------------|-------|---------|
| security | 10 | #970–#979 |
| regression / bug | 6 | #957–#961, #963–#964 |
| architecture | 5 | #965–#969 |
| testing | 5 | #980–#983, #991 |
| performance | 7 | #984–#990 |
| documentation | 2 | #992–#993 |
| engineering (process) | 2 | #962, #994–#995 |

### Pre-existing open (not duplicated): 68 → ~103 after audit

Group by label intent:

- **Bug/Regression:** #920, #931–#943 + R-01–R-10
- **Security:** #887–#891, #964–#972
- **Architecture:** #953–#957, #896
- **Performance:** #973–#979, #885
- **Testing:** #905, #960–#963, #904
- **Documentation:** #894, #898, #958–#959
- **Infrastructure:** #868–#871, #875, #939
- **SOP/Engineering:** #873, #897, #900

---

## Top 20 Priorities

| Rank | Issue | Why |
|------|-------|-----|
| 1 | **#970** Header auth bypass | Active exploit path; stop-the-line |
| 2 | **#971** Remove debug surfaces | Production disk/DoS + data leak |
| 3 | **#957** ClassSession materialization epic | Root cause of #932/#933/#920 family |
| 4 | **#959** Payment truth unification | Billing display inconsistencies |
| 5 | **#931–#936** Open in-app bugs | User-visible regressions |
| 6 | **#980** LINE webhook tests | Zero coverage on bind surface |
| 7 | **#984** Tuition alert scale | Director dashboard hot path |
| 8 | **#965** ADR-002 scheduling | Prevents future drift |
| 9 | **#972** Parent login PII logs | Compliance risk |
| 10 | **#973** Webhook hardening | Spoofing / bind attacks |
| 11 | **#960** Deduction idempotency | Double-charge risk |
| 12 | **#958** ApprovalSessionSync fix | Wrong session on approve |
| 13 | **#961** Calendar dedupe overlap | SmartCalendar missing courses |
| 14 | **#966** God controller decomposition | Maintainability blocker |
| 15 | **#939** Platform Gate Phase 0 | CI enforcement when minutes restore |
| 16 | **#868** Staging environment | Safe pre-prod validation |
| 17 | **#977** Dependency CVEs | Supply chain |
| 18 | **#983** E2E parent smoke | #905 matrix implementation |
| 19 | **#992** API reference | Onboarding + contract tests |
| 20 | **#882** DR tabletop | Pi single-host risk |

---

## Recommended Roadmap

### 30 days (stabilize + secure)

1. **Week 1:** #970, #971, #972, #973 — security stop-the-line PR (manual test on Pi clone)
2. **Week 2:** #957 Phase A — unique index + shared `ensureSlot()`; #958, #960
3. **Week 3:** #959 payment resolver; #961 calendar dedupe; merge #937/#938 if still open
4. **Week 4:** #980, #981 webhook/login-line tests; #984 tuition query budget test

### 60 days (architecture + quality)

- ADR-002/003 merged (#965, #967)
- Begin #966 controller extraction (StudentClass → services)
- #868 staging Pi or container
- #983 E2E parent + director specs
- #939 Platform Gate when Actions minutes restored

### 90 days (maturity M4)

- #869 feature flags for calendar/scheduling kill-switch
- #871 merge queue
- #872 DORA dashboard wired
- #882 DR tabletop executed
- #966 Phase 2 — FinanceController / AlertController extraction

---

## Audit Methodology

- Static code review across 898 PHP/JS/Vue files
- Cross-reference with `docs/TECH_DEBT.md`, `AI_REGRESSION_LESSONS.md`, 68 open GitHub issues
- Subagent parallel audits: regression, security, testing/perf, architecture/docs
- No PHPUnit/Playwright execution (Actions minutes exhausted per CEO directive)
- No production SSH changes

---

## Sign-off

**This system is safe for human-controlled deployment only.** Auto-deploy (`deploy.yml`) must remain disabled until Platform Gate (#939) and ADR-001 enforcement are fully operational.

Next agent: Start with #970 fix branch → manual security test → then #957 materialization epic planning.
