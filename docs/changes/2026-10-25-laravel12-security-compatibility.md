## 2026-10-25 — official Laravel 12 compatibility candidate (#977)
<!-- release-notes: silent_ship=silent-2026-10-25-laravel12-security-compatibility -->
<!-- silent-reason: unactivated framework dependency and compatibility candidate -->

Prepare fresh from main, separately from #4385 and closed #2833. Use official
Laravel 12.70.1 and compatible tools; remove advisory ignores and audit the lock.
Preserve whole-minute attendance/report/reschedule durations and whole-second,
absolute RFID/undo windows, plus the existing reminder-template day calculation.
Keep contracts, payroll writers, notification gates and campus/auth boundaries.
Preserve unknown LINE-setting rejection when validation filters nested keys.
Adapt PHPUnit helpers/providers/XML and exception fixtures without dropping tests;
the isolated HTTP harness registers the mail manager before blocking every send.
Add accurate Eloquent types; keep PHPStan level 5/scope and only shrink suppressions.
Disable only new optional Larastan env/collection style rules to retain live gates
and loaded-collection semantics. Capture the existing parent-card month label.

Sanctum 4 stops auto-loading migrations: publish its official 2019 migration under
the original filename. Existing migration history/schema must be verified before
activation; no ALTER, data backfill, token rotation or production DDL was run.
Publication, merge and activation remain separate decisions. Verify real PHP 8.2+
and exact runtime/range before activation; rollback uses the prior locked artifact,
with no automatic migration rollback or data repair. Retired routes stay retired.
