# In-App #332 / #337 Navigation Boundary — Bounded Plan

## Plan identity

- SourceRefs: `alltrue:bug_report:332`, `alltrue:bug_report:337`
- Founder decision: option A (2026-09-21)
- Baseline: `b5bd6dbffff1d618e47bf6f5b4f338df4a55d9f4`
- Planner: Sol (`gpt-5.6-sol` requested/effective; task `/root/sol_nav_plan_text`)
- Plan revision: `P2`
- Revision reason: P1 named `src/views` paths that do not exist in this repository; this revision binds the same bounded plan to the actual `frontend/src` paths and includes the existing navigation helpers.
- Scope fingerprint: `P2-b5bd6db-332-337-founder-A-course-student-nav`
- Plan content SHA-256 (excluding this identity line): `18c3831844f2a9f016bafa4f777bf14d832265c95086cae7d93d6e85949b94ed`

## Product boundary

- Course Management remains the lookup, scheduling, and operations lens.
- Student Management owns course creation, renewal, purchase, and student-data operations.
- Cross-page actions must state the ownership destination and carry a verifiable return context so completion/cancellation returns to the originating course view.
- Learning Assessment and Question Bank routes and entrances remain available until a TrueFit replacement is production-ready.
- `App.vue` receives only the necessary navigation/return-context plumbing; no shell or router rewrite.

## Exact implementation boundary

Only these product files may change in this slice:

- `frontend/src/pages/CourseManagement.vue`
- `frontend/src/App.vue`
- `frontend/src/lib/authoritativeMutationRoutes.js`
- `frontend/src/lib/dashboardReturnContext.js`
- Existing focused tests under `frontend/src/components/__tests__/`, with at most one new navigation-boundary test if existing coverage cannot express the contract.

No backend, migration, schema, permissions, billing calculation, identity, or production-data files are in scope.

## Existing authority and expected behavior

- Reuse `buildStudentsCommercialNav()` for Student Management actions and existing `onNavigateFromCourseManagement()` / `onNavigateFromNotifications()` dispatch.
- Reuse the existing return-context affordance rather than adding a router or global state system.
- Keep existing Course Management local operations that are explicitly operations-owned (scheduling, trial conversion, package set-total, and read-only reconciliation) unchanged.
- Preserve Assessment and Question Bank rendering in `frontend/src/App.vue`.

## Focused acceptance tests

1. Course Management labels identify lookup/scheduling/operations versus Student Management ownership.
2. Create/renew/purchase/student-data actions navigate to Student Management with student/course identity and intent intact.
3. A Course Management → Student Management navigation exposes a return affordance; completing or cancelling returns to the originating Course Management context. Missing/invalid context safely degrades.
4. Existing direct Student Management navigation remains unchanged.
5. Assessment and Question Bank entries remain present and usable.
6. Existing ownership, Course Management, App navigation, and dashboard return-context tests remain green.

Run targeted Vitest suites first; run repository-approved lint/build checks through the governed workflow. Full builds use `local-heavy-gate -- <cmd>`.

## Review, release, and rollback

- This is a reversible T1/T2 UI/navigation change with no data writes or new authority. Review must check the ownership matrix, return-context contract, preserved routes, and exact file boundary.
- Required exact-head CI and independent review must pass before merge. Use the canonical deploy workflow only after merge; do not use Pi SSH or production credentials.
- Runtime evidence must cover Course → Student action → complete/cancel → return to original Course context, plus Assessment/Question Bank reachability. Separate merged, deployed, runtime-verified, and operationally accepted claims.
- Rollback is a revert of the single squash merge through normal CI/deploy; verify the prior navigation and preserved learning routes.

## Non-scope and collision stop

- No App shell decomposition, route redesign, TrueFit replacement, backend/API/schema change, billing implementation for #322, duplicate-review implementation for #316, production activation for #299, or In-App resolved writeback before runtime evidence.
- If an existing router, permission contract, or page implementation contradicts the approved ownership boundary, stop that portion and request a product-owner amendment; do not add dual entry points or hidden fallbacks.

## Evidence and writeback

After delivery, record exact head, focused tests, review/CI, merge, deploy, runtime evidence, and rollback SHA. Keep #332/#337 triaged until the user path is verified; only then use the existing public Phase-C path for source-scoped writeback.
