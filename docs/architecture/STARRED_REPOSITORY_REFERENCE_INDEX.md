# Starred Repository Reference Index

> **Status:** Active reference index  
> **Last verified:** 2026-08-28 (GitHub `user/starred`, 54 repositories)  
> **Scope:** AllTrue System and the related Sunrise Cafe project

## Purpose

GitHub Stars are a curated engineering shelf, not a second source of truth.
When a task says「參考某個 repo」, the agent must start here, read the current
source and tests for the relevant behavior, pin the commit, and then adapt the
principle to the target repository. The target repository's code, contracts,
business rules, authorization, and tests always win over an upstream pattern.

This index is the current source for the Star shelf. The older
[`RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md`](RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md)
is a planning record; it can mention repositories that were useful during
research but are no longer starred.

## Operating rules

1. **Search here first.** Use the category and trigger column to choose the
   smallest relevant set; do not browse all Stars or copy a framework because
   it is popular.
2. **Read code, not only README.** For an adopted pattern, record the exact
   commit, license, source paths, test paths, failure behavior, and the local
   boundary it must not cross.
3. **Pin evidence.** A research note links to a commit URL. Moving branches,
   current Star count, and popularity are not architecture evidence.
4. **License is a hard boundary.** MIT/Apache/BSD still require attribution
   review. GPL/AGPL and `NOASSERTION` repositories are behavior references only
   unless a separate legal review approves reuse.
5. **Keep the shelf small.** A repo needs a concrete AllTrue/Sunrise use case,
   an active maintenance signal, or a documented research question. A future
   candidate stays in the exploratory group only while that question exists.
6. **After every Star change**, update this file's inventory and the reason;
   never let an old RFC silently become the current Star list.

## Current shelf

### Owned products

| Repository | Use | Trigger |
|---|---|---|
| [jerry200176-png/AllTrue_System](https://github.com/jerry200176-png/AllTrue_System) | Target system; source of truth | Every AllTrue decision |
| [jerry200176-png/sunrise-cafe](https://github.com/jerry200176-png/sunrise-cafe) | Related product; separate domain and language | Sunrise feature or shared engineering pattern |

### Core stack and delivery

| Repository | Role | Tier |
|---|---|---|
| [laravel/framework](https://github.com/laravel/framework) | AllTrue runtime conventions and framework behavior | Core |
| [laravel/pint](https://github.com/laravel/pint) | PHP formatting | Core |
| [vuejs/core](https://github.com/vuejs/core) | AllTrue UI runtime | Core |
| [vitejs/vite](https://github.com/vitejs/vite) | Frontend build and dev server | Core |
| [vitest-dev/vitest](https://github.com/vitest-dev/vitest) | Frontend unit/component tests | Core |
| [microsoft/playwright](https://github.com/microsoft/playwright) | Browser and release smoke tests | Core |
| [cloudflare/workers-sdk](https://github.com/cloudflare/workers-sdk) | Cloudflare/Wrangler deployment and tooling | Core for Cloudflare work |
| [cloudflare/agents](https://github.com/cloudflare/agents) | Stateful agent, MCP, concurrency and continuation patterns | Platform reference |

### Scheduling, billing, communication and audit patterns

| Repository | What it is for | Boundary |
|---|---|---|
| [calcom/cal.diy](https://github.com/calcom/cal.diy) | Recurring series versus individual occurrence behavior | Do not replace AllTrue's ClassSession model |
| [fullcalendar/fullcalendar](https://github.com/fullcalendar/fullcalendar) | Calendar interaction and event/occurrence UX | Do not treat UI overlap as domain truth |
| [frappe/erpnext](https://github.com/frappe/erpnext) | Invoice/payment status and enterprise workflow separation | GPL; behavior reference only |
| [invoiceninja/invoiceninja](https://github.com/invoiceninja/invoiceninja) | Invoice lifecycle and customer-facing billing UI | `NOASSERTION`; no code reuse |
| [kimai/kimai](https://github.com/kimai/kimai) | Usage × rate reporting and invoice-oriented views | AGPL; behavior reference only |
| [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii) | Reconciliation, rules, and reasons for corrections | AGPL; behavior reference only |
| [GibbonEdu/core](https://github.com/GibbonEdu/core) | School roles, student/parent/teacher boundaries | GPL; behavior reference only |
| [novuhq/novu](https://github.com/novuhq/novu) | Notification workflow, digest, snooze and retry patterns | `NOASSERTION`; keep existing channels |
| [chatwoot/chatwoot](https://github.com/chatwoot/chatwoot) | Inbox, assignment and conversation state UX | `NOASSERTION`; preserve branch isolation |
| [line/line-bot-sdk-php](https://github.com/line/line-bot-sdk-php) | AllTrue LINE Messaging API contract | SDK reference; verify webhook auth locally |
| [line/line-bot-sdk-nodejs](https://github.com/line/line-bot-sdk-nodejs) | Sunrise LINE integration contract | SDK reference; do not mix product data |
| [irazasyed/telegram-bot-sdk](https://github.com/irazasyed/telegram-bot-sdk) | Telegram bot API shape | Channel only; business rules stay local |
| [laravel-notification-channels/telegram](https://github.com/laravel-notification-channels/telegram) | Laravel notification channel adapter | Do not hide domain state in channel code |
| [supabase/supabase](https://github.com/supabase/supabase) | Sunrise Auth/RLS/Realtime reference | Do not migrate AllTrue to Supabase |
| [supabase/supabase-js](https://github.com/supabase/supabase-js) | Sunrise client/API usage | Sunrise only unless separately approved |
| [upstash/ratelimit-js](https://github.com/upstash/ratelimit-js) | Edge/serverless rate-limit patterns | Evaluate against Laravel/Cloudflare runtime |
| [spatie/laravel-permission](https://github.com/spatie/laravel-permission) | Role/permission modeling patterns | Existing AllTrue middleware remains authoritative |
| [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog) | Actor/subject/event/change audit trail | Adapt to existing audit policy |
| [spatie/laravel-health](https://github.com/spatie/laravel-health) | Composable health checks | Compare with existing production health contract |
| [spatie/laravel-schedule-monitor](https://github.com/spatie/laravel-schedule-monitor) | Missed/slow scheduled-job monitoring | Do not bypass deploy/control-plane rules |
| [getsentry/sentry-laravel](https://github.com/getsentry/sentry-laravel) | Release, environment and PII-safe error context | Use existing Sentry boundary |
| [open-telemetry/opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php) | Vendor-neutral traces and metrics | Future option; do not duplicate Sentry blindly |

### Admin UI and design references

| Repository | What to inspect | Boundary |
|---|---|---|
| [vbenjs/vue-vben-admin](https://github.com/vbenjs/vue-vben-admin) | Vue route metadata, menu generation, layout navigation | Do not migrate the AllTrue shell wholesale |
| [carbon-design-system/carbon](https://github.com/carbon-design-system/carbon) | Dense tables, selection, expansion, batch actions and a11y | AllTrue Vue design tokens remain SSOT |
| [primer/css](https://github.com/primer/css) | Tool-product density and subtle status treatment | Use principles, not a theme swap |
| [radix-ui/primitives](https://github.com/radix-ui/primitives) | Dialog/menu/focus and accessibility contracts | React primitives; no runtime import into Vue |
| [shadcn-ui/ui](https://github.com/shadcn-ui/ui) | Copyable interaction contracts and states | Do not change AllTrue to React |
| [argyleink/open-props](https://github.com/argyleink/open-props) | Token layering and adaptive spacing ideas | Existing `--ds-*` tokens win |
| [pbakaus/impeccable](https://github.com/pbakaus/impeccable) | AI design-language and anti-slop checks | Must obey `RULE_DESIGN_SYSTEM.md` |
| [Leonxlnx/taste-skill](https://github.com/Leonxlnx/taste-skill) | Design brief inference and restrained visual dials | Backend ops density, not landing-page art |
| [VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md) | Brand DESIGN.md structure and token documentation | Reference documentation style only |
| [filamentphp/filament](https://github.com/filamentphp/filament) | Admin resource, form and batch-action affordances | MIT; do not replace Vue with Livewire |

### Security, quality and agent references

| Repository | What to inspect | Boundary |
|---|---|---|
| [ossf/scorecard](https://github.com/ossf/scorecard) | Supply-chain health signals | Advisory first; do not block blindly |
| [step-security/harden-runner](https://github.com/step-security/harden-runner) | CI egress and runner integrity | Must fit current GitHub governance |
| [gitleaks/gitleaks](https://github.com/gitleaks/gitleaks) | Secret scanning rules and false-positive handling | Never print or export secrets |
| [cloudflare/security-audit-skill](https://github.com/cloudflare/security-audit-skill) | Machine-readable, phased security findings | Audit only; never attack production |
| [larastan/larastan](https://github.com/larastan/larastan) | Laravel-aware static analysis | Respect the repository baseline gate |
| [anthropics/claude-agent-sdk-python](https://github.com/anthropics/claude-agent-sdk-python) | Agent SDK boundaries and tool execution | Never bypass AllTrue governance |
| [anthropics/claude-code-action](https://github.com/anthropics/claude-code-action) | GitHub action agent workflow patterns | PR/CI permissions remain local policy |
| [anthropics/skills](https://github.com/anthropics/skills) | Official skill packaging and discovery | Selective adoption only |
| [VoltAgent/awesome-agent-skills](https://github.com/VoltAgent/awesome-agent-skills) | Skill discovery and comparison | Do not install a collection wholesale |

### Exploratory, with an explicit research question

| Repository | Question that justifies keeping it |
|---|---|
| [HKUDS/DeepTutor](https://github.com/HKUDS/DeepTutor) | Which personalized-learning concepts could inform future learning analytics? |
| [PrimeIntellect-ai/prime-agent](https://github.com/PrimeIntellect-ai/prime-agent) | Which long-running/self-improving agent controls are useful for governed engineering work? |
| [vercel/examples](https://github.com/vercel/examples) | Which small TypeScript/Next patterns could help Sunrise without changing AllTrue's stack? |

Exploratory repositories are not architecture authority. If the question is
answered or no local task links to one for 90 days, review it for removal.

## Code-reading ledger (verified 2026-08-28)

The entries below were inspected beyond metadata. Links are pinned to the
latest commit observed in this run; the test path is included so future work
can verify the claimed behavior rather than copy a surface pattern.

| Repository / commit / license | Source and test evidence | Extracted principle for AllTrue |
|---|---|---|
| [calcom/cal.diy @ `176037d`](https://github.com/calcom/cal.diy/tree/176037d0afbe572f870a3c702985e7cd83fe6c0c) · MIT | [`recurring-booking.service.ts`](https://github.com/calcom/cal.diy/blob/176037d0afbe572f870a3c702985e7cd83fe6c0c/apps/api/v2/src/lib/services/recurring-booking.service.ts) delegates to a bounded service; [`recurring-bookings.e2e-spec.ts`](https://github.com/calcom/cal.diy/blob/176037d0afbe572f870a3c702985e7cd83fe6c0c/apps/api/v2/src/platform/bookings/2024-08-13/controllers/e2e/recurring-bookings.e2e-spec.ts) rejects a count above the configured recurrence and verifies dates/recurring identity. | Keep contract/series identity separate from an occurrence; validate the requested horizon and prove the generated occurrences end-to-end. AllTrue still uses `StudentClass` + `ClassSession`. |
| [frappe/erpnext @ `0223223`](https://github.com/frappe/erpnext/tree/0223223385765f9299172968927ee209092835b5) · GPL-3.0 | [`status.py`](https://github.com/frappe/erpnext/blob/0223223385765f9299172968927ee209092835b/erpnext/accounts/doctype/sales_invoice/services/status.py) derives submitted status from outstanding amount, due date and docstatus; [`test_payment_entry.py`](https://github.com/frappe/erpnext/blob/0223223385765f9299172968927ee209092835b/erpnext/accounts/doctype/payment_entry/test_payment_entry.py) verifies submit/cancel changes outstanding and status. | Separate document lifecycle from payment state and make cancellation reversible/auditable; adapt the idea to AllTrue's reported-paid/accounting split, never copy GPL code. |
| [novuhq/novu @ `ed0652b`](https://github.com/novuhq/novu/tree/ed0652b3024bb7e9674c50f976fa9a38c42f0e28) · `NOASSERTION` | [`snooze-notification.usecase.ts`](https://github.com/novuhq/novu/blob/ed0652b3024bb7e9674c50f976fa9a38c42f0e28/apps/api/src/app/inbox/usecases/snooze-notification/snooze-notification.usecase.ts) commits state in a transaction before an external queue call, uses delayed jobs/retry backoff, and validates tier limits; [`snooze-notification.spec.ts`](https://github.com/novuhq/novu/blob/ed0652b3024bb7e9674c50f976fa9a38c42f0e28/apps/api/src/app/inbox/usecases/snooze-notification/snooze-notification.spec.ts) tests transaction ordering and failure limits. | Do not hold a DB transaction across an external notification call; make delayed work claimable, retryable and explicitly bounded. |
| [chatwoot/chatwoot @ `f8e1655`](https://github.com/chatwoot/chatwoot/tree/f8e165519c249705de3b3da65c22a6705a2db20a) · `NOASSERTION` | [`conversation.rb`](https://github.com/chatwoot/chatwoot/blob/f8e165519c249705de3b3da65c22a6705a2db20a/app/models/conversation.rb) has explicit open/resolved/pending/snoozed states, assignee scopes and validations; [`assignments_controller.rb`](https://github.com/chatwoot/chatwoot/blob/f8e165519c249705de3b3da65c22a6705a2db20a/app/controllers/api/v1/accounts/conversations/assignments_controller.rb) updates team assignment under a row lock. | Make inbox/workflow states and ownership explicit; protect assignment races with a server-side lock and keep branch authorization local. |
| [carbon-design-system/carbon @ `7bd6bba`](https://github.com/carbon-design-system/carbon/tree/7bd6bbbc73dba7c937a4b8303573756b81bd169a) · Apache-2.0 | [`DataTable.tsx`](https://github.com/carbon-design-system/carbon/blob/7bd6bbbc73dba7c937a4b8303573756b81bd169a/packages/react/src/components/DataTable/DataTable.tsx) normalizes table state, exposes sort/expand/selection ARIA attributes and only shows batch actions for selected rows; [`DataTable-test.js`](https://github.com/carbon-design-system/carbon/blob/7bd6bbbc73dba7c937a4b8303573756b81bd169a/packages/react/src/components/DataTable/__tests__/DataTable-test.js) exercises filtered selection and batch behavior. | Dense operational lists need one state model, keyboard/ARIA contracts and selection-aware actions; adapt the contract to AllTrue Vue primitives. |
| [spatie/laravel-activitylog @ `95fb95e`](https://github.com/spatie/laravel-activitylog/tree/95fb95ec89072b518e25ba277b82a953bd38635b) · MIT | [`ActivityLogger.php`](https://github.com/spatie/laravel-activitylog/blob/95fb95ec89072b518e25ba277b82a953bd38635b/src/Support/ActivityLogger.php) records causer, subject, event, changes and properties and restores logging state in `finally`; [`ActivityLoggerTest.php`](https://github.com/spatie/laravel-activitylog/blob/95fb95ec89072b518e25ba277b82a953bd38635b/tests/Support/ActivityLoggerTest.php) verifies actor/subject/property behavior. | Sensitive attendance, billing and schedule mutations need who/what/when plus before/after context and safe temporary suppression. Use existing AllTrue audit policy, not a duplicate package. |
| [vbenjs/vue-vben-admin @ `16ba484`](https://github.com/vbenjs/vue-vben-admin/tree/16ba48470a87fa0fc972254d804d61a5f9fa40c3) · MIT | [`generate-menus.ts`](https://github.com/vbenjs/vue-vben-admin/blob/16ba48470a87fa0fc972254d804d61a5f9fa40c3/packages/utils/src/helpers/generate-menus.ts) derives menu entries from route metadata, preserves parent paths, sorts by order and removes hidden items; [`use-navigation.ts`](https://github.com/vbenjs/vue-vben-admin/blob/16ba48470a87fa0fc972254d804d61a5f9fa40c3/packages/effects/layouts/src/basic/menu/use-navigation.ts) resolves internal routes versus external/new-window links. | Keep navigation as a registry with permission/visibility metadata and preserve deep links; this supports the existing AllTrue primary/secondary sidebar split. |
| [cloudflare/agents @ `e33468b`](https://github.com/cloudflare/agents/tree/e33468b63684971ef1fac730d5bbf3358307113c) · MIT | [`index.ts`](https://github.com/cloudflare/agents/blob/e33468b63684971ef1fac730d5bbf3358307113c/packages/agents/src/index.ts) guards state/RPC messages and closed WebSocket responses; [`submit-concurrency.test.ts`](https://github.com/cloudflare/agents/blob/e33468b63684971ef1fac730d5bbf3358307113c/packages/agents/src/chat/__tests__/submit-concurrency.test.ts) tests drop/latest policies, reset epochs and idempotent release. | Any future agent/MCP action needs typed message guards, explicit concurrency policy and idempotent cleanup; it must not bypass AllTrue authorization or production controls. |

Repos in the current shelf that are not in this ledger are **metadata
classified, not architecture-verified**. They become code-reading entries when
a real task invokes them. This prevents a Star or README from being presented
as proof.

## Adaptation map to AllTrue

| Product need | First references | Local source of truth |
|---|---|---|
| Recurring course / single occurrence | Cal.com, FullCalendar | `RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`, `StudentClass`, `ClassSession` |
| Course continuity / trial / renewal | Gibbon, ERPNext | `RFC_COURSE_CONTINUITY.md`; never physically merge financial entities |
| Reported paid / settlement / void | ERPNext, Invoice Ninja, Firefly | `RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`, payment rules and audit policy |
| Director inbox / notifications | Novu, Chatwoot, LINE SDKs | Existing exception workflows, channel adapters and branch scoping |
| Audit and recovery | Spatie Activitylog, ERPNext | `docs/security/AUDIT_LOG_POLICY.md` and repair manifests |
| Dense operations UI | Carbon, Primer, Vben, Radix | `docs/RULE_DESIGN_SYSTEM.md`, existing Vue components and a11y tests |
| Agent/MCP/automation | Cloudflare Agents, Anthropic skills | Governance, Control Plane Contract and least-privilege auth |

## Star history and removals

These repositories are intentionally no longer starred as of this index:

| Repository | Reason |
|---|---|
| `ShawnPana/phone-harness` | Mobile phone control has no current AllTrue or Sunrise product boundary |
| `alfio-event/alf.io` | Ticketing domain was not needed for the current products |
| `crater-invoice-inc/crater` | Billing UI overlap without a distinct current decision |
| `alextselegidis/easyappointments` | Scheduling overlap; Cal.com/FullCalendar cover the active question |
| `microsoft/fluentui` | UI reference overlap; current Vue/Carbon/Primer set is sufficient |
| `alexpate/awesome-design-systems` | Meta-list duplicated by the maintained design references already retained |
| `pacifio/ui` | Dense UI reference was too broad and not needed after the local design system work |
| `nextlevelbuilder/ui-ux-pro-max-skill` | Replaced by the locally governed design skill and AllTrue design rules |
| `birobirobiro/awesome-shadcn-ui` | Duplicate collection; the canonical shadcn source remains starred |
| `akaunting/akaunting` | Accounting reference overlap; ERPNext/Firefly cover the current questions |

Removal means “not in the current reference shelf,” not “bad software.” A
future task can re-evaluate one with a concrete research question.

## Future maintenance checklist

- Add a repo only with a one-line local use case and category.
- Before using it as architecture evidence, record license, commit, source
  paths, test paths and the failure/recovery behavior inspected.
- Prefer maintained releases and primary source; popularity alone is not a
  selection criterion.
- At review time, remove stale exploratory repos and update the Star history.
- Keep implementation decisions in AllTrue RFC/ADR files; this index records
  evidence and routing, not product authority.
