# PB-00 — Observability & reason codes

| Field | Value |
|-------|-------|
| Phase / Risk | 0 / T1 |
| Board | **completed** · [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) · PR [#1446](https://github.com/jerry200176-png/AllTrue_System/pull/1446) |
| Blocks | PB-01,02,03 |
| Status | Merged to `main` — observability only; identity security **not** complete; PB-01–09 not started |

**Scope:** stable `reason_code`; PII-safe `parent_binding_attempts`; correlation id; flag; KPI + missing-contact. Non-scope: OTP; copy/success-path; pairing/Inbox/GSR; PB-01～09.  
**Flag:** `PARENT_BINDING_OBSERVABILITY` **default-off**; production Founder/ops 明確 `true` 後才寫入。Fingerprint: dedicated `PARENT_BINDING_PHONE_HMAC_KEY` only（no `APP_KEY`）；store default false.  
**Rollback:** set flag `false` (do not drop table first-line). Ops: `parent-binding:report --format=json` only. Pending: PB-01 copy · PB-02 UI · PB-03 Inbox.
