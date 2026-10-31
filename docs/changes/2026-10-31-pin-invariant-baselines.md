## 2026-10-31 — chore(ops): pin the 4 nightly money/session invariant baselines (#4678)
<!-- release-notes: silent_ship=silent-2026-10-31-pin-invariant-baselines -->
<!-- silent-reason: nightly check thresholds only; no screen or data change -->
- The first 04:00 run on the Laravel 12 build (2026-10-31, read through pi-health 41157274374) counted `package_over_debit` 109, `session_debited_twice` 2, `unpaid_charge_vs_rate_count` 0 and `paid_flag_vs_invoice` 1. These are pinned in `config/reproduction_baselines.php`, so the nightly job now fails only on NEW drift above them. Lower each number as the known cases are repaired (package ledgers #4470/#4653). The baseline test now sets `null` explicitly for the unmeasured path.
