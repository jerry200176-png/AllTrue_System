# Founder decision packets — Bug closure queue (2026-07-22)

> Engineering continues other queue items. These require Founder / director / channel unlock only.

## Production anchor

- Health: `GET /api/v1/health` → ok (checked 2026-07-22)
- Deployed SHA: `cd9f3fe0` (matches `origin/main`)
- Agent blockers: GitHub Issues API 403; `workflow_dispatch` 403; no local Pi SSH. Inventory via push-triggered Actions after this packet lands.

---

## D1 — in-app #173 supersede repair (Owner Execute)

| Field | Content |
|-------|---------|
| Confirmed | Code path + `173-supersede-repair.yml` merged; **0** successful Owner executes historically |
| Evidence | Decision packet `docs/incidents/173-decision-packet-2026-07-16.md`; dry-run artifacts under `docs/incidents/evidence/` |
| Recommend | Owner runs dry-run → review → execute allowlisted IDs only |
| Alternative | Keep `in_progress`; no further engineering without execute |
| Risk if wait | Historical superseded sessions remain ambiguous for attendance/LR |
| Blocks | Closing in-app #173 |

**Decision needed:** GO / NO-GO for `173-supersede-repair.yml` execute (phrase + backup per workflow).

---

## D2 — #1062 stranded prepaid forward-gen

| Field | Content |
|-------|---------|
| Confirmed | Classify refresh 2026-07-19: **1591** stranded sessions / **286** courses; active 21d = **154** sessions / **16** courses; dormant 1437/270 |
| Evidence | Actions run `29688129788` artifact `stranded-classify-refresh.json`; G-010 |
| Recommend | **No** bulk execute. If GO: branch-scoped `sessions:generate-forward --dry-run` then active slice only per `docs/runbooks/1062-track-a-pcr.md` |
| Alternative | Director outreach for dormant (#1152) separate from calendar writes |
| Risk if wait | Active students prepaid but no upcoming calendar rows |
| Blocks | Calendar completeness for active prepaid cohort |

**Decision needed:** GO active-slice forward-gen / defer / outreach-only.

---

## D3 — #1342 leave-HC director CSV delivery

| Field | Content |
|-------|---------|
| Confirmed | 19 HC candidates packaged; all 4 campuses `awaiting_delivery`; `channel_result=skipped_no_line` (empty staff LINE group); Gmail SMTP previously 535 |
| Evidence | `operations/closeout/leave-hc-campus-review-tracker.json`; closeout `leave-cascade-slot-times-closeout-2026-07-19.md` |
| Recommend | Ops delivers campus CSV via working channel (set LINE group IDs **or** Founder-approved alternate); directors mark approve/keep/review; execute **only** approved session IDs |
| Alternative | Explicit defer past SLA (`final_defer_after` 2026-07-25) without write |
| Risk if wait | Historical wrong weekday clocks remain until director-approved repair |
| Blocks | Closing #1342 |

**Decision needed:** Provide delivery channel credentials / handoff owner / defer.

---

## D4 — Historical billing repairs (#189 / #191 / #190)

| Field | Content |
|-------|---------|
| Confirmed | Code fixes shipped for several billing paths; historical row repair plans exist and stay Draft |
| Evidence | `docs/incidents/189-191-*`, `190-historical-billing-repair-plan.md`, GUIDE_BUG_CLOSURE_GATE |
| Recommend | Case-by-case CEO GO per repair manifest; no reopen of code-complete in-app tickets without new reporter evidence |
| Alternative | Leave resolved + reporter-verify / timeout |
| Risk if wait | Known mis-charged historical invoices remain until approved repair |
| Blocks | Closing related in-app tickets that need data repair |

**Decision needed:** Per-case GO on repair manifests (not a blank cheque).

---

## Not asking (already decided / autonomous)

| Item | Status |
|------|--------|
| Teacher leave-slot cascade + longer makeup | Code + prod verified 2026-07-19; historical batch execute **rejected** |
| TD-059 schema | NO-GO; monitor only (#1343) |
| #1262 SignIn orphans | Closed after overnight evidence |
| #205 / #198 Phase C | Allowlist workflow idempotent (skip if closed) |
| #207 teacher history | Code merged #1374 + deployed `7acb5803` / run `29890459105`; Phase C allowlist next. Optional one-shot historical pin for already-rewritten past sessions if reporter confirms residual wrong teacher |
