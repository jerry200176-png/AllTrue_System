## 2026-10-23 — ci(deploy): Founder can set POP_INAPP_STANDING_POLICY at deploy time (ADR-POP-015)
<!-- release-notes: silent_ship=silent-2026-10-23-deploy-pop-inapp-standing-policy-flag -->
<!-- silent-reason: 只改上線流程（deploy.yml 與 Pi 部署腳本）的輸入；預設 unchanged，不改 production 現值，主任、老師與家長的畫面和功能都不變 -->
- `deploy.yml` adds the `workflow_dispatch` input `pop_inapp_standing_policy` (`unchanged` default, `on`, `off`), wired exactly like `admissions_funnel_v1`, `course_session_calendar_v1` and `course_manager_v1`: refused on a release train, counted as an explicit flag activation on an already-current tip, and passed to the Pi script as `DEPLOY_POP_INAPP_STANDING_POLICY`.
- On the Pi, `on` writes `POP_INAPP_STANDING_POLICY=true` and `off` writes `false` to `/home/admin/backend/.env` before `php artisan optimize` rebuilds the config cache; `unchanged` (and every automatic deploy, which has no input) leaves the file alone. The flag is backend only: nothing is written for Vite and it does not force a frontend rebuild.
- A failed deploy that rolls back restores the previous line, or removes the key if it was absent, before the rollback rebuilds the config cache. ADR-POP-015 still requires the Founder to turn it on only after the dry-run phase.
- Shared fixes for all four flags (independent review of the first head):
  - A manual `application-deploy` with any flag `on`/`off` now always reaches the Deploy step. Before, a lag with no application files ended as a green no-op without touching `.env`, so `off` failed open.
  - Only empty/`unchanged`/`on`/`off` are accepted. `resolve-target` fails first, and the renderer refuses anything else before it pastes a value into the Pi script.
  - Requested changes appear as a run annotation and in the Release state summary. `run-name` is unchanged, because calendar-course acceptance matches the literal deploy run names (#4207).
  - Rollback restores each flag line literally (no regex, so `/`, `&` and `\1` are safe) and checks it with `grep -Fx`; a failed check is reported and fails the rollback. The restore runs first, so it also happens when the code rollback stops early (git reset or composer), and on the migration hold (exit 13).
  - An explicit POP mode is read back from the cached config after `optimize`; a mismatch aborts the deploy.
  - Kill switch: for `POP_INAPP_STANDING_POLICY` only, a requested `off` is never rolled back. Every failure path keeps it at `false`, even if the deploy failed before writing it. A requested `on`, and the other flags, still restore all-or-nothing.
  - The migration hold (exit 13) rebuilds the config cache with the same `php artisan optimize` step as `[5/7]` after restoring the flag lines, so the cached config matches `.env`. A failed rebuild is reported as an error.
