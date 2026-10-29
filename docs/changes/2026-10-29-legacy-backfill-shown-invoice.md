## 2026-10-29 — fix(billing): 補登收款 pays the bill the director saw (#4367)
<!-- release-notes: staff_update=staff-2026-10-29-legacy-backfill-shown-invoice -->
- 補登收款 (`director-record` + `legacy_flag_backfill`) accepts `invoice_id`; stale, void or foreign bills are refused with `legacy_backfill_invoice_stale` before any write (#4367).
