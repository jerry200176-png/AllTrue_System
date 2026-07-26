# Parent Binding — Implementation Issues

| Field | Value |
|-------|-------|
| Status | ADR Accepted; PB-00 shipped (#1446); **PB-01–PB-09 not started** |
| Date | 2026-07-26 |
| ADR | [`ADR-PARENT-STUDENT-BINDING.md`](../../adr/ADR-PARENT-STUDENT-BINDING.md) **Accepted** |
| Design PR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| PB-00 PR | https://github.com/jerry200176-png/AllTrue_System/pull/1446 |

Global non-scope: schedule/billing/leave/RFID/learning-approval; **OTP not Phase 0–2**; no full Portal rewrite. Do not start PB-01–09 without Founder schedule.

| ID | GH | Title | Phase | Risk | Depends | Board |
|----|----|-------|-------|------|---------|-------|
| [PB-00](./PB-00-observability.md) | [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) | Observability & reason codes | 0 | T1 | — | **completed** (#1446) |
| [PB-01](./PB-01-safe-copy.md) | [#1437](https://github.com/jerry200176-png/AllTrue_System/issues/1437) | Safe copy & reason mapping | 1 | T1 | PB-00 | backlog |
| [PB-02](./PB-02-completeness-ui.md) | [#1438](https://github.com/jerry200176-png/AllTrue_System/issues/1438) | Completeness UI | 1 | T1 | PB-00 | backlog |
| [PB-03](./PB-03-inbox-cases.md) | [#1439](https://github.com/jerry200176-png/AllTrue_System/issues/1439) | Inbox binding cases | 1 | T2 | PB-00,02 | backlog |
| [PB-04](./PB-04-relationship-model.md) | [#1440](https://github.com/jerry200176-png/AllTrue_System/issues/1440) | ParentIdentity + GSR | 2 | T3 | PB-00 | blocked |
| [PB-05](./PB-05-pairing-credential.md) | [#1441](https://github.com/jerry200176-png/AllTrue_System/issues/1441) | Pairing credential | 2 | T3 | PB-04 | blocked |
| [PB-06](./PB-06-manual-approval.md) | [#1442](https://github.com/jerry200176-png/AllTrue_System/issues/1442) | BindingRequest self-serve | 2 | T2 | PB-04,03 | blocked |
| [PB-07](./PB-07-migration-backfill.md) | [#1443](https://github.com/jerry200176-png/AllTrue_System/issues/1443) | Backfill + dual-write | 2 | T3 | PB-04,05 | blocked |
| [PB-08](./PB-08-e2e-security.md) | [#1444](https://github.com/jerry200176-png/AllTrue_System/issues/1444) | E2E + security matrix | 2–3 | T2 | PB-05,06,07 | blocked |
| [PB-09](./PB-09-legacy-sunset.md) | [#1445](https://github.com/jerry200176-png/AllTrue_System/issues/1445) | Legacy sunset (KPI; no hard date) | 3 | T3 | PB-08+Founder | blocked |

Founder must-not-regress: max_uses=1; TTL 24h/72h/7d; cap 4; read_only 365d→suspended; revoke→session; self-serve BindingRequest+auth; OTP∉P0–2.
