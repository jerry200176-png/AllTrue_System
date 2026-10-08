# 課程查找 — action model redesign (plan)

Goal (Jerry, 2026-10-08): teachers and directors can do every course move easily: 調課, 代課, 補課, 轉課, 暫停/恢復, 續約, 加購 and 結束課程. This is a UI-structure change only: it reuses the existing modals and endpoints. Billing and calendar files are off-limits.

## 1. Evidence

### 1.1 Headless inventory

Headless inventory on the local ui-foundation build, at 1440 and 390 (same results at both widths). The fixture is one monthly course; course-level buttons only, page header excluded.

| Screen | Production today (flag `COURSE_MANAGER_V1` off) | Drawer (flag on) |
|---|---|---|
| Course row | 4 visible: 前往帳務中心, 編輯, 詳情, 更多 ▾. A session course adds 結束課程, ＋新增下一堂 / 排課, so up to 7. | 2: 前往帳務中心, 管理課程 |
| 更多 ▾ menu | 6 items in 4 sections (排課, 帳務與合約, 其他, 狀態, 危險). Up to 14 for session courses. | — |
| Drawer header + 總覽 | — | 4 tabs, 關閉 + 返回, 暫停課程 |
| 排課與堂次 | — | 備註 ▼, 月曆, 列表 (+ ＋新增下一堂 / 補登 for session courses) |
| 帳務與合約 | — | 查看帳單, 前往帳務中心 (a duplicate of the row button), 結算 / 續約下月, 合約／堂次調整 (+ 產生繳費通知, 轉多科方案預檢) |

### 1.2 Clicks per move, from the course row (production today)

| Move | Path today | Clicks | Problem |
|---|---|---|---|
| 調課 | 詳情 → date chip → 🔄 調課（換日期） | 3 | hidden behind the date list |
| 代課 | 詳情 → date chip → 換代課老師 | 3 | same |
| 補課 | 更多 → 排課/補登 | 2 | buried in the 排課 section |
| 轉課 | 編輯 → change the subject → 儲存 → error → 開啟轉課 | 4+ | **no direct entry at all** |
| 暫停 / 恢復 | 更多 → 暫停課程 | 2 | ok |
| 續約 / 加購 | 更多 → 結算 / 續約下月 | 2 | label mixes 結算 and 續約 |
| 結束課程 | row 結束課程（不再續課） | 1 | a destructive-ish action is a first-class row button |
| 刪除 | 更多 → 刪除 → confirm | 2+confirm | ok |

The drawer (flag on) does not fix this: 調課, 代課 and 轉課 are missing from it, and 結束課程 sits next to 暫停 with no grouping.

### 1.3 Research

From `enterprise-software-research`, lightweight. The browser helper was rate-limited, so the sources are help-center level; the Teachworks and My Music Staff claims are inferred. These are applied as conventions, not proof.

1. **One primary plus an overflow ⋯.** Linear issue view; Google Calendar event card (edit is the primary, ⋮ holds the rest).
2. **Group the overflow by intent, with section labels.** Linear and Notion context menus.
3. **Destructive actions go last, in red, behind a confirm with scope.** GCal "delete recurring event: this / following / all"; Linear delete.
4. **A side panel instead of page jumps.** Linear peek, Notion side peek, Teachworks lesson panel.
5. **Mobile uses a bottom sheet for the overflow,** with ≥44px targets (Apple HIG, Material bottom sheets).

Norm: a frequent edit is ≤2 clicks from the record (GCal: open event → edit, or drag).

## 2. Action model

One `courseActions(course, ctx)` pure function in `frontend/src/lib/courseActions.js` returns `{ primary, groups }`. Both the row and the drawer render from it, so they can never drift.

**Primary: exactly one, chosen by state.**
- paused → 恢復課程
- needs scheduling (planning_status) → 排下一堂 / 補登
- renewal due → 續約／加購
- otherwise → 管理課程 (opens the drawer)

**Overflow ⋯, in fixed group order:**

| Group | Items, each wired to an existing handler |
|---|---|
| 調動 | 調課 (`openSessionEditFromAction` + mode `reschedule`), 代課 (same + mode `substitute`), 補課／補登 (`openQuickAddSessionModal` / `openMonthlySessionModal`), 轉課 (`CourseTransferModal`, newly given a direct entry), 換師複製 (`duplicateCourseForTeacher`) |
| 帳務 | 續約／加購 (`openCommercialPurchaseEntry`), 合約／堂次調整, 產生繳費通知, 查看帳單, 前往帳務中心 |
| 結束 | 暫停 / 恢復, 結束課程（不再續課）, then a separator, then **刪除課程** (red, last, existing confirm) |

The primary is not repeated inside ⋯. Items a course can't use are hidden, using the same `v-if` rules as today.

## 3. Clicks after the change, from the row

| Move | Path | Clicks |
|---|---|---|
| 調課 / 代課 | ⋯ → 調課 / 代課 (opens the next upcoming session already in that mode) | 2 |
| 補課 | ⋯ → 補課 (or the primary when scheduling is needed) | 1–2 |
| 轉課 | ⋯ → 轉課 | 2 |
| 暫停 / 恢復 | ⋯ → 暫停 (恢復 is the primary when paused) | 1–2 |
| 續約 / 加購 | ⋯ → 續約／加購 (the primary when due) | 1–2 |
| 結束 / 刪除 | ⋯ → item → confirm | 2+confirm |

Every move is ≤2 clicks, and none needs a page jump.

## 4. UI

**Shared `ActionMenu.vue`.** A menu button with `aria-haspopup="menu"` and `aria-expanded`, and `role="menu"` with `menuitem` children.
- Keyboard: ↑/↓/Home/End, Esc closes and returns focus, type-ahead.
- Group labels use `role="presentation"`.
- The destructive item is last and uses the `--danger` token.
- Desktop shows a popover. At ≤640px it becomes a bottom sheet with ≥44px items and a safe-area inset. The repo's styles.css tokens are reused, with no new colours.

**Row.** Course summary, the state primary, and ⋯. That's at most 2 visible buttons. The 前往帳務中心 duplicate moves into ⋯ 帳務.

**Drawer header.** The same primary and ⋯. Tabs stay for content (總覽 / 排課與堂次 / 帳務與合約 / 課程設定). Action buttons are removed from the tab bodies where ⋯ already offers them; content-local buttons stay (月曆/列表, 備註, 查看帳期).

The mockup is at `/tmp/claude-1000/-home-jerry/b4b8b6df-875b-4fb3-8c0b-6d0f927a2f10/scratchpad/course-finder-mockup.html`.

## 5. PR slices (each <700 lines, tdd)

1. **PR1:** `courseActions.js` plus vitest (state → primary, groups, hiding rules, delete last), and `ActionMenu.vue` plus vitest (keyboard, aria, focus return, bottom-sheet class). No page wiring yet.
2. **PR2:** the production row (flag-off path) renders primary + ⋯ from the model. 調課/代課 pass a preset mode into the session-edit flow. 轉課 gets a direct entry. Update the e2e `ui-foundation-pages` course tests and the before/after screenshots (PR body only).
3. **PR3:** the drawer header uses the same model (flag-on path), and duplicate tab buttons are removed. e2e with `VITE_COURSE_MANAGER_V1=true`. Staff update card.
4. **PR4:** a11y fixes from the web-design-guidelines and critique audit (full list: scratchpad `cf-audit.md`). `npm run test:calendar` stays green throughout. The top items:
   - The CourseManager dialog has no focus trap, no initial focus and no focus restore to 管理課程 (M:118).
   - The tabs have no roving tabindex or arrow keys (M:143-157).
   - The row menu has a hidden `display:none` menuitem that stalls arrow navigation (P:6887/2749). ActionMenu replaces it.
   - There is no `:focus-visible` on the 更多 trigger or the items (P:6717).
   - The delete and pause confirms lack `role="dialog"`, Esc and a safe default focus. 換師複製 and 共用方案 still use native `confirm()`.

## 6. Decisions / stop points

- Nothing is deleted as a feature. The only removals are duplicates: the 前往帳務中心 row button, and tab buttons duplicated by ⋯.
- `COURSE_MANAGER_V1` stays as it is. Turning it on in production is a separate Founder activation decision, not part of these PRs.
- Rollback: revert the PR. There is no data or backend change.
