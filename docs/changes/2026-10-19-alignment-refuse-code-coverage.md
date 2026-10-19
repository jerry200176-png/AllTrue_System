# 2026-10-19 — test: unpaid invoice alignment refuse-code coverage

silent_ship: true

Adds focused PHPUnit asserts that UnpaidInvoiceChargeAlignment preview refuses with exact codes (standing report, money evidence, invoice scope, report owner, owner mismatch, line-item unsupported state). Release-readiness only; no production behavior change.
