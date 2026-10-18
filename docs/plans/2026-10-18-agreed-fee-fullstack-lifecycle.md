# Isolated fee full-stack: database lifecycle slice

Goal: make reviewed #4253 bootstrap/migration helpers usable by later synthetic
browser fixtures. AllTrue_System; task chore/task-fee-fullstack-db-lifecycle-20261018;
base e60821ed. R2/T2 test infrastructure only, no production route or workflow.

1. Extract guard and same-process Migrator entry from source99312a2e. Reuse
   alltrueTestDatabase.php TCP allowlist/minimal config. Require APP_ENV=testing;
   accept its existing CI/suffixed synthetic schema contract.
2. Prove env/config redirects, remote host, cache and non-testing processes fail
   before providers/DDL. Subprocess provider canaries and one real isolated
   migration; existing phpunit-isolated.sh owns the ephemeral loopback database.
3. Focused tests, syntax/diff checks; normal PR under700, full CI, independent
   review, normal queue. Later fresh-main slices: fixture/router/seed, browser
   spec/config/mount, optional workflow. Do not install inert assets wholesale.

STRIDE: spoof/tamper/elevation checked before providers through env plus loaded
DSN validation; no passwords/tokens in refusals; exit90/success receipt; fail closed.
No HIGH production exposure: test support requires testing+loopback synthetic
DB and no app route loads it. Runtime key/flag/CORS apply only in this process.

Rollback: revert test helpers. Stops: production access/DDL/data/feature changes,
new app semantics and credentials keep separate concrete approvals. Syntax tests
are not browser or real-case acceptance; the three cases remain0/3 accepted.

## Verification

The alternate APP_CONFIG_CACHE fixture reproduced RED: boot completed with exit0
and reached the provider instead of refusing with90. Guard now rejects that env
redirect before bootstrap. Focused lifecycle + existing allowlist + worker safety
suite passed34tests/154assertions in2.667seconds using phpunit-isolated.sh.
Subprocess canaries cover21 unsafe env/loaded-connection forms, default/alternate
config cache, safe target/runtime and a real one-time migration plus replay.
Initial minimal-app fixture lacked migration repository/exception bindings;
corrected the fixture without weakening its assertions. Hosted CI/review pending.

Follow-up RED reproduced the .env/cache path with Laravel's native putenv adapter
turned off: getenv missed the override and boot reached the canary (exit0).
The LoadConfiguration callback now also checks Application::configurationIsCached
before providers. Both process-env and framework-resolved cache paths are refused.

Final-review follow-up: do not execute backend bootstrap/app.php before guard, since its manual provider registrations are too early. The isolated helper constructs the application, binds the framework console kernel (no scheduled-command discovery) and real HTTP/exception kernels, validates loaded config, then registers the route provider; normal config providers follow guarded bootstrap. Fixture bootstrap contains a throwing manual-registration trap. Exact shared AllTrue_test is admitted only with CI=true and GITHUB_ACTIONS=true; local use requires suffixed schemas. Refusals print field names, never raw rejected connection values. Five additional malformed credential/shared-schema fixtures plus all earlier cases pass. Correct combined directory-filter run:39tests/174assertions,3.169seconds. Hosted CI and final review must be renewed.

Final a7b review found the standalone success fixtures would fail under the supported shared local PHPUnit mode. Only the two standalone migration-success scenarios now explicitly require the isolated wrapper or isolated GitHub CI; local shared PHPUnit skips those with an actionable message rather than bypassing the guard. Negative guards remain active. Isolated combined run remains39tests/174assertions,3.238seconds, no skips.
