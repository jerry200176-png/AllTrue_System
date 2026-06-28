# Product to Engineering Maturity Roadmap

> Purpose: give a fresh Cursor or Claude Code session a durable roadmap from user experience to engineering maturity after GitHub Actions minutes recover around 2026-07-01.
>
> Status: draft created during Actions freeze. Do not push or merge until Actions capacity is confirmed.

---

## 1. North Star

AllTrue should evolve from "features exist" to "each role knows the next step, trusts the data, and the system can be operated safely."

The roadmap is ordered by business value:

1. **Product maturity**: teacher, director, and parent experience becomes understandable, role-specific, and low-friction.
2. **Data trust**: session counts, billing, attendance, and learning records become explainable and auditable.
3. **Support loop maturity**: in-app bugs, public replies, deploy status, and user verification become one closed loop.
4. **Security / privacy maturity**: access, PII, secrets, audit logs, and retention become reviewable.
5. **Reliability / SRE maturity**: CI, deploy, backup, rollback, staging, and incident response become resilient.
6. **AI handoff maturity**: every future agent can recover context from docs and GitHub, not from chat memory.

---

## 2. Product Maturity

### Teacher Experience

Goal: a teacher opens the system and immediately knows what to do today.

Priority outcomes:

- TeacherHome shows only role-relevant, plain-language tasks.
- "System Trust" content hides internal engineering terms and translates risk into teacher action.
- Attendance and learning-record flows work well on mobile.
- Teacher schedule, substitute sessions, and pending evaluations are consistent across branches.

Current GitHub anchors:

- #909: teacher-facing System Trust plain language.
- #910: teacher next-step explanation layer.
- #905: role-based QA matrix.

Definition of done:

- A teacher can answer "what do I need to do now?" from the first screen.
- No public teacher UI uses internal terms such as defect, deploy, high-priority issue, CI, or PR.
- Key teacher journeys have QA rows in #905.

### Director Experience

Goal: the director sees operational risk early and trusts every number.

Priority outcomes:

- DirectorDashboard metrics explain "why this number exists."
- Tuition / renewal reminders drill down to student, course, reason, and next action.
- Session-count and billing anomalies become review items before users report them.
- High-risk data corrections require explicit product/director confirmation.

Current GitHub anchors:

- #911: DirectorDashboard drill-down / explanation layer.
- #901: readonly data-quality checks.
- #920: session/billing needs-decision case.
- #878: release / deploy / in-app traceability.

Definition of done:

- Every dashboard alert has a reason, owner, and next action.
- Billing/session anomalies are visible as readonly reports before correction.
- The director can distinguish unpaid, low sessions, monthly due soon, and data anomaly without asking engineering.

### Parent Experience

Goal: parents know what happened, what is pending, and what will happen next.

Priority outcomes:

- ParentPortal first screen prioritizes next class, latest learning update, billing/session status, and pending requests.
- Comments, leave requests, billing follow-ups, and learning updates have timeline/status language.
- LINE/LIFF entry is stable and privacy-safe.
- Parent-visible release notes remain audience-filtered.

Current GitHub anchors:

- #912: parent status timeline and proactive notification.
- #889: PII inventory / retention.
- #903: privacy request SOP.
- `docs/LINE_LIFF_CHECKLIST.md`.

Definition of done:

- A parent can answer "has this been handled?" without calling the front desk.
- Parent UI does not leak internal workflow or staff-only release notes.
- Parent auth / LINE flows are covered by security and QA checks.

---

## 3. Data Trust Maturity

Goal: core data is trusted because anomalies are detected, explained, and corrected through controlled workflows.

Priority issues:

- #901: data quality checks.
- #920: current decision-needed billing/session anomaly.
- #881: PITR / binlog recovery.
- #882: full server DR tabletop.

V1 checks should be readonly:

- Session balance invariant.
- Minutes ledger invariant.
- Payment double-truth invariant.
- Attendance / ClassSession status consistency.
- Learning-record coverage.
- Campus isolation sanity.

Rules:

- Report first, then decide, then fix minimally.
- No automatic data repair in v1.
- No production SQL dump in repo, docs, or issue comments.
- Any data correction must have backup and rollback path.

Definition of done:

- A weekly or manual report identifies high-risk anomalies.
- Each anomaly has severity, affected student/course, suspected cause, and safe next action.
- Corrections are tracked as separate issues or PRs.

---

## 4. Support Loop Maturity

Goal: user reports move from report -> triage -> fix -> deploy -> public verification without losing state.

Current anchors:

- `docs/CHAT_BUG_SYSTEM.md`
- `docs/GUIDE_SUPPORT_REPLY_MACROS.md`
- #895: in-app bug / support SLA metrics.
- #907: public reply macro library.
- #878: release / deploy / in-app traceability.

Target flow:

1. In-app bug is triaged with a plain-language public reply.
2. GitHub issue holds technical investigation and acceptance criteria.
3. PR references the issue and in-app id.
4. Merge waits for CI and deploy status.
5. In-app issue moves to resolved only after deployed behavior is verified.
6. Reporter verification closes the loop.

Definition of done:

- No GitHub-only closure for in-app reports.
- Public comments contain no table names, SQL, class names, or internal PR jargon.
- Every resolved in-app bug can be traced to deploy/version evidence.

---

## 5. Security and Privacy Maturity

Goal: AllTrue can explain who has access, what PII exists, how it is protected, and how sensitive actions are audited.

Priority issues:

- #888: IAM / access review.
- #889: PII data inventory / classification / retention.
- #890: sensitive action audit log coverage.
- #902: security exception register.
- #903: privacy request SOP.

V1 deliverables:

- Access inventory with owner, permission level, business reason, last reviewed date.
- PII inventory with data class, purpose, allowed roles, retention, masking, deletion/correction path.
- Secret inventory without secret values.
- Sensitive action audit coverage map.

Non-negotiables:

- Never paste secrets, tokens, SSH keys, SQL dumps, or full PII into GitHub/docs.
- Use synthetic data for tests and examples.
- Treat screenshots from bug reports as PII-bearing by default.

Definition of done:

- High-privilege accounts have owners and review dates.
- PII fields have masking/export/retention rules.
- Security exceptions have expiry and re-review conditions.

---

## 6. Reliability and Engineering Maturity

Goal: AllTrue can ship safely, recover quickly, and avoid repeating known accidents.

Priority issues:

- #867: move GitHub-hosted required work away from Actions minutes bottleneck.
- #870: CI high availability and usage alerting.
- #868: staging / pre-prod.
- #875: GitHub Environments.
- #878: release / deploy / in-app traceability.
- #881 / #882: PITR and DR.

Actions recovery rule:

- Confirm billing/minutes before rerunning.
- Do not merge red checks.
- Do not push main.
- Do not force push.
- Do not deploy manually to production.
- Do not run test/phpunit/artisan test on the Pi.

Definition of done:

- CI has reliable capacity and required checks.
- Staging exists before production for risky changes.
- Rollback, health check, version verification, and in-app resolution are linked.
- Production data recovery is drill-tested outside production.

---

## 7. AI Handoff Maturity

Goal: a fresh AI session can resume work from docs and GitHub alone.

First-read order for a new session:

1. `docs/INDEX.md`
2. `docs/SOP_MATURITY.md` top `進行中狀態`
3. GitHub #870 / #867 latest comments
4. `gh pr list --state open`
5. Relevant issue comments for #901, #905, #888, #889, #893, #920

Durable breadcrumbs already created:

- #870 / #867: 2026-07-01 Actions recovery handoff.
- #901: readonly data-quality rules.
- #905: role-based QA matrix.
- #888: access review checklist.
- #889: PII inventory checklist.
- #893: service catalog / RACI spec.
- #920: director decision checkpoint.

Local unpushed docs batch:

- `.cursorrules`
- `AGENTS.md`
- `backend/docs/line_setup.md`
- `docs/AI_REGRESSION_LESSONS.md`
- `docs/INDEX.md`
- `docs/SOP_MATURITY.md`
- `scripts/docs-integrity-check.mjs`

Excluded from docs commit unless separately reviewed:

- `backend/public/storage`
- `.cursor/plans/*`

Definition of done:

- One docs-only PR captures the handoff updates when Actions returns.
- `docs-integrity-check --strict` passes.
- No deployable diff is mixed into the docs PR.

---

## 8. Execution Plan After 2026-07-01

### Phase 0: Recovery

1. Confirm GitHub Actions minutes / billing reset.
2. Confirm runner health and required checks.
3. Do not rerun all workflows blindly.

### Phase 1: Clear Existing Queue

1. Refresh or merge #850 if still relevant and green.
2. Update #874 with local docs batch and run docs-only CI.
3. Investigate #914 PHPUnit failure.
4. Rebase/rerun #916/#917 Dependabot PRs.
5. Verify deploy status for #853/#854/#856 before in-app resolved updates.

### Phase 2: Product and Data Trust

1. Resolve #920 decision with director.
2. Convert #901 into a readonly report spec PR.
3. Convert #905 into a docs artifact and gap list.
4. Create role-specific UX specs for #909/#910/#911/#912.

### Phase 3: Governance and Resilience

1. Convert #888/#889 into access and PII inventory docs.
2. Convert #893 into service catalog / RACI.
3. Plan #868/#875 staging + environments.
4. Plan #881/#882 PITR / DR.

### Phase 4: UI Polish

Only after operational blockers are clear:

1. #858 readability / contrast.
2. #866 UI/UX de-AI epic.
3. #860 loading / empty / toast consistency.
4. #862 mobile density and touch targets.

---

## 9. CEO Guardrails

- User trust beats feature velocity.
- Data correctness beats UI polish.
- CI/deploy safety beats convenience.
- Handoff durability beats chat memory.
- Public user language must stay plain; technical detail belongs in internal notes or GitHub.
