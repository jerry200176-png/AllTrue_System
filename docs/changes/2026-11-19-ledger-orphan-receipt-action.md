## 2026-11-19 — fix(billing): give orphan receipt anomalies an actionable path
<!-- release-notes: staff_update=staff-2026-11-19-ledger-orphan-receipt-action -->

- In-app #576: a confirmed receipt whose source course is unavailable in the student's authorized ledger keeps its anomaly, receipt and payment history, but offers the existing headquarters-report action instead of an impossible withdrawal. Reuse the ledger's already scoped course IDs; no additional query, write, permission or accounting-rule change.
- The student-ledger UI respects server manual review even when several anomaly codes merge into one card. Ordinary valid-source withdrawal remains available. Historical receipt ownership still requires a separately verified repair; this display fix does not resolve that data case.
