# Laravel major-upgrade inventory (#977 / TD-014)

> **This is a plan, not an upgrade.** No `composer.json` version constraint, no `vendor/`, no code in `app/` is touched by this document. Do not start the actual migration until TD-076 reaches Phase 5 — see `ALLTRUE_ENGINEERING_NORTH_STAR.md` ("不准跟 TD-076 混"). PR #1661 (framework version bump with no migration work) should stay closed until that gate opens.

## Why now (evidence, not urgency)

`composer.json`'s `config.audit.ignore` block already carries 5 CVE entries whose stated resolution requires a framework major version — they are contained (reachability-reviewed as unused code paths), not fixed. TD-014 tracks the same gap. This inventory exists so that when TD-076 clears, the upgrade can start from a checklist instead of a cold read.

## Current state (verified against `backend/composer.json`, 2026-08-15)

| Package | Current | Notes |
|---|---|---|
| PHP | 8.2.30 (platform pin) | **Already current** — the earlier "PHP 8.1→8.2" note in PR #1661 is stale; no PHP upgrade needed. |
| `laravel/framework` | ^8.75 | EOL. Target below. |
| `laravel/sanctum` | ^2.11 | Auth guard config changed materially in Sanctum 3/4 — audit every `auth:sanctum` middleware use. |
| `phpunit/phpunit` | ^9.6 | Laravel 11+ ships PHPUnit 11 by default; 9.x remains installable but attribute-based test config (`#[Test]`) becomes the documented style. |
| `guzzlehttp/guzzle` | ^7.12.1 (resolved 7.15.3) | **Already patched** — the CVE this issue's "Immediate" fix targeted is already fixed on `main`. Nothing to do here. |
| `nunomaduro/collision` | ^5.11 | Tied to PHPUnit/Laravel major — bumps together. |

### Preparation recheck (2026-09-03)

This recheck is read-only and does not change `composer.json`, `composer.lock`,
`vendor/`, application code, or production:

- `composer prohibits laravel/framework 12.60.0 --tree` confirms the root
  `^8.75` constraint and the current Sanctum 2 line are incompatible with the
  target. The resolved framework is still `8.x-dev`.
- The target framework requires a coordinated dependency migration, including
  Flysystem 1→3, Monolog 2→3, Carbon 2→3, Symfony 5→7, Egulias 2→3/4, and
  `voku/portable-ascii` 1→2. These are compatibility blockers, not patch-only
  updates.
- `composer update laravel/framework:12.60.0 --with-all-dependencies
  --dry-run --no-scripts` fails closed because `12.60.0` is not a subset of
  the current `^8.75` root constraint. No lockfile was written.

The next safe step is a Founder-approved staging branch with Laravel 12
compatibility tests and rollback evidence. Do not loosen the root constraint
or activate a production migration from this inventory update.

## Target version

Per TD-014's own CVE evidence, the fixes land at different framework versions:
- File validation (CVE-2025-27515): fixed **10.48.29+**
- CRLF email rule (GHSA-5vg9-5847-vvmq): fixed **12.60.0+**
- Signed URL (GHSA-crmm-hgp2-wgrp): fixed **12.61.1+**

**Target: Laravel 12.x**, not 10 — landing on 10 would leave two of the three tracked CVEs still open and require a second major hop later. Skipping 9/10/11 as intermediate stops is standard Laravel upgrade practice (each major is designed to be upgraded to directly from the prior one via the official upgrade guide, not chained).

## Compatibility inventory (first read, not yet verified against code)

Ranked by blast radius — check top-down when the migration epic starts:

1. **Sanctum 2→4**: `auth:sanctum` middleware appears on every authenticated API route (300+ routes per `docs/REF_API_ROUTES.md`, #992). Config file shape changed across majors — diff `config/sanctum.php` against the vendor default before merging.
2. **Middleware kernel restructure**: Laravel 11 moved `app/Http/Kernel.php` middleware registration into `bootstrap/app.php`. Every custom middleware in this repo (campus isolation, auth, throttle) needs re-registration, not just a version bump.
3. **PHPUnit 9→11 / Pest**: test suite is 310+ PHPUnit tests (per the 2026-08-15 external review). `RefreshDatabase`, factory syntax (`Campus::factory()`), and `assertJsonStructure`-style assertions are stable across these versions, but `phpunit.xml` schema changed in PHPUnit 10 — validate against the new schema before the first CI run on the new version.
4. **Filesystem validation** (the actual CVE-2025-27515 fix): if any controller validates uploaded filenames/extensions, re-test against Laravel 12's stricter default — this is the security-relevant behavior change, not just a version number.
5. **Guzzle, Collision, Tinker**: no known compat concern at target version; bump alongside the framework in the same PR for lockfile consistency, not upgraded independently ahead of time.

## What this inventory deliberately does not include

- No `composer.json` edits.
- No date estimate — TD-076 Phase 5 timing gates the start, not effort estimation for this upgrade alone.
- No decision on whether to skip Laravel 9/10/11 entirely in one hop vs. staged majors — that's an implementation-time call once TD-076 clears and this inventory gets its first real verification pass against `vendor/`.

## Refs

Refs #977, TD-014, TD-075 (audit-ignore expiry tracking), #1661 (should stay closed until this can start for real).
