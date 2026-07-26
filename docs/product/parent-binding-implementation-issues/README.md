# Parent Binding — Implementation Issue Breakdown

| Field | Value |
|-------|-------|
| Status | **Planning only** — do not start implementation Agents this round |
| Date | 2026-07-26 |
| ADR | [`ADR-PARENT-STUDENT-BINDING.md`](../../adr/ADR-PARENT-STUDENT-BINDING.md) |

本目錄將實作拆成可獨立 review 的 issue。每個 issue 含 scope／non-scope、AC、tests、rollback、dependency、risk class。

**全域 non-scope（所有 issue）：** 排課、扣堂、billing、leave、RFID、評量審核邏輯、OTP SMS provider、整包替換 Parent Portal。

| ID | Title | Phase | Risk | Depends on |
|----|-------|-------|------|------------|
| [PB-00](./PB-00-observability.md) | Observability & reason codes (no UX change to success) | 0 | T1 | — |
| [PB-01](./PB-01-safe-copy.md) | Safe external copy + reason mapping | 1 | T1 | PB-00 |
| [PB-02](./PB-02-completeness-ui.md) | Data completeness UI & filters | 1 | T1 | PB-00 |
| [PB-03](./PB-03-inbox-cases.md) | Action Inbox high-signal binding cases | 1 | T2 | PB-00, PB-02 |
| [PB-04](./PB-04-relationship-model.md) | ParentIdentity + GuardianStudentRelationship | 2 | T3 | PB-00 |
| [PB-05](./PB-05-pairing-credential.md) | Pairing credential lifecycle + APIs | 2 | T3 | PB-04 |
| [PB-06](./PB-06-manual-approval.md) | BindingRequest + approve/reject | 2 | T2 | PB-04, PB-03 |
| [PB-07](./PB-07-migration-backfill.md) | Legacy binding backfill & dual-write | 2 | T3 | PB-04, PB-05 |
| [PB-08](./PB-08-e2e-security.md) | E2E + security verification matrix | 2–3 | T2 | PB-05, PB-06, PB-07 |
| [PB-09](./PB-09-legacy-sunset.md) | Legacy name+phone sunset | 3 | T3 | PB-08 + Founder gate |

Risk class：T1 low-risk code · T2 product workflow · T3 safety-critical（auth/PII/relationship）。
