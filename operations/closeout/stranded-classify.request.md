# Request: stranded #1062 classify refresh (read-only)

Trigger: `ops-stranded-classify-refresh.yml`  
Purpose: refresh stranded cohort + **24h/72h producer proxies** + **exposure_ntd split** + **#1130 unit breakdown** via `scripts/ops/stranded-classify-probe.php`.  
**No execute / no bulk repair.** CEO GO required before any forward-gen execute.

# probe-deepen 2026-07-22T03:30:00Z

# note: probe script lands this PR; workflow scp wiring is follow-up (see operations/closeout/patches/README-workflow-followup.md)
