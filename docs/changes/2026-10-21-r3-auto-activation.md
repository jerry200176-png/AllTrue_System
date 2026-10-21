## 2026-10-21 — ci(deploy): R3 application ranges activate without a Founder click, behind automated controls
<!-- release-notes: silent_ship=silent-2026-10-21-r3-auto-activation -->
<!-- silent-reason: deploy control-plane change; no product UI change -->

Founder 2026-10-21 (`FOUNDER_STANDING_DECISIONS.md` 2026-10-21（二）之二): a deploy
range held only because it contains R3 PRs now activates through `production-auto`
(`mode=auto-r3`) when `autonomy_gate.decide_r3_auto_activation` accepts it under both
the deployed and the target policy: complete provenance, valid declarations, rollback
evidence, no migration / data-repair / identity-credential / control-plane effect, no
gate-policy change in range, Codex no-findings confirmation on every PR's final head
with zero open review threads, and no deploy freeze (main CI red or the last production
deploy failed). Exact-main CI, health/identity/smoke verification and the automatic
rollback are unchanged. Anything else still waits for the Founder on the release train.

Gap #9: each PR in an `auto-r3` range must also ship dark. Its body declares exactly one
`Feature-Flag: <perfflags key> (default off)` (the key is `(bool) env('X', false)` in the
target's `backend/config/perfflags.php` and the PR's added lines use it) or
`Feature-Flag: none — <reason>` (refused when the PR changes `frontend/src/`). A missing or
invalid declaration falls back to the Founder click.
