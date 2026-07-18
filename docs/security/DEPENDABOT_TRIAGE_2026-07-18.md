# Dependabot triage — AllTrue open alerts (2026-07-18)

**Owner:** Founder / CTO Agent  
**Installed framework:** `laravel/framework` **8.x-dev** (`composer.lock`; require `^8.75`)  
**Method:** Evidence-based; no upgrade solely because alert exists; no dismiss solely because CI green.

| # | Package | Advisory | Affected installed? | Reachable? | Prod exposure | Exploit preconditions | Fixed version (upstream) | Breaking risk | Test impact | Recommended action | Deadline |
|---|---------|----------|---------------------|------------|---------------|----------------------|--------------------------|---------------|-------------|---------------|----------|
| [4](https://github.com/jerry200176-png/AllTrue_System/security/dependabot/4) | laravel/framework | GHSA-5vg9-5847-vvmq — CRLF in default email rule (High) | Range claims `<12.60.0` (includes 8.x numerically) but **patch only ships on Laravel 12** | **Low** — reviewed 2026-07-12: no user-supplied address mail via default email rule; Laravel 8 uses SwiftMailer | Mail outbound | Unauth attacker + app sends mail to attacker-controlled addresses via vulnerable rule | 12.60.0 | **Major** (8→12) | Full suite + mail | **Keep** `composer.json` `audit.ignore` with documented rationale; track framework EOL on **#977**; do **not** jump to L12 in this PR | Re-review **2026-08-18** or on mail-feature change |
| [5](https://github.com/jerry200176-png/AllTrue_System/security/dependabot/5) | laravel/framework | GHSA-crmm-hgp2-wgrp — temporary signed URL path confusion (Medium) | Same: alert range `<12.61.1`; fix on L12 | **Needs confirmation** if any temporary signed local-disk URLs are issued | Signed file URLs | Crafted URL vs local driver signing | 12.61.1 | **Major** (8→12) | Auth/file tests | **Package:** inventory signed-URL usage; if unused → document residual; if used → harden or accelerate #977 | Inventory by **2026-07-25**; upgrade only with L10/L12 migration plan |
| [3](https://github.com/jerry200176-png/AllTrue_System/security/dependabot/3) | laravel/framework | GHSA-78fx-vch4 / CVE-2025-27515 — file validation bypass (`files.*`) (Medium) | Range `<10.48.29` → 8.x flagged; fix on **10.48.29+** | **Conditional** — only if wildcard file/image array validation is used | Upload endpoints | Malicious multipart bypassing `files.*` rules | 10.48.29 (still major from 8) | **Major** (8→10+) | Upload/validation tests | Grep for `files.*` / image array rules; if present add explicit rules + tests; **no** blind L10 bump | Code inventory **2026-07-25**; mitigations before any major upgrade |

## Explicit non-actions

- Do **not** `composer require laravel/framework:^12` as a security hotfix.  
- Do **not** mark alerts “fixed” without installed version change or accepted risk with expiry.  
- Prefer **#977** Laravel EOL migration as the durable remediation path.

## Follow-ups (implementation-ready packages)

1. **Signed URL inventory** (alert #5) — list controllers using `temporaryUrl` / `URL::temporarySignedRoute`.  
2. **Upload validation inventory** (alert #3) — list `files.*` validators.  
3. Continue Laravel upgrade epic **#977** (R2/R3).  
