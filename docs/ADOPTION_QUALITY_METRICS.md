# Adoption & Quality Metrics Dictionary

> Scope: issue #462 (KPI cockpit) + #460 (trust layer) v1  
> Owner: Product + Engineering  
> Cadence: weekly review (Mon), with 2-week post-release baseline observation

---

## 1) Staff adoption metrics

| Metric | Formula | Source | Notes |
|---|---|---|---|
| `teacher_open_rate_pct` | distinct teacher users with successful login in 7-day window / total teacher users in campus × 100 | `user_login_activities`, `UserCampus`, `User` | Login success only |
| `director_open_rate_pct` | distinct director/admin users with successful login in 7-day window / total director/admin users in campus × 100 | `user_login_activities`, `UserCampus`, `User` | Campus-scoped |
| `teacher_activation_rate_pct` | distinct teacher users with at least one core action in 7 days / total teacher users × 100 | `LearningRecord`, `StudentSingIn` | Core actions: learning record input or attendance sign-in |
| `director_activation_rate_pct` | distinct director/admin users with at least one core action in 7 days / total director/admin users × 100 | `LearningRecord.ApprovedBy`, `bug_report_status_logs.changed_by` | Core actions: approval or defect workflow action |
| `activation_funnel.teacher.activation_within_24h_pct` | teacher activated users / teacher opened users × 100 (v1 proxy) | `user_login_activities`, activation aggregates | v1 uses 7-day open/activation proxy for same-day completion trend |
| `activation_funnel.director.activation_within_24h_pct` | director activated users / director opened users × 100 (v1 proxy) | `user_login_activities`, activation aggregates | used by mission-center adoption review |
| `system_completion_rate_pct` | `learning_records_filled / attended_sessions × 100` | `LearningRecord`, `ClassSession` | Guard zero denominator |

---

## 2) Parent engagement metrics

| Metric | Formula | Source | Notes |
|---|---|---|---|
| `parent_feedback_reply_rate_pct` | approved learning records with parent feedback / approved learning records × 100 (7 days) | `LearningRecord`, `learning_record_feedbacks` | Campus-scoped via `Student.CampusID` |
| `parent_feedback_unread_backlog` | feedback rows where `last_read_by_director_at` is null or `< updated_at` | `learning_record_feedbacks` | Real-time backlog |

---

## 3) Quality & trust metrics

| Metric | Formula | Source | Notes |
|---|---|---|---|
| `bug_reopen_rate_pct` | count(`resolved -> in_progress`) / count(`to_status = resolved`) × 100 (7 days) | `bug_report_status_logs`, `bug_reports` | Campus-scoped |
| `p1p0_median_lead_hours` | median of `resolved_at - bug_created_at` hours for severity `high/critical` in window | `bug_reports`, `bug_report_status_logs` | Uses resolved status log timestamp |
| `trust_contract_backlog` | `workflow_daily.due_total` | adoption workflow aggregations | Represents unresolved cross-workflow pending items |
| `trust_contract_breached_total` | `workflow_daily.breached_total` | adoption workflow aggregations | SLA warning signal |

---

## 4) Super-admin cross-branch comparison

- Endpoint: `GET /api/v1/adoption/cross-branch-metrics`
- Access: `super_admin` only
- Output: per-branch row + aggregate averages (`meta.summary`)
- Intended use: weekly leadership review (not for transactional decisions)

---

## 5) Trust panel payload contract

- Staff endpoint: `GET /api/v1/system/trust-summary?branch_id={id}`
- Parent endpoint: `GET /api/v1/parent/system-trust-summary`
- Payload blocks:
  - `recent_improvements`: derived from `docs/CHANGELOG.md` recent items
  - `known_issues`: curated allowlist from `backend/config/system_trust.php`
  - `reliability_snapshot`: open defects + pending workflow counters

---

## 6) Governance & security constraints

- Always enforce campus isolation for non-`super_admin` requests.
- `known_issues` must stay sanitized: no names, tokens, stack traces, internal paths.
- Parent-facing trust summary is read-only and uses parent session token validation.
- New metrics must define denominator fallback behavior (0-denominator = 0.0).

---

## 7) Monitoring checklist (post-release, 2 weeks)

1. Confirm dashboard loads with no 4xx/5xx for director/teacher/super_admin/parent.
2. Verify branch comparison data appears only for `super_admin`.
3. Spot-check metric sanity against DB counts for one branch per week.
4. Confirm `known_issues` content stays sanitized before each release.
5. Review trends weekly; only change formulas via documented ADR/plan update.
