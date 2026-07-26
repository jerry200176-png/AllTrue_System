# Parent Binding — Implementation Issue Breakdown

| Field | Value |
|-------|-------|
| Status | **Planning only** — do not start implementation Agents from this closeout |
| Date | 2026-07-26 |
| ADR | [`ADR-PARENT-STUDENT-BINDING.md`](../../adr/ADR-PARENT-STUDENT-BINDING.md) — **Accepted** (Founder 2026-07-26) |
| PR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |

本目錄將實作拆成可獨立 review 的 issue。每個 issue 含 scope／non-scope、AC、tests、rollback、dependency、risk class。

**全域 non-scope（所有 issue）：** 排課、扣堂、billing、leave、RFID、評量審核邏輯、**OTP SMS（Phase 0–2 禁止 dependency）**、整包替換 Parent Portal。

| ID | GitHub | Title | Phase | Risk | Depends on | Board |
|----|--------|-------|-------|------|------------|-------|
| [PB-00](./PB-00-observability.md) | [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) | Observability & reason codes | 0 | T1 | — | **ready / next** |
| [PB-01](./PB-01-safe-copy.md) | [#1437](https://github.com/jerry200176-png/AllTrue_System/issues/1437) | Safe external copy & reason mapping | 1 | T1 | PB-00 | backlog |
| [PB-02](./PB-02-completeness-ui.md) | [#1438](https://github.com/jerry200176-png/AllTrue_System/issues/1438) | Data completeness UI & filters | 1 | T1 | PB-00 | backlog |
| [PB-03](./PB-03-inbox-cases.md) | [#1439](https://github.com/jerry200176-png/AllTrue_System/issues/1439) | Action Inbox high-signal binding cases | 1 | T2 | PB-00, PB-02 | backlog |
| [PB-04](./PB-04-relationship-model.md) | [#1440](https://github.com/jerry200176-png/AllTrue_System/issues/1440) | ParentIdentity + GuardianStudentRelationship + lifecycle | 2 | T3 | PB-00 | blocked |
| [PB-05](./PB-05-pairing-credential.md) | [#1441](https://github.com/jerry200176-png/AllTrue_System/issues/1441) | Pairing credential (max_uses=1, TTL, active cap) | 2 | T3 | PB-04 | blocked |
| [PB-06](./PB-06-manual-approval.md) | [#1442](https://github.com/jerry200176-png/AllTrue_System/issues/1442) | BindingRequest self-serve + approve/reject | 2 | T2 | PB-04, PB-03 | blocked |
| [PB-07](./PB-07-migration-backfill.md) | [#1443](https://github.com/jerry200176-png/AllTrue_System/issues/1443) | Legacy backfill & dual-write + session invalidate | 2 | T3 | PB-04, PB-05 | blocked |
| [PB-08](./PB-08-e2e-security.md) | [#1444](https://github.com/jerry200176-png/AllTrue_System/issues/1444) | E2E + security verification matrix | 2–3 | T2 | PB-05, PB-06, PB-07 | blocked |
| [PB-09](./PB-09-legacy-sunset.md) | [#1445](https://github.com/jerry200176-png/AllTrue_System/issues/1445) | Legacy sunset (KPI gate, no hard date) | 3 | T3 | PB-08 + Founder | blocked |

Risk class：T1 low-risk code · T2 product workflow · T3 safety-critical（auth/PII/relationship）。

Founder parameters（must not regress）：`max_uses=1`；TTL 24h/72h/7d；active cap 4；read-only 365d then suspended；revoke invalidates ParentSession；self-serve BindingRequest with auth；OTP not in Phase 0–2。
