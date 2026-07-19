# AllTrue Design SSOT Recovery (Phase 2, read-only)

**Task:** #1319 · **Date:** 2026-07-19 · **Branch:** `chore/task-1319`

## Canonical authority

| Item | Value |
|---|---|
| **Single source of truth** | [`docs/RULE_DESIGN_SYSTEM.md`](../../RULE_DESIGN_SYSTEM.md) |
| **Token implementation** | `frontend/src/styles.css` (`--ds-*` in `:root`; legacy `--primary` / `--porsche-*` alias to `--ds-*`) |
| **Component library** | `frontend/src/components/design-system/` (`AtButton`, `AtCard`, `AtEmpty`, `AtMetric`, form fields) |
| **Companion docs** | [`docs/GUIDE_UI_COPY.md`](../../GUIDE_UI_COPY.md), [`docs/GUIDE_DESIGN_QA_SMOKE.md`](../../GUIDE_DESIGN_QA_SMOKE.md) |
| **Navigation index** | [`docs/INDEX.md`](../../INDEX.md) §設計系統 |

**Do not create** `design-v2.md`, `new-design.md`, or a parallel design system. Root [`design.md`](../../../design.md) is a thin pointer only.

## Open-source lineage (reference, not authority)

| Source | Role |
|---|---|
| [VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md) → Stripe `DESIGN.md` | Adapted into AllTrue-specific rules in `RULE_DESIGN_SYSTEM.md` (2026-06-06) |
| Inter + Noto Sans TC | Chosen over Stripe Söhne (commercial); documented in §4 Typography |
| Material Symbols Outlined | Icon system; emoji forbidden in UI copy |

No additional open-source design repos are adopted as SSOT. Patterns are extracted into tokens and component specs, not copied wholesale.

## Superseded / conflicting docs

| File | Status | Action |
|---|---|---|
| [`docs/archive/PORSCHE_VISUAL_SYSTEM.md`](../../archive/PORSCHE_VISUAL_SYSTEM.md) | **Superseded 2026-06-06** | Archive only; banner points to `RULE_DESIGN_SYSTEM.md` |
| [`docs/INDEX.md`](../../INDEX.md) | Current | Lists PORSCHE as ⛔ superseded |
| [`.cursor/plans/bug154_design-governance_learning-handoff_2026-06-06.md`](../../../.cursor/plans/bug154_design-governance_learning-handoff_2026-06-06.md) | Historical handoff | Do not treat as live spec |
| Epic #687 rollout tracker (in `RULE_DESIGN_SYSTEM.md` §10) | Living tracker | Issue states authoritative; not a second design doc |

No repo-root `DESIGN.md` existed before this recovery; `design.md` (lowercase) added as pointer per Phase 2 convention.

## Enforcement in code

| Mechanism | Location | Status |
|---|---|---|
| CSS token SSOT | `frontend/src/styles.css` `:root` | **Enforced** — comment cites `RULE_DESIGN_SYSTEM.md` |
| Raw hex guard | `scripts/design-hex-count.sh`, `scripts/check-no-raw-hex.sh`, `docs/design-hex-baseline.json` | **CI** (`npm run lint:design` in `.github/workflows/ci.yml`) |
| Hex KPI | `npm run metrics:design-hex` | Baseline 2026-07-15: **1004** grand total (pages 886 + components 118); today **1000** (882 + 118) |
| PR design gate | `.github/pull_request_template.md` + issue #697 | **Done** — checklist |
| QA smoke | `docs/GUIDE_DESIGN_QA_SMOKE.md` | Manual post-merge |
| `At*` components | `frontend/src/components/design-system/` | Consume `--ds-*` only |
| TD-064 | `docs/TECH_DEBT.md` | Attendance multi-state colors — token gap, not a second system |

**Written but partial:** Phase 2b notes hex guard may become blocking required check (currently may `continue-on-error`). Form/toast unification (#702, #708) still open.

## Page migration status (Epic #687)

| Tier | Pages / scope | Status |
|---|---|---|
| Foundation | AtButton/Card/Empty/Metric (#688), hex CI (#689), UI copy (#690), shell (#698) | Mixed — hex/copy/shell **Done**; shared components **Open** |
| Wave 1 | DirectorDashboard, TeacherHome, LearningRecords, SmartCalendar (#686 PR) | **Done** (light pass); depth issues #699–#700 **Open** |
| Wave 2 | CourseManagement (#691), StudentsList (#692), TeachersList (#693), tuition trio (#694), Attendance (#695) | CourseMgmt + StudentsList **Done**; rest **Open** |
| Wave 3 | ParentPortal, payroll, bugs (#696) | **Open** |

### Highest hex debt (inconsistent with token-first rollout)

| File | Raw hex count (2026-07-19) |
|---|---|
| `CourseManagement.vue` | 365 |
| `CoursePackagesPage.vue` | 99 |
| `ScheduleDiscrepancyPage.vue` | 75 |
| `PaymentSlipModal.vue` | 55 |
| `DirectorAccountsPage.vue` | 39 |
| `StudentsList.vue` | 36 |
| `Login.vue` | 35 |

**Migrated / low hex (aligned):** `LearningRecordsPage.vue` (5), `SmartCalendar.vue` (4), most `At*` components (0–1).

## Recovery actions (documentation only)

1. Keep **`docs/RULE_DESIGN_SYSTEM.md`** as the only maintained design spec.
2. Use root **`design.md`** for agents/tools that expect lowercase `design.md`.
3. When editing UI, prefer `--ds-*` / `At*`; run `npm run lint:design` before PR.
4. After intentional hex cleanup, refresh `docs/design-hex-baseline.json`.
5. Do **not** revive PORSCHE naming in new code; `--porsche-*` remains alias-only for backward compatibility.

## Confidence

**High** — canonical file, archive markers, token wiring, and CI guard all agree. Remaining gap is **rollout completion** (pages still carrying raw hex), not SSOT ambiguity.
