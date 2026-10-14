# Codex review throttle — single activation packet

All eight repos are staged in `codex-review-fleet.json`, **disabled**. AllTrue,
sunrise-cafe/portfolio-ops have automatic-review evidence; three income/engineering
repo samples had none, exoprotocol/todo had no PR sample. None proves disabled.
Settings changed per user at **2026-10-14T12:19:58Z**; independent verification
pending. Earlier queued reviews do not refute this report. No test PR/review sent.

## Code and service boundary

Trusted-main runner `codex-review-runner.mjs` admits one configured repo/PR per run.
A separate operator activation manifest binds exact trusted main SHA, approved
scope, sender/bot numeric IDs, verified repo overrides/base, durable cooldown path
and (for canary) immutable repo/PR/head/base. Default checked-in config is disabled;
no calls/writes occur without admission. Existing read-only workflow stays read-only.
No new provider API, key/PAT, token scope, permission, scheduler or required gate.
Readonly identity probes confirmed sender 259058503 and bot 199175422; repository
role reports push. This does **not** prove token endpoint write capability.

Core requires open/non-draft, exact-head pinned required SUCCESS CI (skipped,
neutral/unknown hold), 15-minute stability or exact trusted author readiness,
complete threads, fresh refs/CI before reservation. At most initial + one explicit
consolidated-fix review across heads/base/policies. Full scope for critical risk,
changed/unknown base dependencies; delta SHAs and unresolved IDs travel in request.

Durable Contents ledger uses pre-provisioned data-only orphan `codex-review-state`
branch/`ledger.json`, data-only tree checks, blob-SHA conflicts, external observations.
Lost reservation/readback never blindly retries; historical removal fails closed.
External bot completions consume budget; uncorrelated requests/running/unknown
reviews stop requests. No synthetic base SHA is invented for historical reviews.
Comment send persists exact body/refs first, rechecks PR refs, posts once. Readback
requires authentic sender request and bot numeric ID/type, exact marker/128-bit nonce/head/base,
commit/time correlation; missing echo/eyes/edited/ambiguous output remains unknown.
Completion is never approval. Echo protocol acceptance is **not service-validated**.

Global filesystem cooldown is pre-provisioned on one existing durable executor;
missing/corrupt/unwritable/locked state stops before network. fsync/atomic rename,
reload each run, shared lock, Retry-After/reset/GraphQL limits stop entire execution.
A transient runner filesystem is insufficient; no fallback empty cooldown allowed.

## One bounded decision, after final exact-head CI and independent review

Approve exact final #4092 code scope plus: (1) verify each of the eight repos'
governance/default branch/settings/current CI and existing capabilities; (2) create
only data-only orphan state branches and durable local cooldown; (3) verify real
Contents CAS with two bounded competing writes containing only synthetic ledger
data, then reconcile budgets before any review; (4) select **one current eligible
existing AllTrue PR**, record exact head/base in the manifest, full-scope canary,
max one existing Codex review slot/no retry/no fallback PR/zero extra API fees;
(5) only after authentic readback, enable verified repos under the same bounded
scope. Do not reuse stale #3890/#4091 refs. Any changed ref aborts the chosen target.
Unknown quota units, missing capabilities, failed service echo/CAS/cooldown, active
reviews or exhausted budget stop rollout; obtain changed-scope authority only if
needed. Keep other repos disabled until their evidence is complete.

Rollback: disable executor/config and revert code through reviewed changes; keep
Automatic off, preserve ledger/cooldown (never reset budgets), no main/production
data repair. No remote state, service canary, merge/deploy/dispatch ran in this task.
Fixtures prove local logic only; real write/CAS/bot acceptance awaits this decision.
