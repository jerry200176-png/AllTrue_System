# POP Control Plane SLO (draft)

> **Authority:** [ADR-POP-011](adr/ADR-POP-011-meta-controller.md)  
> **Phase:** Metrics implementation Phase 2+

| Service | SLO (30d) | Error budget |
|---------|-----------|--------------|
| Approval API availability | 99.9% | ~43 min |
| Execution Plane completion | 99.5% | ~3.6 hr |
| Operations within SLA | 95% | 5% over catalog `estimated_duration×2` |
| Verification delay P95 | <120s | — |
| Queue delay P95 (scheduled→claimed) | <60s | — |
| Controller lag P95 (drift→plan) | <300s | — |
| CP recovery P95 (degraded→healthy) | <15min | — |

Budget exhaustion policy: freeze non-P0 scheduling; postmortem required to restore full throughput.
