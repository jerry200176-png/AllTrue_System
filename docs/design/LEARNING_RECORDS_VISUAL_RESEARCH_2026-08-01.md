---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-01
---

# 學習評量表視覺與 UX 研究

這是 Learning Records 改版前的 audit，不是另一套 design system。品牌、token、可及性與 ops 密度仍以 [`RULE_DESIGN_SYSTEM.md`](../RULE_DESIGN_SYSTEM.md)、[`ALLTRUE_UI_FOUNDATION.md`](./ALLTRUE_UI_FOUNDATION.md) 與 [`GUIDE_UI_COPY.md`](../GUIDE_UI_COPY.md) 為準。

## Current-state evidence

正式站 2026-08-01 驗收顯示，主任角色的學習評量表目前有以下問題：

1. 頁首、角色／留言 tab、狀態 tab、快捷 filter、篩選條件、顯示模式連續堆疊，第一眼無法回答「我現在要審哪一筆」。
2. 大量 pill tabs 與 card wrapper 讓所有控制看起來同等重要；`待審佇列`、`需修改追蹤`、`已核准`、`已退回`、`全部` 缺少清楚的任務優先級。
3. 篩選區與結果區之間有過多垂直空白，實際學生資料被推到第一視窗以下。
4. 目前列表同時承擔學生群組、科目、狀態、留言、填寫狀態與多個操作，資料層級需要重新編排，而不是單純縮字或加顏色。
5. teacher entry、director review、parent feedback 是不同工作；目前共享太多表面結構，造成角色 UX 混在一起。

## First-hand reference decisions

| Reference | Source checked | Decision for AllTrue |
|---|---|---|
| `GibbonEdu/core` (starred) | `42b1d2b`; `modules/Markbook/markbook_view.php`, `modules/Markbook/css/module.css` | Assessment data needs explicit class/student grouping and a dense, scan-friendly table; role-specific entry/review views should not be collapsed into one generic card feed. |
| `filamentphp/filament` (starred) | `38eb676`; page and table templates | Separate page header, state tabs, filters, table toolbar, records, and row actions. Each level gets one job. |
| `chatwoot/chatwoot` (starred) | `bc7ae88`; Tabs and tab-item components | Use one text-tab navigation with an active underline and compact count badges; no stacked pill navigation for primary state. |
| `vbenjs/vue-vben-admin` (starred) | `9f5b1cd`; dashboard workspace composition | Keep a deliberate main work area and small utility rail; do not turn every number into a tile. |
| IBM Carbon / GitLab Pajamas | Existing AllTrue foundation evidence | Preserve keyboard focus, loading/empty/error states, predictable table anatomy, and quiet elevation. |

## Proposed product shape

### Director

- Page header: `學習評量表` + scope + one export action.
- One primary state navigation: `待審核 / 需修改 / 已核准 / 全部`, with counts.
- One compact filter toolbar: search student, date, teacher/subject, and one `更多篩選` disclosure.
- Result header states exactly what is shown: `27 筆待審核評量` plus batch mode as an explicit action.
- Records are grouped by student, but the first row exposes date, subject, teacher, completion state, parent feedback, and one dominant review action.
- Review actions become a clear right-side action cluster on desktop and a bottom action row on mobile.

### Teacher

- Default entry is the schedule: today/week switch and a clear `填寫評量` action per lesson.
- Drafts remain an explicit utility, not a second navigation system.
- Record history is secondary and uses the same status language as the director view.

### Parent feedback

- Remains a separate top-level mode, not a filter mixed into assessment status.
- Unread and awaiting-reply are state shortcuts within that mode.

## Acceptance before release

- No more than one primary status tab row above the results for director view.
- The first viewport exposes the active queue and first student record at 390, 412, 768, 1280, and 1440px.
- No unexpected horizontal overflow; table/list actions remain reachable on mobile.
- Verify director batch approve, request changes, reject, row review, parent feedback preview, filters, reset, export, and teacher schedule entry.
- Capture normal, loading, empty, error, long-name, dense-data, and active-filter screenshots from the real Vue page.
- Deploy only after CI and authenticated desktop/mobile acceptance on the production commit.
