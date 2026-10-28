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

Review fixes (2026-10-28, Founder「能不能不要一直讓我按approve 實現自動化」→「做」): a capped
compare range, a rename out of a protected path, a control-plane file inside an application PR,
`backend/routes/api.php`, or a Rollback line that names no reversal all keep the Founder gate;
freeze and final-head review are re-checked after the executor slot is held; the last real
deploy is searched past skipped runs. The self-declared `Feature-Flag:` gate (gap #9) is dropped:
it could not be machine-verified and would have kept most R3 fixes on a manual click (Founder 2026-10-28:
「功能開關那條拿掉可以，繼續」).
