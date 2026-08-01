---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-01
---

# 主任總覽視覺研究與決策紀錄

這份紀錄補上本次 dashboard 重做缺少的證據鏈：參考 repo 必須實際 checkout、閱讀元件／規則，再把決策落回 AllTrue 現有 token 與真實工作流程。它不是新的設計系統；品牌與 token 仍以 [`RULE_DESIGN_SYSTEM.md`](../RULE_DESIGN_SYSTEM.md) 和 [`ALLTRUE_UI_FOUNDATION.md`](./ALLTRUE_UI_FOUNDATION.md) 為準。

## Design read

這是給主任使用的 authenticated B2B operations surface。使用情境是每天快速掃描、判斷責任、立即處理案件；視覺語氣採 restrained enterprise product：低裝飾、清楚的字級階層、單一品牌 action 色、列表優先而不是 KPI 牆。

本頁 dials：`variance 3 / motion 1 / density 5`。美感來自比例、留白、排版與一致的控制元件，不使用 marketing hero、漸層、glow 或裝飾性狀態點。

## First-hand references

| Reference | Checked revision / files | Observation | AllTrue decision |
|---|---|---|---|
| GitLab Pajamas | `ca6e1a4`; `components/page_header.vue`, `packages/gitlab-ui/src/components/dashboards/dashboard_layout/dashboard_layout.vue`, `dashboard_panel.vue`, `regions/empty_state/empty_state.vue` | Page header is compact and task-oriented. Dashboard panels have one title/action area and bounded bodies; empty state is a composed region, not a decorative hero. | One page header, one view switch, bounded regions, composed loading/empty states. |
| Leonxlnx/taste-skill | `e988add`; `skills/taste-skill/SKILL.md`, `skills/redesign-skill/SKILL.md` | The brief and audience come before styling. Product surfaces reject AI-purple defaults, repeated decorative dots, generic card grids, and unbounded dense feeds. | Director work is a product surface: no hero, no per-student feed in the primary queue, one accent, real copy and evidence before ship. |
| pbakaus/impeccable | `c5e1ddd`; `plugin/skills/impeccable/reference/operate.md`, `craft-floor.md`, `DESIGN.md` | Operate mode values familiarity, restrained color, standard controls, skeleton/error/empty states, and screenshot-based bounded QA. Cards exist only when hierarchy needs them; nested cards are a failure mode. | Keep the existing shell and tokens, use flat bordered regions, reserve filled amber for decisions, and verify rendered desktop/mobile evidence. |
| shadcn/ui | `cb2bcd8`; `apps/v4/app/(app)/(root)/cards/*`, registry and component source | Components are intentionally owned in the product and composed from simple primitives; the system is not a reason to copy a default visual skin. | No new runtime dependency. Reuse AllTrue-owned Vue primitives and change composition, not the product stack. |

### Founder-star audit added during visual polish (2026-08-01)

The founder's current GitHub starred repositories were queried before this polish pass. The following repos were checked out at the listed revisions and their relevant source was read:

| Starred reference | Checked revision / files | Observation | AllTrue decision |
|---|---|---|---|
| `vbenjs/vue-vben-admin` | `9f5b1cd`; `apps/web-antd/src/views/dashboard/workspace/index.vue` | The workspace uses a quiet header, one dominant work area, and a deliberate secondary column; quick navigation and todo content are grouped by job, not repeated as KPI tiles. | Keep the dashboard's queue + summary split, but give the primary region a clearer visual anchor and row rhythm. |
| `filamentphp/filament` | `38eb676`; `packages/panels/resources/views/components/page/index.blade.php`, `packages/tables/resources/views/index.blade.php` | Page header, table heading/actions, filters, toolbar, and records are separate responsibilities with predictable spacing. | Do not make every control another card; keep the page header, task queue, and secondary operations as distinct levels. |
| `chatwoot/chatwoot` | `bc7ae88`; `app/javascript/dashboard/components/ui/Tabs/Tabs.vue`, `TabsItem.vue` | Tabs are a simple text row with one active underline and optional count badges; overflow is handled by the tab system rather than wrapping into a pill wall. | Use text tabs for top-level views and reserve count badges for actual state, not decoration. |
| `GibbonEdu/core` | `42b1d2b`; `modules/Markbook/markbook_view.php`, `modules/Markbook/css/module.css` | The education workflow separates role-specific views and treats dense assessment data as a structured table, with explicit grouping and editable columns. | The upcoming Learning Records pass will separate teacher entry, director review, and parent feedback states before styling the table. |

The audit confirms the current dashboard's remaining weakness is composition polish, not missing information: the production page has the right queue but too little surface contrast, task-row rhythm, and loading structure. The next change therefore stays within AllTrue tokens and adds hierarchy rather than more metrics or decoration.

## Decisions made from the audit

1. The primary view contains one row per director decision. Adoption/task-tracker rows stay in the secondary view and cannot expand the daily queue into dozens of student rows.
2. The daily view has one navigation control. Snapshot numbers sit in one quiet summary panel and do not repeat the action queue.
3. Trust is a disclosure row in the side rail. It can explain risk without becoming a second decision center above the queue.
4. Full operations is a separate view with the real workflow surfaces: schedule, parent leave, evaluation review, tuition, schedule reports, notifications, and a collapsed analysis section.
5. Primary action styling is reserved for a decision that changes state. Ordinary navigation uses text actions to prevent an orange button wall.
6. Mobile is a structural single column. Every decision action remains in the row; no horizontal scroll or hidden CTA is allowed.

## Evidence required before release

- Render the real Vue page with normal, empty, loading, error, and urgent data.
- Capture focus and full views at 390, 412, 768, 1280, and 1440 CSS pixels.
- Check `scrollWidth <= clientWidth`, the daily view has no legacy `.workbench` node, and the primary queue excludes `source === adoption` rows.
- Click the parent-leave CTA and verify the full view lands on `exception-workflows-sec` with “尋找補課時段” reachable.
- Re-check desktop and mobile production after deployment; CI green alone is not visual acceptance.
