# AllTrue → AI-native SaaS — Evolution Roadmap

> **Status:** Living roadmap (2026-07-10). Owner: CEO/CTO. Companion to
> `docs/MODULE_PRODUCT_ENGINEERING_MATURITY_ROADMAP.md`.
> **Principle:** every AI/BI feature reads a *stable, tested metric surface* — never
> raw god-controller queries — so intelligence is trustworthy and cheap to evolve.

## Where we are (2026-07-10)
Reliability recovery is complete: canonical DB constraint (#957 D1), honest deploy
(R62/R67), scheduler heartbeat (R68), nightly reproduction gate (#1080), private repo,
rotated secrets. The system is **stable and self-auditing**. It is *not yet*
**intelligent** — it reports state, it does not yet predict, explain, or act.

## Phase 0 — Metric surface (SHIPPED this cycle)
- `BusinessDigestService` + `ops:business-digest` (read-only, nightly 04:10): revenue-at-risk,
  retention-risk, data-quality anomalies, forward coverage. **This is the substrate** every
  later phase reads. Threshold anomalies included as the explainable seed set.

## Phase 1 — Business Intelligence dashboard (highest ROI, ~2-3 wk)
- Expose `BusinessDigestService::metrics()` via an authenticated admin endpoint
  (`GET /api/v1/admin/business-digest`, super_admin + campus scope).
- Director cockpit widget (RULE_DESIGN_SYSTEM): revenue-at-risk NT$, retention-risk count,
  data-quality health, 7-day coverage — with drill-down to the underlying courses/students.
- Persist a daily snapshot (`business_digest_snapshots`) so trends (WoW/MoM) are chartable.
- **Value:** turns the owner's "how is the business?" into one screen; makes #1062 revenue
  leakage visible in NT$, not issue threads.

## Phase 2 — Automated anomaly detection (~2-4 wk)
- Upgrade the threshold flags into trend/seasonality-aware detection on the snapshot series
  (e.g. week-over-week retention-risk spike, revenue-at-risk acceleration, attendance drop
  per campus). Start statistical (z-score / STL residual), not ML — explainable first.
- Route anomalies to the existing alert channel (Telegram/Sentry) as a daily/weekly signal.
- **Value:** the system tells the owner *what changed and why it matters* before a human notices.

## Phase 3 — Retention & revenue intelligence (~3-5 wk)
- Retention model inputs already computable: attendance cadence, gap since last session,
  remaining-sessions burn rate, leave frequency, evaluation sentiment (LearningRecord content).
- v1: rule-based churn-risk score per student (no ML) surfaced to directors with the "why".
- v2: LLM-assisted summary of at-risk students + suggested outreach (owner-approved actions only).
- **Value:** proactive retention is the highest-leverage revenue lever for a補習班.

## Phase 4 — AI-assisted administration (~ongoing, guardrailed)
- LLM-drafted (human-approved) parent replies for in-app bug/feedback (extends GUIDE_SUPPORT_REPLY_MACROS).
- Natural-language ops queries over the metric surface ("which 大安 students are churn-risk?").
- Draft-only, never auto-execute writes; every mutation stays behind existing authz + PCR gates.

## Phase 5 — Automated engineering maintenance (~ongoing)
- Extend the reproduction gate into a self-healing loop: when an owner-gated divergence has an
  approved, snapshot-backed repair (like #1130 p1-ghost), offer a one-click PCR execution.
- Dependency/security auto-triage (Dependabot + osv-scanner already run) → auto-open scoped issues.
- DORA + digest metrics into a monthly auto-generated engineering scorecard (#994/#904).

## Non-negotiable guardrails (apply to every phase)
1. **Read models first** — AI reads `*Service`/view models (#957), never god controllers (ADR-003).
2. **Draft, don't act** — AI proposes; humans approve; writes go through existing authz + PCR.
3. **Explainable before ML** — thresholds/rules with a stated "why" before any model.
4. **Campus isolation & PII** — every metric/endpoint respects campus scope; no student PII leaves the box without owner sign-off (#889 PII inventory is a prerequisite for any external LLM use).

## Sequencing by the owner's priorities (impact→value→security→reliability→leverage)
Phase 0 ✅ → **Phase 1 (BI dashboard)** → Phase 2 (anomaly) → Phase 3 (retention) in parallel with
resolving the two open P0s (#1062 forward-generation, which Phase-0 now quantifies in NT$; and the
canonical read-model build #957 that Phases 1-3 depend on). Phases 4-5 ride on top once 1-3 land.
