# Severity Lookup Table

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**  
> Lookup table: STATE + signal → severity label. Escalation: INCIDENT stack (contract I3).

---

## MemPalace scope (read first)

**MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.**

| | MemPalace | AllTrue production |
|---|-----------|-------------------|
| In SLO | **No** | Yes |
| In alerting / UptimeRobot | **No** | Yes |
| Incident detection | **No** | Yes |
| Infer production health | **No** | Yes |
| Operator response | Best-effort P2 dev tooling | P0/P1 immediate |

MemPalace issues use **MP-*** IDs below — never page CEO for MemPalace alone.

---

## Scale

| Level | Meaning | Response | Escalation |
|-------|---------|----------|------------|
| **P0** | Production app impaired or data at risk | Immediate; rollback at T+15 if unstable | CEO LINE |
| **P1** | Degraded, workaround exists, or fixes blocked | Same day | CEO LINE if unresolved 4h |
| **P2** | Limited impact; dev tooling; best-effort | Schedule | Log issue — no page |

**Escalation (solo operator):** P0/P1 production → CEO LINE only. No other routing.

---

## Product incidents (Pi production)

| ID | Symptom | Sev | Action |
|----|---------|-----|--------|
| PROD-01 | Health not ok / widespread 5xx | P0 | INCIDENT Step 3 deploy rollback |
| PROD-02 | RFID / login / today schedule broken | P0 | Same |
| PROD-03 | Active bad data writes (billing/sessions) | P0 | INCIDENT Step 3 DB path |
| PROD-04 | Single page broken, health OK | P2 | fix/* PR |
| PROD-05 | In-app bug, site up | P2 | CHAT_BUG_SYSTEM.md |

---

## CI incidents

| ID | Symptom | Sev | Action |
|----|---------|-----|--------|
| CI-01 | Required checks fail, production OK | P1 | INCIDENT Step 3 CI path |
| CI-02 | Required checks fail, production down | P0 | Rollback + CI fix in parallel |
| CI-03 | Self-hosted runner offline | P1 | OPERATIONS_RUNBOOK §B4 |
| CI-04 | deploy.yml cannot run (SSH/secrets) | P0 | OPERATIONS_RUNBOOK §I, §S |
| CI-05 | Actions minutes exhausted | P1 | §B2-12 documented bypass only |

---

## Infrastructure incidents

| ID | Symptom | Sev | Action |
|----|---------|-----|--------|
| INFRA-01 | Pi disk >90% | P0 | pi-health / §Z |
| INFRA-02 | Backup stale >24h | P1 | §P |
| INFRA-03 | SSL expiry | P1 | .cursorrules IT |
| INFRA-04 | Google Drive sync failed | P1 | §P |

---

## Security alert incidents (Dependabot / advisories / secret scanning) — #879

| ID | Symptom | Sev | Action |
|----|---------|-----|--------|
| SEC-01 | Secret leak confirmed (gitleaks / manual discovery) | P0 | `OPERATIONS_RUNBOOK.md` §O「外洩應急」— rotate same day, no waiting for schedule |
| SEC-02 | Dependabot/`osv-scanner` HIGH+ advisory, package reachable | P1 | Patch same week; if no fix available, document reachability + mitigation in the PR/issue |
| SEC-03 | Dependabot/`osv-scanner` HIGH+ advisory, package unreachable (dead code path) | P2 | `composer.json`/`package.json` `audit.ignore` with reachability review note (see TD-075 precedent) — not urgent, but must be tracked, not silently dismissed |
| SEC-04 | Routine 90-day secret rotation reminder (automated, see §O) | P2 | Rotate on next convenient maintenance window, not same-day |
| SEC-05 | GitHub Security Advisory filed against this repo (private vulnerability report received) | P0 | Immediate triage — private reporting is enabled (#879); treat as PROD-equivalent until scoped |

---

## MemPalace incidents (best-effort — NOT production)

Local WSL2 only. **Do not use for production health inference.**

| ID | Symptom | Sev | Action |
|----|---------|-----|--------|
| MP-01 | CLI missing | P2 | Install mempalace |
| MP-02 | Ingest failed | P2 | `--replay` → `--resume` |
| MP-03 | Lock skip | P2 | Wait; retry |
| MP-04 | Chroma / disk on WSL2 | P2 | `--repair` |
| MP-05 | Search stale | P2 | mempalace-maintain.sh |
| MP-06 | Monthly reminder missed | P2 | Run when convenient |

---

## Decision shortcuts

```
Tutoring app users affected?     → PROD / CI / INFRA → INCIDENT_START_HERE
Only AI search quality bad?      → MP-* P2 → MEMPALACE_OPERATIONS_HANDBOOK
At T+15 unstable or unknown?     → Mandatory rollback (INCIDENT STOP rule)
```

---

*Severity applies to production SLI/error budget per SRE_POLICY. MemPalace never consumes error budget.*
