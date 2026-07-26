---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-07-26
---

# AllTrue UI Foundation

> Ops / product surfaces only. Brand / Auth (`Login.vue`) still follow [`docs/RULE_DESIGN_SYSTEM.md`](../RULE_DESIGN_SYSTEM.md) §1.1.
> Color SSOT remains `--ds-*` in `frontend/src/styles.css` + RULE. This file defines **structure, density, primitives, and migration**.

## 1. Design principles

1. Professional, quiet, reliable — built for administrative scanning, not demos.
2. High information density with clear hierarchy (title → meta → filters → data → actions).
3. One primary action per region; semantic color only for status.
4. Progressive adoption — no Big Bang library swap.
5. Accessibility is part of the foundation (focus-visible, keyboard, non-color cues).

## 2. Primary reference system

**GitLab Pajamas** — page header, tabs, toolbar/filter layout, quiet elevation, content hierarchy.

Reason: Vue-native ops product language close to AllTrue’s workflow UI; MIT-licensed docs/components available for *pattern* learning without adopting brand purple.

## 3. Secondary behavior references

| System | Use for | Do not use for |
|---|---|---|
| IBM Carbon | Data table anatomy, skeleton loading, focus/keyboard, status not color-only | Brand colors, IBM type, visual skin |
| Shopify Polaris | Dense admin content hierarchy (optional) | Brand chrome / marketing patterns |
| Component Gallery | Cross-system component taxonomy | Aesthetic cherry-picking |

## 4. Taste dials (ops)

| Dial | Value |
|---|---|
| Density | 7/10 |
| Decoration | 2/10 |
| Contrast | 6/10 |
| Radius | Low–medium (3 levels) |
| Elevation | Minimal (≤2 shadows) |
| Motion | Restrained + `prefers-reduced-motion` |
| Color count | Low (AllTrue amber brand + neutrals + semantic) |
| Information hierarchy | High |
| Mobile ergonomics | High |

## 5. Token definitions

Defined in `frontend/src/styles.css` (`:root`):

- Spacing: `--ds-space-1`…`--ds-space-8` (4px grid)
- Type: `--ds-font-size-*`, `--ds-line-*`, `--ds-font-weight-*`
- Radius: `--ds-radius-sm|md|lg` (+ `--ds-radius-pill` rare)
- Controls: `--ds-control-height-sm|md|lg`
- Surfaces / text: `--ds-surface-*`, `--ds-text-*`
- Elevation: `--ds-shadow-1|2` only
- Focus: `--ds-focus-ring`
- Z-index: `--ds-z-*`
- Motion: `--ds-motion-*`, `--ds-ease-standard`
- Breakpoints (reference): 390 / 768 / 1440

Brand amber/orange stays AllTrue — **not** GitLab purple or Carbon blue.

## 6. Primitive inventory

Existing + foundation set under `frontend/src/components/design-system/`:

| Component | Role |
|---|---|
| `AtPageHeader` | Title, description, meta, actions |
| `AtSection` | Quiet content region (optional border) |
| `AtToolbar` | Action row |
| `AtFilterBar` | Search/filter grid |
| `AtButton` | `shape=pill|rect`, loading, variants |
| `AtIconButton` | Accessible icon-only control |
| `AtBadge` | Status with text + tone (+ dot) |
| `AtDataTableShell` | Dense table chrome |
| `AtEmpty` | Compact empty state |
| `AtInlineAlert` | Inline error/warning/info |
| `AtSkeleton` | Loading placeholder |
| `AtModal` | Dialog shell (Escape, scroll lock) |
| `AtField` / `AtInput` / `AtSelect` / `AtTextarea` | Forms (existing) |
| `AtCard` / `AtMetric` | Existing; avoid card-wrapping every block on ops pages |

## 7. AI Slop ban list

- Purple/indigo AI gradients on ops pages
- Glassmorphism / glow borders on ops
- Every block in a heavy card + shadow
- Pill-everything (tabs, filters, every badge)
- Giant centered empty-state icon heroes
- Emoji as product icons
- Fake KPI / “歡迎回來” dashboard heroes
- Decorative illustration noise
- >1 primary CTA per region
- Color-only status meaning

## 8. Accessibility requirements

- Visible `:focus-visible` rings on interactive controls
- Icon buttons require accessible name (`aria-label`)
- Status badges include text (not color alone)
- Modals: `role="dialog"`, Escape to close, scroll lock
- Skeletons: `role="status"` + visually hidden “載入中”
- Touch targets: prefer ≥32px ops controls; critical CTAs ≥44px where already required
- Honor `prefers-reduced-motion`

## 9. Migration strategy

1. **Tokens first** (done in this foundation).
2. **Pilot pages**: 主任收件匣 (`NotificationsCenter.vue`), 學生列表 (`StudentsList.vue`).
3. Next waves (structure only, no business logic changes): TeachersList → CourseManagement chrome → Billing/Tuition tables → Attendance.
4. Keep `AtButton shape="pill"` default for backward compatibility; new ops surfaces use `shape="rect"`.
5. Do **not** install `@gitlab/ui` / Carbon packages for full-app swap (bundle + Vue API mismatch risk).

Rollback: revert the feature branch / PR; tokens and primitives are additive and page-scoped.

## 10. Source / license register

| Source | URL | License | How used |
|---|---|---|---|
| GitLab Pajamas / GitLab UI | https://design.gitlab.com/ · https://gitlab.com/gitlab-org/gitlab-services/design.gitlab.com | MIT | Pattern reference only; no vendor code copied |
| IBM Carbon | https://carbondesignsystem.com/ · https://github.com/carbon-design-system/carbon | Apache-2.0 | Interaction/a11y/table behavior reference only |
| Component Gallery | https://component.gallery/ | Site content for taxonomy | Component inventory checklist |
| Shopify Polaris | https://polaris.shopify.com/ | Polaris license (docs) | Optional content hierarchy cues |
| AllTrue RULE DS | `docs/RULE_DESIGN_SYSTEM.md` | Project | Brand color + auth exceptions |

**Decision:** Do not vendor `@gitlab/ui` or `@carbon/vue` in this pilot — implement AllTrue-owned `At*` primitives on existing Vue 3 + CSS tokens (zero new runtime UI dependency).
