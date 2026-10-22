## 2026-10-21 — chore(ops): in-app replies run as batches without per-run Founder approval
<!-- release-notes: silent_ship=silent-2026-10-21-inapp-reply-batch-no-founder-gate -->
<!-- silent-reason: Internal ops/control-plane change to the in-app write-back workflows and governance records; no staff-facing product change. -->
- `bug-phase-a-triage.yml` and `bug-followup-comment.yml` take a `rows_json` batch (1–20 rows, validated all-or-nothing by `.github/scripts/inapp-writeback-rows.py`, replies ≤600 characters) and no longer wait for a per-run `production-activation` approval (Founder 2026-10-21). Still dispatch-only, main-only, `production-ssh` secrets, shared write lock, ID/hash-only preview.
- Record the Founder 2026-10-21 decisions (in-app reply gate removal; R0–R3 merge conditions and batched R3 activation) in `FOUNDER_STANDING_DECISIONS.md`; align CLAUDE.md G-012 and the bug SOP.
