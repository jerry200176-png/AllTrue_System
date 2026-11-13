# Page template (staff pages)

Reference page: 今日工作台 (`frontend/src/pages/DirectorDashboard.vue`, the 今天 tab). Every staff page uses the same shell, header, cards, rows and type. Look and tokens: [`ALLTRUE_UI_FOUNDATION.md`](ALLTRUE_UI_FOUNDATION.md); order and per-page definition of done: [`UI_FOUNDATION_MIGRATION_SEQUENCING.md`](UI_FOUNDATION_MIGRATION_SEQUENCING.md). This file is the layout contract; where they disagree on layout, this file wins. Login / auth / brand first screen are out of scope (CLAUDE.md Y9).

Shared parts live in `frontend/src/components/ui/page/` (`PageShell`, `PageHeader`, `StatStrip` + `StatItem`, `SectionCard`, `StatusBadge`, class sets `dataTable`, `control`, tones). Use them; do not re-type their classes in a page.

## 1. Shell

| Rule | Value | Where |
|---|---|---|
| Max width | 1280px, centred | `PageShell` (`tw:max-w-7xl`); 今日工作台 `.director-workbench-v2` |
| Gutter | desktop: the app shell's 34px; phone: exactly 16px (shell 10px + 6px) | `PageShell`; `App.vue` `.main-content` |
| Gap between blocks | 16px | `PageShell` `tw:gap-4` |
| Bottom room | 96px on phone (tab bar + feedback button), 40px desktop | `PageShell` |
| Landmarks | the app shell owns `main`; a page adds none | sequencing, Definition of done item 7 |

## 2. Page header (`PageHeader`)

- Title: `h2`, `text-tpl-page`, bold, one line. No icon in front of the title, no label above it.
- Context line under the title, `text-tpl-caption`, secondary colour: what the numbers mean, or 分校・日期・更新時間. One line of plain words.
- Actions on the right, in this order: period stepper, icon-only refresh (`aria-label`), secondary actions, then at most one primary. On phone the action row is full width under the title.
- Sizes: page actions 48px (`control.page`, `control.pageIcon`), in-row actions 44px (`control.row`).
- Primary = the ink pill (`Button` default). The sun-yellow button is only for the one daily action (去點名). Everything else is `outline` or `ghost`.
- Tabs sit under the header, above the content they switch.

## 3. Summary strip (`StatStrip` + `StatItem`)

- One card split by hairlines, not a row of separate cards. Label (`text-tpl-caption`, secondary) over value (`text-tpl-page`, bold, tabular) with an optional unit and one sub line.
- At most 5 figures. Phone: two per row; give a wide figure `tw:max-md:col-span-2`, hide a minor one with `tw:max-md:hidden`.
- Colour only where it is a state: owed / overdue = danger, collected = success. Counts stay ink.
- The big KPI cards with a gradient icon tile (`.tpl-tile`, `text-tpl-num-sm`) belong to home pages (今日工作台, 老師首頁, 家長首頁) only.

## 4. Section card (`SectionCard`)

- Surface: `surface.card` (the `.tpl-card` glass fill and hairline, 24px radius, `shadow-tpl-card`; tokens in `frontend/src/ui.css`). No card inside a card; group inside a card with hairlines.
- Title row: `h3` `text-tpl-list` bold with the count in （）; section actions and filters on the right. Body runs edge to edge; footer holds totals, paging and export.
- Selection: when rows are picked, a bar at the top of the card shows 已選 N 筆 + the batch action + 取消選取 (`--ds-primary-wash`).

## 5. List or table

| Use | When |
|---|---|
| List rows (今日工作台 task list) | one thing to do per row, 3 facts or fewer; work queues, alerts, settings |
| Table (`components/ui/table` + `dataTable`) | people compare numbers down a column, or 4+ facts per row; reports, payroll, bills |

- List row: at least 72px (`tw:min-h-18`), 20px side padding (16px phone), hairline between rows, `text-tpl-body` semibold title, `text-tpl-caption` secondary line, chevron or one 44px action at the right.
- Table header: 44px, `text-tpl-caption` semibold, secondary colour, soft fill (`dataTable.head`). Rows: at least 56px (64px with a second line), hairline between rows, hover fill only.
- Search and filter chips sit above the card they filter; chips are 44px pills with their count, the active one is the ink pill.

### Table and name-cell rules

1. A person's name never wraps and never breaks inside the name: `dataTable.name` (`whitespace-nowrap`, semibold). Never `overflow-wrap:anywhere` or a fixed narrow width on a name column. The name column takes the width of the longest name (allow 5 CJK characters).
2. The second line in a cell (`dataTable.sub`: subject, teacher, period) may wrap; split it into `inline-block whitespace-nowrap` pieces so it breaks between facts, not inside one.
3. Numbers: right-aligned, `tabular-nums`, no wrap, header right-aligned too (`dataTable.num`, `dataTable.numHead`). Money keeps one format per column.
4. First column is the identity (name). Leading edges line up: every cell and its header share the same left padding; first / last cells use 20px to match the card title.
5. Checkbox column, when a page has selection: first column, 48px wide, 20px box, `aria-label` 選取 + name; header checkbox selects all and shows the mixed state.
6. One status badge per row (`StatusBadge`: dot + words on a wash). Other categories (班型, 科目) are plain text. Dates are plain tabular text; an empty value is 「—」 in the secondary colour.
7. Row actions: last column, no header text (`sr-only`), one visible 44px button plus an overflow menu.
8. Totals row: `tfoot`, `dataTable.total`; each total sits under its own column, 合計 under the name column, columns without a total stay empty.
9. Phone (under 768px): keep name, the main number and the status; hide the rest with `dataTable.phoneHide` and repeat what matters as the second line of the name cell (`dataTable.phoneOnly`). The body never scrolls sideways. A row action becomes a chevron and the row opens the detail.
10. More than 50 rows: page (footer shows 第 x / y 頁（共 n 筆）); hide the pager when there is one page.

## 6. Type, spacing, colour

| Token (`ui.css`) | Use | 標準 | 稍大 | 大 |
|---|---|---|---|---|
| `text-tpl-caption` | context line, table header, second line, badge | 15 | 16 | 17 |
| `text-tpl-body` | body, cells, buttons | 17 | 18 | 19 |
| `text-tpl-list` | section title | 20 | 21 | 22 |
| `text-tpl-unit` | unit beside a number, phone stat value | 22 | 23 | 24 |
| `text-tpl-page` | page title, stat value | 28 | 31 | 34 |
| `text-tpl-num-sm` / `text-tpl-num` | home-page KPI numbers only | 44 / 64 | 48 / 72 | 52 / 80 |

- Sizes come from these tokens only; no per-component font size, so 字級 (`useTextSize.js`, `html[data-text-size]`) moves the whole page.
- Spacing steps: 4, 8, 12, 16, 20, 24. Card padding 20; card radius 24 (`rounded-3xl`); controls and badges are pills.
- Status tones (`tones.js`): success = 已收 / 完成; warning = 待確認 / 需核對 / 部分; danger = 未收 / 逾期; neutral = the rest. Red is only danger; gold and amber are brand, not status.
- Numbers that change or line up use `tabular-nums`. Headings use `text-balance`, sentences `text-pretty`.

## 7. States, phone, keyboard

- Loading: `Skeleton` rows the height of real rows, inside the same card; never a spinner in the middle.
- Empty: inside the card, one icon, one sentence, the next step if there is one. Error: `Alert tone="danger"` with 再試一次; keep stale data visible if there is any.
- Phone: one column; primary action visible without scrolling at 375; nothing hidden behind the tab bar.
- Focus: every control shows the `ring` focus style; tab order follows reading order; icon-only buttons have `aria-label`; state is never colour alone.

## 8. Per-page checklist (implementer and reviewer both run it)

- [ ] Built from `components/ui/page` parts; no page-level font size, radius, shadow or colour; no `design-system/At*` import.
- [ ] One `h2`, one context line, at most one primary action; wording unchanged from the old page.
- [ ] Every name on one line at 375 and 1536, at 標準, 稍大 and 大.
- [ ] Numeric columns right-aligned and tabular; totals under their columns; one status badge per row.
- [ ] No sideways scroll of the body at 375; phone gutter 16px; controls at least 44px.
- [ ] Loading, empty and error states exist and sit inside the card.
- [ ] Design skills run in this order and named in the PR: `impeccable`, `make-interfaces-feel-better`, `web-design-guidelines`, `ui-ux-pro-max` for tables, forms and charts.
- [ ] Sequencing §7 fixed items still pass (page guide selectors, landmarks, old scoped style removed).

## 9. Evidence every UI PR attaches

This section is the one evidence rule for UI PRs; `UI_FOUNDATION_MIGRATION_SEQUENCING.md` points here.

1. Real-browser screenshots (Playwright, mocked API, realistic names including a 4-character one) at 1536×784 and 375×800, at 標準, 稍大 and 大, before and after, listed in the PR description as file paths or CI artifacts.
2. The page has an entry in `frontend/e2e/ui-foundation-layout-guard.spec.js` (`PAGES`) and it passes: names on one line, no horizontal body overflow, row leading edges aligned. It runs in the required ui-foundation job.
3. Vitest + axe for the page; the `ui-preview` link for the reviewer.

## 10. References (what we took from where)

Public docs and open-source code only. Adapt patterns; copy code only from MIT / Apache sources on the same stack, with a header naming the source.

| Source (licence) | URL | Taken |
|---|---|---|
| shadcn-vue blocks, `dashboard-01` (MIT) | https://www.shadcn-vue.com/blocks | page stack (cards, then table); toolbar row above the table; soft sticky header fill; footer with selected count and paging |
| shadcn/ui table + data-table docs (MIT) | https://ui.shadcn.com/docs/components/data-table | semantic `table` parts; checkbox column with select-all and mixed state |
| Tremor KPI cards (Apache-2.0) | https://blocks.tremor.so/blocks/kpi-cards | small muted label over a semibold figure, comparison line under it, `dl` markup |
| GitHub Primer DataTable (MIT) | https://primer.style/product/components/data-table/guidelines/ | numbers right + tabular; row header first; wrap before truncate; actions column without a header; skeleton matches rows; title / subtitle / actions container |
| Shopify Polaris index table (docs) | https://shopify.dev/docs/api/app-home/patterns/compositions/index-table | bulk bar replaces the filter row when rows are picked; primary / secondary slots decide what a phone row keeps; status badge tones |
| Ant Design data list spec (MIT) | https://ant.design/docs/spec/data-list | table vs list vs card choice; filter updates as you pick; hide the pager on one page; batch toolbar on check |
| Vben Admin grid page (MIT) | https://doc.vben.pro/en/components/common-ui/vben-vxe-table.html | order on a list page: search, title + tools, grid, pager |
| Linear UI redesign notes (article) | https://linear.app/now/how-we-redesigned-the-linear-ui | align labels, icons and buttons on shared edges; less chrome; accent colour kept for state |
| Vendored skills | `.claude/skills/impeccable`, `make-interfaces-feel-better`, `web-design-guidelines`, `ui-ux-pro-max` | no label above a heading, no card in card, no side-stripe; shadow for depth, hairline for structure; tabular numbers; 44px targets; table-to-list on phone |
