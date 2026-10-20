# pop-mount shared production lock — queue: max

**silent_ship:** yes — CI/deploy contract only; no product UI.

## Why

`#4306`/`#4311` put `pop-mount-reviewed-financial-once.yml` on `alltrue-production-side-effects-v2` with `cancel-in-progress: false` but omitted `queue: max`. Deploy's Merge/activation contract (`test_every_shared_production_lock_queues_instead_of_replacing_pending_runs`) then fails on tip, so Deploy to Pi cannot reach DtP / release-train start.

## Change

Add `queue: max` under that workflow's concurrency block (same pattern as other POP / deploy side-effect workflows).
