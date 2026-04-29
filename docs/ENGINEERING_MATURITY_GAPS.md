# Engineering Maturity Gaps

> Purpose: one-page registry of AllTrue gaps versus a larger-company engineering baseline. This file is a navigation layer, not a full SOP. The issue is the execution tracker; runbooks hold the operating procedure.

Last reviewed: 2026-04-29

## Current State

AllTrue has the essential safety rails for a solo private repo:
- Protected `main` with required checks, admin enforcement, and no force push/delete.
- PR-based deploy path with `deploy.yml` as the only production deployment workflow.
- WSL2 self-hosted runner for CI only; production deploy remains GitHub-hosted.
- DB snapshots: local nightly, sixhour, monthly, Google Drive offsite, manifest, and restore drill.
- Production Pi is deploy target only, not code source of truth.

Current blocker:
- PR #206 is merged to `main`, but production deploy is blocked by GitHub billing/spending limit. Track in #194 and #205.

## Gap Registry

| Issue | Area | Priority | Gap | Target Outcome |
|---|---|---:|---|---|
| #194 | CI / deploy | P0 | GitHub-hosted deploy runner cannot start due to billing/spending limit | Restore billing, rerun deploy, verify Pi commit + health |
| #205 | Import | P1 | Student import fix is merged but not deployed/smoke-tested | Deploy #206 and confirm Neihu import works |
| #201 | Actions / monitoring | P1 | GitHub-hosted scheduled workflows can go dark when Actions cannot start | Pi-local / external fallback for health and backup freshness |
| #202 | Backups | P1 | Backup verification is spread across logs/workflows | One read-only audit command for local/offsite/restore status |
| #207 | DB recovery | P1 | No point-in-time recovery / binlog replay SOP | Decide PITR approach and drill on non-production DB |
| #208 | Security / SSH | P1 | Maintenance workflows still need stricter SSH host key pinning | No production SSH workflow disables host checking |
| #209 | CI runner | P1 | Self-hosted runner lacks lifecycle/hardening SOP | Document update, cleanup, offline, compromise, and prerequisite checks |
| #195 | Ops / hardware | P1 | Pi health reported 100C | Verify cooling and reliability risk |
| #210 | Code backup policy | P2 | Nightly git tag idea conflicts with protected-main / Pi deploy-target policy | Remove or redesign tag/code-backup process without Pi main push |
| #204 | Static analysis | P2 | `PHPStan (php)` is required but currently advisory via `|| true` | Make it blocking or explicitly rename/document advisory behavior |
| #203 | AI docs | P2 | Always-loaded rules near size limit; duplicate SOP risk | Keep `docs/INDEX.md` as navigation and reduce rule bloat |
| #196 | AI memory | P2 | MemPalace Chroma compaction failure | Repair/rebuild local index; docs remain source of truth meanwhile |
| #197 | Attendance | P2 | Teacher sign-in diagnostic follow-up blocked by deploy/billing | Run diagnostic after deploy path recovers |
| #198 | Attendance | P2 | Zheng Yuting roster issue pending field confirmation | Confirm whether refresh fixed it or continue root-cause investigation |

## Label Policy

Use labels so future AI can filter work without reading every issue body:
- `area:*`: product or platform area (`area:ops`, `area:ci`, `area:security`, `area:db`, `area:backups`, `area:docs`, `area:attendance`, `area:import`).
- `priority:p0`: immediate blocker or production safety issue.
- `priority:p1`: high priority follow-up.
- `priority:p2`: medium priority improvement.
- `type:tech-debt`: maturity gap or technical debt.
- `status:blocked`: blocked by external dependency or prerequisite.
- `status:ready`: ready to pick up.

## Execution Order

1. Restore deploy capability: #194, then close #205 only after production smoke test.
2. Protect observability and recovery: #201, #202, #207, #208.
3. Harden CI and AI operations: #209, #204, #203, #196.
4. Resolve product follow-ups: #197, #198.

## Safety Rules

- Do not run PHPUnit, `php artisan test`, cache/config clear, or restore drills on production Pi.
- Do not move `deploy.yml` to the WSL2 self-hosted runner.
- Do not weaken branch protection to revive nightly Git tags or Pi-origin pushes.
- Do not close an issue only because a PR merged; close after deployment and acceptance criteria pass.
