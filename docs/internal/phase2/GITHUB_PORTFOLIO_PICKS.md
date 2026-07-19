# GitHub Portfolio Picks — AllTrue (Phase 2, document only)

**Task:** #1319 · **Repo:** [jerry200176-png/AllTrue_System](https://github.com/jerry200176-png/AllTrue_System)  
**Snapshot:** `/tmp/phase2-github-portfolio.json` (2026-07-19)  
**Queue:** 63 open issues · 15 open PRs · 5 drafts · 9 failed-CI PRs · 35 `needs-decision`

## Priority formula (Phase 2)

```text
Expected loss avoided
+ affected users
+ frequency
+ recurrence prevention
+ dependency unblock
+ strategic product value
- implementation effort
- regression risk
```

**Closure-first rule:** If a PR already addresses the problem, prefer review → conflict fix → tests → merge/close over opening duplicate work.

---

## Pick 1 — Highest risk (Risk Closure Track)

| Field | Value |
|---|---|
| **Item** | **PR [#1292](https://github.com/jerry200176-png/AllTrue_System/pull/1292)** — `fix(scheduling): preserve substitutes in atomic reschedules` |
| **Classification** | **P1 user-blocking defect** (scheduling integrity) · **recurring bug family** adjacency (#1282, #1286) |
| **State** | Open · **mergeable: CONFLICTING** · not draft · age 1d |
| **Linked issues** | #1282 (partial); does not close alone — production verify still required |

### Rationale (formula)

| Factor | Score / note |
|---|---|
| Expected loss avoided | **High** — ordinary reschedules dropping substitute teachers breaks calendar truth and director trust (same domain as #1062/#1130 pain) |
| Affected users | **Medium–High** — every campus using substitute + reschedule flows |
| Frequency | **High** — reschedule is daily ops |
| Recurrence prevention | **High** — fixes class of payload bug ( erroneous `teacher_id` in `commitReschedule`) |
| Dependency unblock | **Medium** — unblocks production verification for #1286 family |
| Strategic product value | Medium (ops reliability > new feature) |
| Implementation effort | **Low** — PR exists; primary work is **conflict resolution + CI** |
| Regression risk | **Medium** — PR self-rates Medium Risk; intentional omission of `teacher_id` must not block intentional teacher changes |

**Why not #1062 / 1681 stranded sessions first?** Larger financial exposure (~1681 sessions) but **no ready mergeable PR**; repair is CEO-GO gated (`1062-track-a-pcr.md`). Phase 2 Risk Track allows one active reliability PR — #1292 is the highest **close-first** item with code in flight.

**Why not money/data P1 issues (#1096, #1152)?** Classified **Founder decision** / outreach policy — not a conflict-blocked PR ready for engineering closure.

### Recommended next steps (no new PR)

1. Rebase/resolve conflicts on #1292 against `main`.
2. Re-run calendar regression + frontend build (author verified locally).
3. Merge → deploy → verify substitute preserved on production reschedule.
4. Close or update #1282 with production evidence.

---

## Pick 2 — Highest value user-facing (Product Quality Track)

| Field | Value |
|---|---|
| **Item** | **PR [#1200](https://github.com/jerry200176-png/AllTrue_System/pull/1200)** — `[P1] 家長 LINE 通知整合 — LIFF ?tab= 深層連結支援` |
| **Classification** | **P1 user-blocking defect** · **UX friction** (parent journey) |
| **State** | Open · age 4d · in failed-CI set (portfolio JSON) |
| **Scope** | `ParentPortal.vue` — `?tab=schedule|attendance|billing` deep links for LINE Flex buttons |

### Rationale (formula)

| Factor | Score / note |
|---|---|
| Expected loss avoided | **Medium** — parents landing on wrong tab after LINE notification → missed billing/attendance actions |
| Affected users | **High** — all LINE-linked parents (primary off-app channel) |
| Frequency | **High** — every schedule/attendance/tuition push |
| Recurrence prevention | Medium — completes P1 three-scenario contract documented in PR body |
| Dependency unblock | **High** — backend Flex templates already assume these LIFF endpoints |
| Strategic product value | **High** — parent trust + reduces “通知点了没反应” support load |
| Implementation effort | **Low** — focused frontend routing; no schema change |
| Regression risk | **Low** — default tab unchanged when param absent |

**Why not #1131 (評量審核批次)?** High director value but **Founder decision** class, no open PR in portfolio top list; larger scope than one Phase 2 product PR slot.

**Why not #1047 (TeacherHome fetch)?** P1 performance/dedupe — reliability-weighted; parent LINE deep link wins on **direct user-facing outcome** and **backend contract already shipped**.

### Recommended next steps

1. Fix CI on #1200 (or merge via #1227 bundle if that remains the chosen integration path — prefer **smallest shippable unit** first).
2. LIFF manual test matrix from PR body (LINE in-app + browser).
3. Production verify `/#/parent?tab=billing` etc. after deploy.

---

## Portfolio context (not selected now)

| Item | Class | Why deferred |
|---|---|---|
| #1062 / 1681 stranded | money/data | CEO GO + classification baseline first (#1319 Task B) |
| #1152 dormant pilot | Founder decision | Policy approval, not code PR |
| #1130 duplicates | Founder decision | Repair PCRs exist; separate from substitute fix |
| Draft docs PRs (#1254, #1232, …) | governance / UX docs | Zero production user outcome until product PR lands |
| #1007 credential P0 | security | Founder decision; no open PR |
| PR #1225 storage/logs | money/data/security | Strong security pick; second risk slot if #1292 closes quickly |

---

## Phase 2 slot discipline

| Track | Selected | Max active |
|---|---|---|
| Risk Closure | PR #1292 | 1 |
| Product Quality | PR #1200 | 1 |
| Open-source research | TBD — must serve chosen journey (parent LINE / director calendar) | 1 read-only |

No new product-redesign PRs opened from this classification pass.
