## 2026-10-19 — ci: allow the complete PHPUnit gate to finish
<!-- release-notes: silent_ship=silent-2026-10-19-phpunit-runtime-budget -->
<!-- silent-reason: CI resource budget only; product behavior and production controls are unchanged. -->

- Raise the PHPUnit job ceiling from 15 to 25 minutes. Successful full coverage run [39187646437](https://github.com/jerry200176-png/AllTrue_System/actions/runs/39187646437) took 13:31 for 4,258 tests (job 14:42); [39188010042](https://github.com/jerry200176-png/AllTrue_System/actions/runs/39188010042) timed out twice, with attempt 2 still progressing at 3,660/4,270.
- Keep every test, coverage threshold, required check, finite worker deadline, runner, service, permission and production gate. Timeout is not a passing result; complete exact-head CI and independent review remain required.
- Rollback restores the prior 15-minute ceiling; repeated runtime cancellation may return.
