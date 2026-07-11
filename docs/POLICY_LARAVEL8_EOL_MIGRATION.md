# Laravel 8 EOL Migration Readiness (#977)

Status: **assessment / plan** (2026-07-11). No code migrated yet — this is the prerequisite analysis so the upgrade can be scoped, de-risked, and owner-approved.

## 1. Current state

| | Value | Note |
|---|---|---|
| Framework | **Laravel 8.83.29** | **EOL** — Laravel 8 has received **no security fixes since ~Feb 2023** |
| PHP | 8.2.30 | Good — supports Laravel 9, 10, and 11 (11 requires PHP ≥8.2) |
| Test safety net | ~184 Feature + a thin Unit layer (#991) | Feature coverage is the migration's main safety net |

## 2. Open security advisories (from `composer audit`, all unpatchable on 8)

| Severity | Advisory | Fixed in | **Exposure in THIS app** |
|---|---|---|---|
| **High** | CRLF injection in the default `email` validation rule (GHSA-5vg9-5847-vvmq) | 12.60.0+ | **Low / likely none** — the app validates its email field as `nullable|string|max:128`, **not** via Laravel's `email` rule (grep of app/ + routes/). Re-confirm before relying on this. |
| Medium | Temporary Signed URL path confusion (GHSA-crmm-hgp2-wgrp) | 12.61.1+ | **Verify** — 13 signed-URL references; the payment-report flow uses a **custom** `PaymentReportTokenService`, not Laravel signed routes, which would reduce exposure. Audit the 13 sites. |
| Medium | File validation bypass — CVE-2025-27515 (GHSA-78fx-h6xr-vch4) | 10.48.29+ | **Exposed** — `mimes:`/`image`/`file` validation on real upload endpoints: `ImportController` (xlsx/csv), avatar (`AuthController`), `BugReportController` attachments, `ChatController`. **This is the one interim risk worth mitigating now.** |
| — | `swiftmailer/swiftmailer` **abandoned** | symfony/mailer (L9+) | **Low** — no `Mail::` usage found; notifications go via LINE/Telegram. Resolved automatically by the upgrade. |

**Because the fixes ship only in Laravel 10.48.29 / 12.60+, the framework upgrade is the only real remediation.** Dependabot *alerts* also appear disabled (the alerts API returns `[]` while the dependency graph flags these) — enable them (repo setting) so this is tracked going forward.

## 3. Interim mitigation (ship before the migration)

Only CVE-2025-27515 (file-validation bypass) has real exposure. Recommended reversible guard, independent of the upgrade:
- Add a **shared server-side content-type re-check** (`finfo`/`getMimeType()` on the stored file) after Laravel's `mimes:` validation on the four upload endpoints, rejecting a mismatch. Defense-in-depth that neutralises the bypass regardless of framework version. Tracked separately; small + testable.

## 4. Target & path

- **Recommended target: Laravel 11** (PHP 8.2 native; longest runway). Acceptable interim: **Laravel 10** if a dependency blocks 11.
- Laravel mandates **incremental** major upgrades: **8 → 9 → 10 → 11**. Do them as sequential PRs, CI-green at each step, deploy-and-soak between majors.

## 5. Effort drivers / breaking changes

| Area | 8→9 | 9→10 | 10→11 |
|---|---|---|---|
| Mail | **SwiftMailer → Symfony Mailer** (largest single change; low here — mail barely used) | — | — |
| Symfony base | 5 → 6 | 6 → 6.x | → 7 |
| `laravel/sanctum ^2.11` | → **3.x** (config/middleware changes) | → 3.3+ | → 4.x |
| `maatwebsite/excel ^3.1` | verify 3.1 Laravel-9 compat (heavy Excel usage in ImportController) | | |
| `pusher ^7`, `guzzle ^7.12`, `sentry-laravel ^4.25` | mostly compatible; bump + smoke-test | | |
| App code | `$dates`→`$casts`, `lang/` path, `Str`/`Arr` deprecations, route/exception-handler shape (11 restructures bootstrap) | flysystem 3, monolog 3 | new app skeleton (optional) |

## 6. Prerequisites (de-risk before starting)

1. **Staging environment (#868)** — do the upgrade + soak on staging before prod. Currently blocked; a strong argument to unblock it.
2. **Broaden test coverage**, esp. the thin Unit layer (#991) and the upload/import + payment paths, so regressions surface in CI.
3. **Enable Dependabot alerts** + auto-PRs for dependencies (owner repo setting).
4. **Dependency compatibility matrix** — pin the target versions of sanctum, maatwebsite/excel, pusher, sentry that support the chosen Laravel major.

## 7. Recommended phased plan

1. **Now (no owner approval needed):** ship the CVE-2025-27515 upload-mime guard (interim); enable Dependabot alerts.
2. **Phase 1 (owner GO + staging):** 8→9 branch — Symfony 6, Sanctum 3, Mail→Symfony Mailer, deprecation sweep; CI green; soak on staging.
3. **Phase 2:** 9→10 — flysystem/monolog majors; deploy + soak.
4. **Phase 3:** 10→11 — bootstrap restructure; deploy + soak.
5. Each phase is its own reviewed PR with a rollback (revert + `composer install` from the prior lock).

## 8. Risk & business framing

Being on an EOL framework is a **standing, compounding risk** (no future CVE patches). None of the current advisories is confirmed high-exposure here (the "high" one likely doesn't apply), so this is **important but not an emergency** — plan it deliberately with staging rather than rush. Estimated effort: **~2–4 focused engineering weeks across the three majors**, dominated by dependency compatibility + regression testing, not app rewrites (the app is mostly plain controllers/services).

**Owner decisions:** (a) approve the phased upgrade + target (Laravel 11); (b) unblock staging (#868); (c) enable Dependabot alerts. The interim upload-mime guard I can ship immediately.
