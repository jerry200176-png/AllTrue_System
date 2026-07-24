# Outbound readiness gate — human-recipient workflows

**Normative.** Workflows that need a **human recipient** MUST pass this gate before activation is claimed.

| # | Check | Pass |
|---|--------|------|
| 1 | Recipient configured | Concrete channel id per campus/role (not silent skip) |
| 2 | Channel health | Probe/API health OK |
| 3 | Test delivery | Non-PII test receipt logged |
| 4 | Acknowledgment path | `delivered_at` + `acknowledged_at` backfill without GitHub PII |
| 5 | PII policy | No names/CSV in git, Issue, PR, public logs |

**Forbidden:** artifact generated ≠ delivery; `skipped_no_line` ≠ delivered; merge alone ≠ activation.

```bash
python3 scripts/outbound-readiness-gate.py --tracker operations/closeout/leave-hc-campus-review-tracker.json --mode schema
python3 scripts/outbound-readiness-gate.py --tracker operations/closeout/leave-hc-campus-review-tracker.json --mode activation
python3 scripts/outbound-readiness-gate.py --tracker operations/closeout/leave-hc-campus-review-tracker.json --mode delivery_complete
```

Exit 0 = pass; 2 = blocked. Lesson 2026-07-19/#1342: staff_line_group unset → all skipped; Engineering PASS, Operational Delivery BLOCKED. Manual DM handoff does not greenlight automated activation.
