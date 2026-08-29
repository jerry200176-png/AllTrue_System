# Decision Record — 2026-08-07 M6–M9 Tech-Debt Batch

**Owner:** Founder / CTO Agent
**Status:** Decided (Phase 1 = documentation/template, executable now; Phase 2 = requires Pi/infra or GitHub-UI access, tracked separately)
**Method:** Each linked issue already proposed a 大廠對標 (big-company reference). This record **adopts** that reference as the decision, scopes it into a Phase 1 deliverable this session can actually produce (docs, checklists, templates, decision text), and a Phase 2 deliverable that needs a human with Pi SSH / GitHub Settings UI / paid-plan access to execute. No issue here is closed — a decision is not the same as the drill/rollout actually having happened.

---

## How to read this record

For every issue: **Decision** (adopt / adopt-phased / defer-with-reason) → **Reference** (the big-company practice being copied) → **Phase 1** (shippable today, doc/config only) → **Phase 2** (needs infra access or a paid GitHub feature; stays open, owner = Founder).

---

## M6 — GitHub 治理與協作成熟度

### #880 Repository ruleset / branch protection baseline
**Decision:** Adopt — migrate from ad-hoc `gh api` branch protection to versioned **Repository Rulesets** (GitHub's policy-as-code layer), matching how GitHub itself and most Rulesets-era orgs manage protection.
**Reference:** Policy-as-code repo governance; protected tags for releases.
**Phase 1 (this doc):** Baseline to encode once Rulesets are enabled — required checks (all current CI jobs), no force-push, no branch deletion, required conversation resolution, and a **new** tag-protection rule (`v*` pattern) preventing release-tag deletion/overwrite, since none exists today.
**Phase 2 (needs GitHub Settings UI, Founder):** Create the Ruleset from this baseline; add a monthly calendar reminder to export/diff it (`gh api repos/:owner/:repo/rulesets`) against this doc to catch drift.

### #876 CODEOWNERS / review ownership
**Decision:** Adopt-phased. Google/Meta-style ownership matrices assume >1 reviewer; this is a solo-maintainer repo, so **required-approvals stays at 0** (per the repo's own Founder Decision 3 — "no universal rubber-stamp bottleneck"), and CODEOWNERS remains a **routing/reminder** signal, not a hard gate.
**Reference:** GitHub CODEOWNERS + two-person review for high-risk paths (deferred until there's a second maintainer).
**Phase 1 (this doc):** Ownership matrix below — the trigger to flip to `required_approving_review_count: 1` is **hiring/adding a second maintainer**, not a calendar date.

| High-risk path | Owner | Solo-mode substitute for a 2nd reviewer |
|---|---|---|
| `backend/app/Http/Controllers/*Billing*`, `AlertController.php` | Founder | Bugbot/Cursor AI review + PR Threat Note + required `High-Risk Test Gate` check |
| `.github/workflows/*.yml`, `deploy.yml` | Founder | `Agent Session Provenance` + `Presubmit Checks` required checks |
| `backend/database/migrations/*` | Founder | Migration Compatibility section in PR template + expand/contract review |
| `docs/governance/*` | Founder | Self-review checklist (already in PR template) |

**Phase 2:** When a second maintainer joins, flip required-approvals to 1 for the paths above via the Ruleset from #880.

### #875 GitHub Environments (production/staging secrets)
**Decision:** Adopt-phased.
**Reference:** GitHub/GitLab Environments, secrets least-privilege.
**Phase 1 (this doc):** Environment plan — `production` environment bound to `main`, holding the existing Pi SSH/DB secrets; `staging` environment created empty now, populated when #868 (staging host) exists. No manual-approval gate yet (solo repo), but the environment boundary itself is worth having immediately for deployment-history visibility.
**Phase 2 (needs GitHub Settings UI, Founder):** Create both Environments, move `deploy.yml` secrets from repo-level to `production` environment-level, update the workflow's `environment:` key.

### #878 Release / Deploy / in-app bug traceability
**Decision:** Adopt.
**Reference:** Change management / release-train traceability.
**Phase 1 (this doc):** Trace chain, made explicit — `in-app bug # → GitHub issue # → PR # → merge commit SHA → deploy.yml run ID → deploy-meta.json backend_sha (from #1496) → version.json frontend_sha`. Rule: an in-app bug is **not** marked `resolved` in-app until its `deploy_run_id` shows `success` **and** `/health/detailed` reflects the merged SHA — this closes the exact #165/#166 gap ("merged but not deployed" mislabeled resolved).
**Phase 2:** Wire this rule into `docs/CHAT_BUG_SYSTEM.md` §3.7 as a hard gate (small doc PR, can be done in a follow-up).

---

## M7 — SRE / Operations 成熟度

### #884 Observability consolidation
**Decision:** Adopt-phased, lightweight-first (explicitly rejecting a full ELK/Datadog stack for a single-Pi shop).
**Reference:** Google SRE observability; Sentry release health; alert-fatigue control.
**Phase 1 (this doc):** Weekly ops digest spec — one Markdown/Slack-style report combining: `/health/detailed` snapshot, Sentry error count by release, nginx/Laravel 5xx count, `slow-query` report count, last backup-restore-drill result, last deploy SHA. Alert-noise rule: **an alert without a named owner and a numeric threshold is not an alert, it's noise** — every existing UptimeRobot/Telegram alert must be re-labeled with owner + threshold or removed.
**Phase 2 (needs Pi access):** Wire the weekly digest as a scheduled job (cron or GitHub Actions `schedule:`) that posts to Telegram, matching the existing alert channel.

### #885 Capacity management
**Decision:** Adopt.
**Reference:** SRE capacity/resource-budget planning; FinOps.
**Phase 1 (this doc):** Metrics + thresholds table:

| Metric | Warn | Critical | Action at Critical |
|---|---|---|---|
| Disk usage | 70% | 85% | Rotate/prune backups, evaluate SSD upgrade |
| DB size growth (month-over-month) | +15% | +30% | Investigate table bloat, plan archival |
| Backup age | >26h | >50h | Page on-call (Founder), check `sessions:audit-stranded`-style cron health |
| CPU sustained (5 min avg) | 70% | 90% | Check for runaway query, consider external runner |

**Phase 2 (needs Pi access):** Add these thresholds to the existing pi-health script; feed into the #884 weekly digest.

### #881 MySQL PITR / binlog recovery
**Decision:** Adopt-phased, **drill-DB only** — explicitly reaffirming R2/R6 (never run restore drills against production `AllTrue`).
**Reference:** RDS PITR / MySQL binlog restore / SRE data-recovery drills.
**Phase 1 (this doc):** Target RPO — binlog retention 5 days (balances disk cost vs. recovery granularity); drill runbook skeleton: `mysqlbinlog --start-datetime → apply to restored nightly snapshot on drill DB → verify row counts against known checkpoint → record actual RPO achieved`.
**Phase 2 (needs Pi + drill DB access):** Enable binlog retention, execute one real drill, record actual RPO/RTO in `docs/OPERATIONS_RUNBOOK.md`.

### #882 Full server DR tabletop
**Decision:** Adopt, twice-yearly cadence (matches the repo's existing "restore drill monthly, full DR twice yearly" tiering pattern).
**Reference:** DR tabletop exercise; AWS Well-Architected Operational Excellence.
**Phase 1 (this doc):** Checklist skeleton — OS packages → repo checkout (deploy key) → `.env`/secrets restore → MySQL restore from latest backup → composer/npm install → nginx/PHP-FPM config → cron re-registration → UptimeRobot/Telegram re-point → `/health` + smoke test green. Each step gets a timestamp when actually drilled, to compute real RTO.
**Phase 2 (needs a spare Pi/SD card, Founder, twice a year):** Execute once, record RTO, file gaps as new issues.

### #899 RFID / device inventory
**Decision:** Adopt.
**Reference:** IT asset inventory; endpoint ownership.
**Phase 1 (this doc):** Inventory schema — `device_id, campus, room, purpose, owner, last_verified_date, backup_plan`. Monthly test = swipe-in on each reader, confirm `SwipeRfidController` logs a row within 5s.
**Phase 2 (needs on-site access per campus, Founder or campus director):** Populate the inventory rows, run first monthly test.

---

## M8 — Security, Privacy & Compliance

### #889 PII data inventory / classification / retention
**Decision:** Adopt — highest priority in this batch given the prior SQL-dump-in-git incident (#1124).
**Reference:** GDPR/PDPA data inventory; data minimization; retention policy.
**Phase 1 (this doc):** Inventory table (starter, to be completed against the real schema):

| Field | Table | Classification | Access roles | Retention |
|---|---|---|---|---|
| Student name/phone | `Student` | PII — direct identifier | admin, director, teacher (scoped) | Active + 3y post-withdrawal (align with tax/contract law) |
| RFID card ID | `Student`/`RfidCard` | PII — access credential | admin, director | Same as student record |
| LINE user ID | `ParentIdentity` | PII — 3rd-party linkage | admin, director | Until unlink + 30d grace |
| Attendance/session records | `ClassSession`, `LearningRecord` | PII — behavioral | admin, director, teacher (own class) | 3y (billing dispute window) |
| Payment/invoice records | `PaymentReport`, `Invoice` | PII + financial | admin, director | 7y (statutory bookkeeping) |

**Rule adopted immediately (no infra needed):** production DB dumps are **never** committed to git, and any local dump used for debugging must be deleted within 24h — this formalizes what #1124 already forced as an incident response.
**Phase 2 (needs full schema walkthrough, Founder + AI session):** Complete the inventory for every table with PII, publish as `docs/security/PII_DATA_INVENTORY.md`.

### #888 IAM / access review
**Decision:** Adopt, quarterly cadence.
**Reference:** SOC2 access review; least privilege; joiner/mover/leaver.
**Phase 1 (this doc):** Review scope checklist — App roles (`super_admin`/`director`/`teacher`), `UserCampus` bindings, GitHub collaborators + PATs/Actions secrets, Pi SSH `authorized_keys`, LINE/Sentry/UptimeRobot account access. Each gets an owner + "last confirmed" date.
**Phase 2 (needs Founder to actually pull each system's user list, quarterly):** First review pass; remove anything without a named owner.

### #887 Production host hardening verification
**Decision:** Adopt, quarterly cadence.
**Reference:** CIS Benchmark; NIST CSF Protect.
**Phase 1 (this doc):** Checklist — `ufw status` (only 22/80/443 open), `sshd_config` (`PasswordAuthentication no`, key-only), `fail2ban-client status sshd` (active, ban count sane), `systemctl list-units --type=service` diffed against a known-good baseline, Nginx security headers (`X-Frame-Options`, `X-Content-Type-Options`, CSP) present on responses.
**Phase 2 (needs Pi SSH, Founder, quarterly):** Run the checklist, log results + any remediation issues.

### #890 Sensitive action audit log coverage
**Decision:** Adopt.
**Reference:** SOX/SOC2 audit trail.
**Phase 1 (this doc):** Coverage matrix (starter):

| Sensitive action | Audited today? | Gap |
|---|---|---|
| Schedule/session edits | ✅ (`schedule audit logs`) | — |
| Payment confirm/void | Partial (`PaymentReport` has status but no actor/reason log) | Add actor + reason column |
| Role/permission change | ❌ | No audit table for `super_admin` grants |
| PIN reset | ❌ | No audit log |
| Bug status transitions | ✅ (in-app bug SOP) | — |
| PII export (`/export` endpoints) | ❌ | No log of who exported what |

**Phase 2 (implementation, needs code + tests — track as a real PR, not a doc-only task):** Prioritize role/permission-change and PII-export audit logging first (highest blast radius), open focused follow-up issues per gap.

### #903 Privacy request SOP
**Decision:** Adopt, depends on #889 (data map) for full coverage but can start now.
**Reference:** GDPR/PDPA data-subject request handling.
**Phase 1 (this doc):** SOP skeleton — identity verification (director confirms requester relationship to student) → scope (which of the #889 categories) → response SLA (30 days, matching GDPR/PDPA norms) → deletable vs. must-retain (financial/attendance records retained per #889's statutory retention) → alternative: archive/anonymize instead of hard-delete when retention required.
**Phase 2:** Publish as `docs/PRIVACY_REQUEST_SOP.md` once #889's full inventory exists (needed to know what's deletable).

### #879 Security Advisory / secret rotation drill
**Decision:** Adopt.
**Reference:** GitHub Security Advisories; SLSA supply-chain hygiene; quarterly secret rotation.
**Phase 1 (this doc):** Drill checklist — rotate Pi SSH deploy key, GitHub Actions secrets (DB password, Telegram/LINE tokens), Sentry DSN if exposed; verify old credentials actually rejected post-rotation, not just replaced. SLA: P0 security alert = same-day triage, P1 = 3 days, P2 = 2 weeks (aligned with the repo's existing priority label semantics).
**Phase 2 (needs GitHub Settings UI to confirm private vulnerability reporting is enabled, Founder):** Confirm setting, run first rotation drill.

---

## M9 — Operating Model & Workflow

### #895 In-app bug / support SLA metrics
**Decision:** Adopt.
**Reference:** Support SLA (Zendesk/JSM-style); bug-aging dashboard.
**Phase 1 (this doc):** Metric definitions — Triage time (report → `triaged`), Time to resolution (`triaged` → `resolved`), Reopen rate (`resolved` → reopened within 14d), Backlog age (days since `triaged` for anything not yet `resolved`). SLA target: P0 triage <4h, P1 <24h, P2 <1 week (matches issue priority labels already in use).
**Phase 2 (needs a query against the in-app bug tracker, Founder or AI session with DB access):** Produce the first backlog-aging report.

### #894 SOP / runbook review cadence
**Decision:** Adopt.
**Reference:** Runbook lifecycle management; docs governance.
**Phase 1 (this doc):** SOP inventory columns — `doc, owner, review_cycle (monthly/quarterly/yearly), last_reviewed, next_review, last_drill_evidence_link`. Seed rows for the docs already carrying dates: `docs/OPERATIONS_RUNBOOK.md`, `docs/SECURITY.md`, `docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md`.
**Phase 2 (needs a scan across all `docs/*.md` frontmatter/dates, can be scripted):** Generate the full inventory, flag anything >1 review-cycle overdue.

### #904 Technical health scorecard
**Decision:** Adopt.
**Reference:** Engineering health dashboard; quality scorecard.
**Phase 1 (this doc):** Scorecard metrics — CI failure rate (last 30 runs), PHPStan baseline delta (growing/shrinking), open P1/P2 tech-debt count (this repo has ~50 — a real number worth tracking monthly), bug recurrence families (per `docs/AI_REGRESSION_LESSONS.md`), hot files (files touched by >5 bug-fix commits in 90 days).
**Phase 2 (needs a script against GitHub Actions API + git log, can be automated in a follow-up PR):** Produce the first scorecard, use it to pick next month's 1–2 tech-debt payoffs.

---

## What did NOT get a Phase-1-only treatment here

Billing/attendance correctness bugs (#934, #920, #959, #1096, #1151, #1152, #1130, #1134, #1131), the P0 security incident (#1401) and its epic (#1437–#1445), and the large architecture/UX epics (#957, #966, #1042, #1043, #1047, #1080, #1147, #1600, #1621, #1618, #1319, #1382, #1408) are **not** "adopt a big-company doc pattern" decisions — they require either real code fixes against live billing/security logic, or a scoped Phase-1 vertical slice of a multi-week epic. Those are tracked and decided separately (see companion PRs/issue comments), not folded into this docs-only batch.
