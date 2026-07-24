# Workflow follow-up (post-CI)

This PR intentionally omits `.github/workflows/**` edits so Actions can schedule.

After merge, apply (or open follow-up PR):

1. `ops-leave-hc-review-tracker.yml` — ack_by / `deadline_at_risk` comment columns + `readiness-gate` job calling `scripts/outbound-readiness-gate.py`.
2. `ops-stranded-classify-refresh.yml` — checkout + scp `scripts/ops/stranded-classify-probe.php`, parse `PROBE_JSON` (exposure / producer / #1130 units).

Until then: run probe manually on Pi after scp; run `python3 scripts/leave-hc-tracker-sla.py` for ack SLA.
