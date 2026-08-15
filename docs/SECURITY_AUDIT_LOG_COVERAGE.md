# Sensitive-action audit log coverage matrix (#890)

> Inventory only — no code changed by this document. Critical gaps become
> their own issues (see bottom) per #890's acceptance criteria.

## Coverage matrix (verified against `backend/app/`, 2026-08-15)

| # | Category | Action | Audit write? |
|---|---|---|---|
| 1 | 權限變更 | `DirectorAccountController::approve` — `User.type='D'`, `UserCampus.Approved=true` (`app/Http/Controllers/DirectorAccountController.php:126-128`) | ❌ **none** |
| 2a | 帳務作廢（Invoice） | `BillingController::voidInvoice` (`:184`) | ⚠️ Weak — audit string in `Invoice.Note` + `Log::info` (`:226`), no dedicated audit-log row |
| 2b | 帳務作廢（PaymentReport） | `PaymentReportController::void` (`:579`) | ⚠️ Weak — `voided_by`/`voided_at`/`void_reason` columns + `Log::info` (`:646`), no dedicated audit-log row |
| 3 | 堂數/SessionCount 手動調整 | `StudentClassController::update` (`:1408`, field write `:1502-1584`) | ❌ **none** for the field mutation itself (only indirect: session-rebuild side effects log separately) |
| 4 | PII 匯出 | `ExportController::students` → `StudentsExport` (Name/Phone/SchoolName) (`app/Http/Controllers/ExportController.php:13-19`) | ❌ **none** — no `Log::` calls anywhere in `ExportController.php` |
| 5a | Password reset request（公開） | `PasswordResetRequestController::store` (`:15`) | ⚠️ Weak — creates a `PasswordResetRequest` row (request record, not a completed-reset log) |
| 5b | Password reset（super_admin 代重設 director） | `DirectorAccountController::resetPassword` (`:194`, field write `:206-217`) | ❌ **none** |
| 6 | Bug 狀態變更 | `BugReportService::changeStatus` (`:211`) | ✅ `BugReportStatusLog::create()` (`:256`), inside `DB::transaction` |
| 7 | 薪資（Payroll） | `FinanceController::parttimePayrollLock/Reopen/RulesUpdate/TeacherRulesUpdate/TeacherRulesDelete` | ✅ `PayrollAuditLog::create()` at each corresponding write site |

**Also found, not in scope of the 7 categories above:**
- `StudentIdentityAuditLog` — ✅ fully wired for cross-campus student-identity linking (`StudentIdentityService::audit()`, `app/Services/StudentIdentityService.php:266`).
- ~~`SecurityAuditEvent` model exists but no code anywhere calls `::create()`~~ **Correction**: this was wrong — the initial grep only checked `::create()`/`::insert()`/`new SecurityAuditEvent(` and missed the actual call pattern, `SecurityAuditEvent::append(...)`. It's fully wired: `LineWebhookController::263`, `StudentController::500`, `ParentPortalController::304-424` (parent auth failures/success, sibling-switch). Delivered via #1420/PR #1580 (merged 2026-08-01), separate from this issue's scope. No follow-up needed here.

## Critical gaps → follow-up issues

Per #890's acceptance criteria ("Critical gaps 有 issue 與優先級"), the four categories with **no** audit write at all (not just a weak one) get their own issue rather than being bundled:

| Gap | Why critical | Follow-up |
|---|---|---|
| #1 權限變更無稽核 | Director approval/rejection changes who can act as director for a campus — highest-privilege action in the system with zero trail | #1810, P1 |
| #3 SessionCount 手動調整無稽核 | Same risk shape as the billing dual-truth bugs this session already touched (#934/#920/#959) — a manually-changed session count with no "who/when/why" trail is unauditable if a family disputes it | #1811, P1 |
| #4 PII 匯出無稽核 | Student PII export with zero log — cannot answer "who exported what, when" for a privacy request | #1812, P1 |
| #5b Admin 代重設密碼無稽核 | Highest-privilege account-takeover-adjacent action (super_admin can silently reset any director's password) with zero trail | #1813, P2 |


## What this document deliberately does not do

- Does not add any audit-log write itself — that's the follow-up issues' scope, so each can be reviewed/tested independently instead of landing as one large mixed-risk PR.
- Does not define retention/query-UI/export policy (#890's other stated gap) — that's a product/ops policy decision, not a code inventory; flagged here as still open.

## Refs

Refs #890.
