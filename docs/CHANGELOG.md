# AllTrue Changelog

> 格式：每條一行，分類 Added / Fixed / Changed / Security / Ops  
> 細節查 PR 說明或 `.cursor/plans/`  
> **版本公告（給老師／主任看的短卡）**：同一版建議 **第一條寫使用者白話**；技術細節請另起一行並以 **`開發備註：`** 開頭（`npm run sync-release-notes` 會略過不進 `releaseNotes.generated.js`）。  
> **閱讀**：依日期標題搜尋；**勿逐行通讀**。
>
> **滾動歸檔策略**（對齊 Keep a Changelog / 大型 repo 慣例）：主檔只保留**當月**，月初把上月移入 `archive/`。更早紀錄：
> - 2026-05：[`archive/CHANGELOG_ARCHIVE_2026-05.md`](archive/CHANGELOG_ARCHIVE_2026-05.md)
> - 2026-04（含更早）：[`archive/CHANGELOG_ARCHIVE_2026-04.md`](archive/CHANGELOG_ARCHIVE_2026-04.md)

---

## 2026-06-07 — feat(calendar): SmartCalendar composables 剝離完成（#740 Step 7）

- `useCalendarDataLoad` / `useCalendarLeaveExtra` / `useCalendarSubstitute` / `useCalendarReschedule`
- `SmartCalendar.vue` **5260 → 3308** 行；拖曳調課 handler 仍留父層
- P4-b：`GET /api/v1/student-classes` 支援 `start`/`end` 視窗過濾 + 前端傳參
- 測試：`npm run test:calendar` 全綠（含 4 組 composable vitest）

開發備註：PR #773/#777/#778/#782/#787/#789；行數 <3000 留作 Step 7c（course-edit composable）後續。

## 2026-06-07 — perf(calendar): loadCourses 平行化 student-classes ∥ schedules（#740 P4-a）

班級行事曆冷載時，課程清單與排程例外改為同時抓取，縮短等待時間；顯示結果與合併邏輯不變。

開發備註：新增 `calendarCourseLoad.js`（`fetchCalendarCoursesAndSchedulesParallel`）；`class-sessions` 仍串行（依賴 course ids）。理論節省 ≈ schedules 端點延遲（實測見 TD-062）。`test:calendar` +9 cases。Refs #740。

## 2026-06-07 — refactor(calendar): SmartCalendar Modals 群拆分（#740 Step 6）

班級行事曆五個 inline modal 剝離為獨立 presentational 元件，單堂檢視 modal 移除死碼分支，行數再降 661 行。

開發備註：`CalendarSessionEditModal` / `CalendarLeaveModal` / `CalendarRescheduleModal` / `CalendarSubstituteLegacyModal` / `CalendarExtraLessonModal` + `calendarModalRwd.css`。父層保留 form state 與 submit API。`SmartCalendar.vue` 4845→4184。`test:unit` 56 passed。技術文件 → `GUIDE_SMARTCALENDAR_REFACTOR.md` §4.6。Refs #740。

## 2026-06-07 — refactor(calendar): SmartCalendar 受控拆分暫時收尾（#740 Phase 4c）

班級行事曆大檔案完成第一階段受控拆分：純工具與五個 UI 葉子元件剝離，課程卡 CSS 祖先耦合改為 prop 驅動，視覺驗收通過；Modals 與效能平行化延後。

開發備註：`SmartCalendar.vue` 5260→4845 行（−415）。剝離 `lib/calendarDateUtils|calendarFormat|teacherColor` + `components/calendar/{TeacherColumnHeader,DayTabsBar,WeekTeacherChips,WeekNavBar,CourseBlockContent}`；`CourseBlockContent` 3 props（course/badges/layout）解耦 `:has()`/compact/容量徽章。PR #751–#757 全綠部署。技術文件 → `docs/GUIDE_SMARTCALENDAR_REFACTOR.md`。Modals、P4-a/b 仍 open，#740 暫不收案。

## 2026-06-07 — ops(rollback): 回滾就緒度檢查 + Rollback Runbook（#733）

新增「回滾就緒度」自動檢查與標準作業程序文件，確保萬一某次更新出問題時，系統能用最短時間、最小破壞地恢復到前一個正常版本。

開發備註：新增 `scripts/rollback-readiness.sh`（4 項非破壞性檢查：deploy.yml 自動回滾區塊完整、全 migration 有 down()、最新 commit 可乾淨 git revert、DB 備份還原 workflow 存在）+ `rollback-readiness.yml`（月排程 / 手動 / 改 deploy.yml 或 migration 的 PR 觸發）+ `docs/RUNBOOK_ROLLBACK.md`（含自動/手動回滾 SOP、DB 回滾、MTTR 量測）。零 production 風險。Refs #733。

## 2026-06-07 — test(frontend): 導入 Vitest 元件測試基礎建設（#729）

新增前端元件自動化測試護欄，未來改動共用 UI 元件若破壞行為，CI 會在合併前擋下，降低介面回歸風險。

開發備註：導入 `vitest` + `@vue/test-utils` + `jsdom`。新增 `vitest.config.js`（範圍限 `components/**/__tests__`，與 `src/lib/*.test.js` 純函式測試分離）、4 個 design-system 元件測試（AtButton/AtCard/AtEmpty/AtMetric，共 18 cases）、`npm run test:unit` script，並以 blocking step 納入 `ci.yml` 的 `vite-build` job。Closes #729。

## 2026-06-06 — fix(learning): 學習評量表日期排序修正（in-app #155）

學習評量表不再把「已核准但內容空白」的舊評量頂到最上面；需要填寫的優先顯示，已核准的依上課日期由新到舊排列，日期不再看起來亂。

開發備註：根因為 `LearningRecordsPage.vue` `sortRecords` 的 `missingBodyTier` 把 approved-empty 設 tier 0 置頂。抽出純函式 `lib/learningRecordSort.js`（approved/rejected/其他→tier1 依日期；僅 pending/changes_requested 未填→tier0）+ 單元測試 `learningRecordSort.test.js`（含 bug 端到端情境）；`sortRecords` 改呼叫 lib。篩選（「只看未填」toggle／分頁）不受影響。Closes #742。

## 2026-06-06 — feat(ui): 老師工作台 token 對齊 + dark mode 整併（#699 step 1）

開發備註：#699 Wave 1 補完三頁第一步（TeacherHomePage.vue）。raw hex 48 → 9，降 81.25%（AC ≥80%）。批次處理：(1) 移除 `var(--primary, #1976d2)` / `var(--ds-primary, #EF6C00)` / `var(--ds-primary-deep, #E65100)` / `var(--ds-primary-wash, #fff8e1)` fallback hex（13 處）— 全域已定義；(2) `#475569`/`#0f172a`/`#64748b`/`#334155` slate-tone → `--ds-ink-{secondary,,mute,secondary}`；(3) `#f8fafc` feedback-metric 底色 → `--ds-canvas-soft`；(4) `color: #fff` on primary/accent bg → `--ds-on-primary`（5 處：badge、day-tag、branch-chip、fill-btn hover、chat-btn）；(5) clockin-card hover / icon-empty `var(--bg-hover, #f5f5f5)` / `var(--bg, #f5f5f5)` / `var(--card-bg, #fff)` legacy fallback → DS token；(6) `.th-ckin-late` `#c62828` → `--ds-danger`；(7) `.th-icon-late`/`.th-badge-late` `#fce8e6`/`#c62828` → `--ds-danger-wash`/`--ds-danger`，並**移除 4 條 dark mode override（`#3b0c0c`/`#ef9a9a`/`#424242`/`#bdbdbd`/`#3b2612`/`#ffb74d` 系列）**——ds token 已自適應；(8) `.th-report-btn` red `#fef2f2`/`#ef4444`/`#fee2e2` → `--ds-danger-*`（active hover 改 filter brightness）；(9) `.th-form-substituted` `#e0e0e0`/`#757575` → `--ds-canvas-soft`/`--ds-ink-mute`。**保留 raw**：`.th-action-learning` 藍（`#e3f2fd`/`#1565c0`，多態語意色）、`.th-form-leave`/`.th-event-leave` 暖橘（`#fff7ed`/`#c2410c`/`#f97316`，請假狀態需與 warning 區別）、`color-mix(... #ffffff)` tint blend（4 處，tint 基色語法需求）。`npm run build` 通過。DirectorDashboard 與 LearningRecords 屬後續 step。

## 2026-06-06 — chore(docs): 文件治理向大公司看齊（INDEX 去重 / 過時修正 / CHANGELOG 滾動歸檔 / size gate）

文件庫整理：去重與修正過時描述讓 AI 更快找對資料、CHANGELOG 滾動歸檔省 token、補文件保鮮 metadata。

開發備註：分兩個 PR、於隔離 git worktree 進行（避免與並行 #692 working-tree race）。PR-A：presubmit CHECK 2 對 `chore/docs-*` 排除 CHANGELOG/archive 搬移於 size 計算；INDEX 合併重複命名 prefix 段 + 補 `ADR_`、設計摘要 navy+indigo→navy+品牌橘黃；`RULE_DESIGN_SYSTEM` 標題去 Stripe-Inspired + Badge/Forbidden indigo→info/品牌橘黃；`RULE_DESIGN_SYSTEM`/`PRICING_CONTRACT`/`ROLE_PLAYBOOK` 補 front matter 並納入 docs-integrity STALE_CHECK；APPROVED_PREFIXES += `ADR_`。PR-B（本次）：CHANGELOG 滾動歸檔——主檔只留當月，2026-05（162 條）移入新 `archive/CHANGELOG_ARCHIVE_2026-05.md`、2026-04（114 條）append 進既有 04 archive（零丟失，補回 archive 缺的 04-25~04-30），主檔頂部加 archive 導航。對齊 Keep a Changelog。

## 2026-06-06 — feat(ui): 學生管理表單 / 包套 / 歷史 / LINE / Toast token 對齊（#692 wave C）

開發備註：#692 StudentsList Wave 2-2 第三階段（表單 + package + history + LINE + toast + dark mode 整併）。**完成 #692 AC：raw hex 143 → 28，降 80.4%**。`.form-section-title`/`.rfid-bind-row input`/`.required` legacy var + `#ddd`/`#f5f5f5`/`#333` → `--ds-primary`/`--ds-hairline`/`--ds-hairline-input`/`--ds-canvas-soft`/`--ds-ink`/`--ds-danger`。`.cost-preview` 漸層 `#FFF8E1→#FFECB3` + border `#FFE082` → 實色 `--ds-primary-wash` + `--ds-hairline-input`；`-label` `#5D4037`、`-value` `var(--primary)`、`-formula` → `--ds-ink-secondary`/`--ds-primary`/`--ds-ink-mute` 並補 `tabular-nums`。`.tag-paused-sm`/`.tag-expiring` 全部 hex → `--ds-warning-{wash,}`；`.btn-renew-warn` `#ff9800`/`#fff`/`#e65100` → `--ds-warning`+`--ds-on-primary`，hover 用 `filter: brightness(0.92)` 取代第二個 hex。**保留 `.tag-package` 紫色（套餐多態語意色，無 ds token）**。`.sl-empty-active`/`.sl-history-*` 共 25 個 slate-tone hex → `--ds-ink-{mute,secondary}`/`--ds-hairline{,-input}`/`--ds-canvas-soft`/`--ds-shadow-1`；`.sl-tag-history--settled` 綠 → `--ds-success-*`；**保留 `--completed` 藍（無 ds token）**。`.line-bound-badge`/`.line-binding-id` 維持 **LINE 官方 `#06C755`**（third-party brand 不可換 token）；周邊 layout `#f5f5f5`/`#9e9e9e`/`#757575`/`#ef5350`/`#fff` → `--ds-canvas-soft`/`--ds-ink-mute`/`--ds-danger`/`--ds-on-primary`。`.toast-notification` `#323232`/`#fff` + 硬編陰影 → `--ds-ink`/`--ds-on-primary`/`--ds-shadow-2`。**Dark mode 區大幅整併**：12 條 `[data-theme="dark"]` override 拿掉 11 條（ds token 已自適應 dark），僅保留 `.sl-tag-history--completed`（藍多態無 token）。Template inline color：rfid-unbound icon `#bdbdbd`、invoice modal subtitle/loading/empty/due-date hint `#666`/`#aaa`/`#888`、sessions-near-empty 與 package-hint `#e65100`/`#7a4b00`、duplicate-course-heading `#e65100` 全部抽出為 scoped class（`--ds-ink-mute`/`--ds-warning`）。移除 §7 禁止的 emoji 狀態圖示：「💰 加購堂數」「🎓 年級升級」「⚠️ 此學生已有進行中的課程」 → 純文字。`npm run build` 通過。

## 2026-06-06 — feat(ui): 學生管理列表 / 狀態 chip / 課程展開區 token 對齊（#692 wave B）

開發備註：#692 StudentsList Wave 2-2 第二階段（列表 + 狀態 + 課程展開）。`.student-row` hover/expanded `#FFF8E1`/`#FFF3E0` → `--ds-primary-wash`；border-bottom `var(--accent)` → `var(--ds-primary)`；`.student-select-checkbox` accent → `--ds-primary`。狀態左邊框：active `#43a047` → `--ds-success`、paused `#e65100` → `--ds-warning`；**graduated `#1565c0` 藍、transferred `#7b1fa2` 紫無對應 ds semantic token，維持 raw 待 token 擴充**（同 #691 wave C 原則）。`.student-avatar-mini`：base 漸層 `#43a047→#66bb6a` 改實色 `--ds-success`、paused 漸層改 `--ds-warning`、graduated/transferred 漸層 → 實色 raw；`color: #fff` → `--ds-on-primary`。`.subject-pill` `#E8F5E9`/`#2E7D32` → `--ds-success-wash`/`--ds-success`；`.low` `#FFEBEE`/`#C62828` → `--ds-danger-wash`/`--ds-danger`。`.note-icon` `#ffab00` → `--ds-warning`。`.student-status-badge.paused` → `--ds-warning-*`（graduated/transferred 同上保留）。`.rfid-tag` `var(--primary)` → `var(--ds-primary)`；`.rfid-unbound` `#bdbdbd` → `--ds-ink-mute`。`.mini-progress` `#e8e8e8` → `--ds-hairline`。`.day-chip` 5 個 hex → `--ds-hairline`/`--ds-canvas-soft`/`--ds-ink-secondary`/hover `--ds-primary`+`--ds-primary-wash`/selected `--ds-primary-deep`+`--ds-primary`+`--ds-on-primary`。`.course-detail-row` `#FAFAFA` → `--ds-canvas-soft`；`.course-panel` border `var(--accent)` → `--ds-primary`；`.course-panel-header h4` `var(--primary)` → `--ds-primary`。`.student-note-line`/`.course-memo-line` `#64748b` → `--ds-ink-mute`。`.course-inner-table` `#F0F0F0`/`#EEEEEE` → `--ds-canvas-soft`/`--ds-hairline`。`.status-tag.one_on_one` → `--ds-primary-wash`+`--ds-primary-deep`、`.tutoring` → `--ds-success-*`（1on2/1on3/trial 多態語意色保留 raw）。raw hex 129 → 98。表單 / package tag / history / LINE / toast 屬 wave C。`npm run build` 通過。

## 2026-06-06 — feat(ui): 學生管理頁首+篩選列+批次工具列 token 對齊（#692 wave A）

開發備註：#692 StudentsList Wave 2-2 第一階段（header + filter + bulk + 共用 chip）。`.close-btn`/`.paid-date-hint`/`.invoice-status-chip.{paid,unpaid,partial}`/`.invoice-skeleton` 原 raw hex 改 `--ds-{success,warning,primary}-wash` + 對應 ink；`.header-icon` `var(--primary)` → `var(--ds-primary)`；`.stat-badge` `#FFF3E0`/`#E65100` → `--ds-primary-wash`/`--ds-primary-deep` 並補 `tabular-nums`；`.stat-badge-light` `#f5f5f5`/`#78909c` → `--ds-canvas-soft`/`--ds-ink-mute`；`.button-outline` legacy var → `--ds-canvas`/`--ds-hairline` 並對齊 secondary 按鈕語意；`.bulk-toolbar` `#E3F2FD`/`#90CAF9`（藍 info）→ `--ds-primary-wash`/`--ds-hairline-input`（品牌橘 wash）；`.filter-bar`/`.search-icon` legacy + `#bdbdbd` → `--ds-hairline`/`--ds-ink-mute`。Body/列表狀態色/RFID/課程展開區屬 wave B，modal/表單/package/history/LINE 屬 wave C。raw hex 143 → 129。`npm run build` 通過。

## 2026-06-06 — refactor(identity): runtime 移除 Teacher table 依賴，改以 User/UserCampus 為老師權威來源

開發備註：Phase 2。老師資料 runtime 改以 `User`（姓名、電話、LineID）與 `UserCampus`（分校、RFID）為權威來源；`Teacher.RFID` 已由 `UserCampus.RFID` 完全取代。更新老師建帳/更新/刪除、RFID 刷卡、老師打卡、LINE 通知、課程/評量/財務/出勤查詢與合併工具，不再 join/write `Teacher` table。`TeacherSingIn.TeacherID`、`StudentClass.TeacherID`、`StudentSingIn.TeacherID`、`schedules.teacher_id` 語意維持 `User.id`。新增 migration 將 legacy `Teacher` 的 phone/LineID/CampusID/RFID 補回 `User`/`UserCampus`，`down()` 不刪 live data。測試 fixture 同步移除 `Teacher` table 假設；本機 PHP 不可用且依使用者指示改由 GitHub Actions 執行測試。

## 2026-06-06 — feat(ui): 課程 modal 中性結構色 token 化（#691 第三階段）
## 2026-06-06 — feat(ui): App 外殼去裝飾、品牌色統一（#698 topbar/FAB/banner）

全站共用外殼的視覺收斂：頭像、說明浮動鈕、系統更新提示列從多色漸層統一為單一品牌色，與設計系統一致。

開發備註：#698 App shell chrome 去裝飾。`App.vue` `<style>`：(1) `.update-banner` 藍漸層（`#0ea5e9→#2563eb`）→ `--ds-primary` 實底 + `--ds-shadow-1`；按鈕改 `--ds-canvas`/`--ds-primary-deep`/hover `--ds-primary-wash`。(2) `.account-avatar` 橘漸層（`#f97316→#fb923c`）→ `--ds-primary` 實色。(3) `.global-guide-btn`（說明 FAB）橘漸層（`#ff9800→#ff6f00`）→ `--ds-primary` + `--ds-shadow-2`。(4) `.account-role`/`.account-menu-chevron` → `--ds-ink-mute`；`.account-menu-btn-danger` → `--ds-danger`/`--ds-danger-wash`。登入頁品牌 hero radial 光暈屬品牌動畫，依設計系統保留。`npm run build` 通過。



課程相關彈窗（堂次編輯、續約月結）的容器底色、標題、輸入框邊框等中性樣式統一對齊設計系統；出缺勤狀態色、計費比較色等「功能語意色」維持不變（屬設計 token 擴充議題，另議）。

開發備註：#691 reference page 治理第三階段（modal 群中性結構）。`SessionEditModal.vue`：`.session-edit-info` 底色、`.se-label`/`.se-section-title`/`.se-sub-hint`/`.se-loading`/`.field-note`/`.se-charge-label`/`.se-charge-hint` 文字色、動作按鈕與 `.se-time-input` 邊框 → `--ds-*`。`RenewMonthlyModal.vue`：`.period-hint`、`.info-row` → token。**保留**：`.se-st-*`（出缺勤狀態）、`.se-btn-*`（動作色）、`.se-charge-standard/higher/lower`（計費比較）等功能語意色——現有 ds semantic token（success/warning/danger/info）不足以表達 scheduled 藍/reschedule 紫等多態區分，貿然替換會降低可辨識度，登記為後續 design token 擴充。`npm run build` 通過。



課程管理頁的統計列、課程列表卡片、表格從多層漸層光暈與彩虹裝飾條收斂為乾淨的白底卡片與中性表格，狀態標記（暫停、聚焦）改用統一的語意色，整體視覺一致、好掃讀。

開發備註：#691 reference page 治理第二階段（內容容器；狀態 chip 細節與 modal 留後續 PR）。`CourseManagement.vue` `<style>`：(1) `.stats-strip`/`.stats-orb` 移除漸層底與 `::after` 彩線（`#0f172a→#f59e0b`）、`.stats-orb-total` radial 改 `--ds-primary` 底邊；數字字重 950→700。(2) `.table-card`/`.student-group-card` 移除多層 gradient 背景、彩虹 `::before` 頂條（`#38bdf8`/`#f59e0b`）、hover transform/大陰影 → `--ds-canvas` + `--ds-shadow-1`，圓角 22→12。(3) skeleton 彩虹 shimmer → 中性 `--ds-canvas-soft`/`--ds-hairline`。(4) `.creation-success-banner`/`.focus-mode-banner`/`.student-group-paused-badge` 改 success/info/warning token wash。(5) `.expand-indicator`/`.student-group-meta`/`.focus-btn`/`.student-group-add-row` 色票 → `--ds-*`。(6) `.course-table` thead/th/td 與 `.course-row` 左側 accent bar（`rgba(14,165,233)`→`--ds-primary`）token 化。頁面 hex 347→311。`npm run build` 通過。



課程管理頁的頁首從浮誇的漸層光暈 hero（多層放射/旋轉光暈、超粗大標題）收斂為乾淨的白底卡片，標題字級字重回到後台應有的沉穩感；篩選列、主要按鈕統一品牌色，整體更專業、更好掃讀。

開發備註：#691 reference page 治理第一階段（頁首 + 篩選列，內容區與 modal 留後續 PR）。`CourseManagement.vue` `<style>`：(1) 移除 `.course-page::before` 背景 gradient mesh 光暈、`.course-header-card::before`（grid mask）與 `::after`（conic 旋轉光暈）三組裝飾偽元素。(2) `.course-header-card` 改 `var(--ds-canvas)` + `--ds-hairline` + `--ds-shadow-1`，圓角 24→16。(3) `.page-title` font-weight 950→700、clamp 3.6rem→2rem；`.command-kicker` `#7dd3fc`→`--ds-ink-mute`、字重 900→700。(4) `.meta-pill`/`.btn-soft`/`.filter-bar`/`.filter-field` 色票全改 `--ds-*`，移除 inset 高光與 hover transform/大陰影。(5) `.btn-accent` 主 CTA 由深色 gradient → 實心 `--ds-primary`，hover `--ds-primary-deep`。`npm run build` 通過。



左側選單目前選中項目改為更沉穩的「左側色條 + 品牌色淡底」（參考大型後台軟體做法），取代原本較搶眼的漸層光暈；待辦數字標記顏色統一為品牌色與警示紅，整體更專業一致。

開發備註：#698 App 外殼治理第一階段（側欄）。`styles.css`：(1) 新增 `--sidebar-active-wash`/`--sidebar-active-bar`/`--sidebar-badge-bg` token（light + dark 各一組）。(2) `.sidebar-nav button.active` 移除舊 indigo gradient + indigo 外陰影（殘留 `rgba(83,58,253,*)`），改 `inset 3px` 左色條 + 半透明品牌色淡底。(3) `.nav-badge` 硬編碼 `#ff7043` → `var(--sidebar-badge-bg)`；urgent `#d32f2f` → `var(--ds-danger)`。`App.vue` loading 文案 `載入中...` → `載入中…`（`GUIDE_UI_COPY`）。`npm run build` 通過。topbar / 導覽 FAB / update-banner 留後續 PR。



啟動 UI 去 AI 化的元件化基礎建設：建立 4 個只吃設計 token 的共用元件，後續各頁面逐步替換，讓全站按鈕、卡片、空狀態、數字卡視覺一致。

開發備註：新增 `frontend/src/components/design-system/`（AtButton：primary/secondary/ghost/danger × sm/md，primary 改實心非 gradient；AtCard：default/inset + header/actions slot；AtEmpty：Material icon + 標題 + 下一步說明，禁 emoji；AtMetric：`tabular-nums` 數字 + delta tone + accent 邊條）+ README（用法 + 禁止清單）。全部僅消費 `--ds-*` token，零硬編碼色。示範：`LearningRecordsPage` 上一堂摘要空狀態改用 `AtEmpty`、loading 文案改全形省略號（對齊 `GUIDE_UI_COPY.md`）。`npm run build` 通過。Epic #687 Sprint 0 基礎建設。



開發備註：批次完成 Epic #687 文件/基礎建設層：(1) 新增 `docs/GUIDE_UI_COPY.md` — 空狀態公式、loading/error 規範、placeholder/按鈕文字規則（Closes #690）。(2) 新增 `docs/GUIDE_DESIGN_QA_SMOKE.md` — 逐角色 smoke 路徑 + 上線後 OPS 確認（Closes #705）。(3) 新增 `scripts/design-hex-count.sh` + `docs/design-hex-baseline-2026-06-06.json`（grand total 3800 hex，作為 #687 KPI baseline）+ `npm run metrics:design-hex`（Closes #706）。(4) `.github/pull_request_template.md` 新增 Design System 檢核區塊（Closes #697）。(5) `docs/RULE_DESIGN_SYSTEM.md` §9 新增 Rollout Tracker 表格連結所有子 issue（Closes #709）。(6) `docs/INDEX.md` 前端開發章節補 UI_COPY_GUIDE / DESIGN_QA_SMOKE 導航。(7) README：頁面數 30→33、近期重點更新改 2026-06、補 ReleaseNotesPage / BranchManagementPage。


## 2026-06-06 — feat(learning/ui): 評量新增「上一堂摘要」+ 首批四頁視覺治理（#154）

老師/主任在學習評量表可直接看到「上一堂上到哪裡」（含代課老師那堂），不用再翻歷史；同時完成首批四個高曝光頁面的視覺一致化，降低介面割裂感與 AI 模板感。

開發備註：`GET /api/v1/learning-records/latest-approved-summary` 回傳補齊 `is_substitute`、`homework_status`、`quiz_score`、`next_week_test_scope`；`LearningRecordsPage` 新增上一堂摘要卡（載入/錯誤/空態、代課標示），並在編輯既有/課表開單/主任手動開單時自動載入。新增 regression：`SubstituteTeacherTest::test_latest_approved_summary_uses_effective_substitute_teacher`。UI 治理首批覆蓋 `DirectorDashboard`、`TeacherHomePage`、`LearningRecordsPage`、`SmartCalendar`：工具列與容量標示 token 化、移除高辨識 emoji 呈現、CTA 與重點色對齊 `RULE_DESIGN_SYSTEM.md` token。

## 2026-06-06 — security(repo): 移除另外 2 個 production PII SQL dump + .gitignore 防再犯

開發備註：承上 docs 大掃除，repo 內再揪出 2 個含 PII 的 dump——`AllTrue (3).sql`（root，1920 行）、`backend/storage/backups/prd-e-20260418-232201.sql`（production 備份，6156 行），含真實 `Student`/`StudentClass`/`Teacher` 資料。皆 `git rm` 出 HEAD。新增 `.gitignore`：`*.sql`（`!scripts/*.sql` 保留查詢腳本）+ `backend/storage/backups/`。歷史清除（filter-repo + force-push main）屬 P0，依風險取捨**暫不執行**，決策留檔於 `docs/SECURITY.md §6`（private repo + 單一 committer，殘留風險可接受；repo 轉 public/新增協作者前再重評）。

## 2026-06-06 — chore(docs): docs/ 大掃除（移除 PII 備份、去重、歸檔、補導航）

開發備註：(1) ⚠️ **移除 `docs/AllTrue_backup.sql`**——2026-02-07 的 phpMyAdmin dump，含真實 `Student`/`StudentClass`/`StudentSingIn`/`Teacher` INSERT（姓名/RFID/LineID），不該入 repo（個資法）。已 `git rm` 出當前樹；**git 歷史殘留需另外決策**（filter-repo 需 force-push，屬 P0，待使用者批准）。(2) 刪除 `docs/` root 與 `archive/` 重複的 `使用說明_主任與超級管理員.md`、`更新網站前端.md`（body 相同，只差封存 banner；保留 archive 版）。(3) `PORSCHE_VISUAL_SYSTEM.md`（已 superseded）移入 `archive/`。(4) 孤兒檔補進 INDEX 導航：`api-swipe-rfid.md`、`SUPER_ADMIN_AND_MIGRATIONS.md`、`AMBIENT_AUDIO_LICENSES.md`、`SMOKE_TEST_RUNBOOK.md`、`ADOPTION_QUALITY_METRICS.md`、`reviews/PRODUCT_GAP_REVIEW_2026-06.md`。(5) 修正 README 3 處指向 root 但實際在 archive 的過時路徑。(6) `git update-index --chmod=-x` 清掉 4 個誤設可執行權限的文件。docs-integrity-check `--strict` 全綠。

## 2026-06-06 — chore(deps/test): phpstan 2.2.2 + guzzle 7.11；修 factory faker 姓名超長 CI flaky

開發備註：清掉殘留的 Dependabot PR 與分支。(1) phpstan/phpstan 2.2.1→2.2.2 + guzzle 7.10.5→7.11.0（promises/psr7 同組），phpstan patch 在 `CoursePackageController::createMultiSubject` 報 13 個 `ternary.alwaysTrue`/`nullCoalesce.offset` 等——皆 larastan 由 `payment_type` 驗證規則推 `$isMonthly` 為常數真的誤報（runtime 仍可為 `session`，改 code 會弄壞 count 制方案），故併入 `phpstan-baseline.neon`、不動計費邏輯（取代 dependabot #678 → #679）。(2) `StudentFactory.name`/`UserFactory.Name`/`CampusFactory.name` 原直接用 `faker->name()`/`city()` 寫入 VARCHAR(32) 欄位，遇較長姓名（如 33 字 "Prof. … Jr."）間歇性 `1406 Data too long` 失敗 → 一律 `mb_substr(…, 0, 32)`（鏡像同檔 SchoolName 既有寫法），消除隨機 CI flaky。

## 2026-06-01 — chore(notify): 學習回饋／回覆接推播基礎建設（dark launch，預設關閉）

開發備註（dark launch，功能未對外開啟，故不進版本公告卡）：家長在學習評量留言或追加回覆時通知老師／主任；老師回覆家長時推播家長 LINE（需綁定）。家長可於家長系統關閉。

開發備註：T3（家長 PII + LINE 推播 + 防騷擾）。新增 `FeedbackPushNotifier` 服務串接 `LearningRecordFeedbackController` 三個事件（`parentUpsert`/`parentReply` → 站內 `Notification`（Type `lr_feedback`，SourceKey 去重）；`staffReply` → 家長 LINE，鏡像 `SendTuitionReminders` 的 `StudentLineBinding`+`Campus.messaging_channel_token` 推播）。**dark launch**：perfflag `feedback_push_enabled` 預設 **false** → 全程 no-op，production 行為不變；確認推播節奏/文案後再以 `PERF_FEEDBACK_PUSH=true` 開啟。防騷擾：同 (feedback,direction) 於 `feedback_push_merge_window_seconds`（預設 600=10 分鐘）內合併一則。個資退出權：`student_line_bindings.notify_learning_feedback`（預設開）+ `GET/PUT parent/notification-preferences`。Best-effort：推播失敗只記 log、不阻斷主流程。涵蓋測試：flag-off no-op、staff 站內、parent LINE、merge window、opt-out、跨校隔離、推播失敗不丟出。**未做（flip flag 前的 fast-follow）**：ParentPortal 退訂 toggle UI；關聯 TD-013（LINE 綁定率低 → 觸達上限）、TD-057（reply-rate KPI）。PRD：`.cursor/plans/feedback-push-notifications_2026-06-01.md`。

## 2026-06-01 — feat(billing): 建課即時費用試算與計價方式提示

建立課程時，排課摘要會即時顯示「每堂計費／每小時計費」與預估總額，幫助主任確認金額正確，降低單價填錯造成的費用落差。

開發備註：`UniversalClassScheduler` 摘要卡新增費用試算面板，鏡像後端 `EnrollmentService::store` 計價契約（session：round(單價×堂數)；hour：round(單價×總時數)，總時數=堂數×平均每堂分鐘/60）。計價方式（每堂／每小時）與送出 payload 同源（皆由 `hasPerDayDuration` 推導），故預覽顯示的單位必與實際入帳一致，直接防止 Bug #129 類的單位混淆 ×2 錯帳。公式抽成純函式 `estimateCreateCharge`（`coursePricing.js`）+ 單元測試（含 8,800 vs 17,600 對照、四捨五入、防呆），已 wire 進前端 `build` chain（CI 把關）。混合時長之 hour 模式為「平均」估算（uniform 為精確），面板標示「預估」。`CourseEditForm` 編輯態（含 preservedDelta）暫未加，留待後續。

## 2026-06-01 — chore(perf): /class-sessions 代課解析改 derived-table join（TD-058 / TD-062 Phase 3）

開發備註：`ClassSessionController::index` 解析代課老師原以 per-row correlated subquery `sub_sched.id = (SELECT MAX(sub2.id) …)`，且 `DATE()`/`SUBSTRING()` 包裹欄位使索引失效（TD-058，主查詢 1–3.5s 主因）。改為預先彙總的 derived-table join（鏡像既有 `lr`/`si` 的 `MAX(id)` 衍生表）：inner aggregate 取每 `(student_course_id, schedule_date, HH:MM)` 的 `MAX(id)`，並在彙總內過濾 `teacher_id <> 課程老師`、`status='scheduled'`、`original_schedule_id IS NOT NULL`，與原 subquery 等價。`schedule_date` 為 DATE、`start_time` 為字串，故 GROUP BY 該兩鍵等同原 DATE()/SUBSTRING() 正規化，不多出列。golden 保護：18 條代課/調課/可見性/HH:MM:SS 格式測試 + ClassSessionApi/SameDayMultiSlot/Batch/Duplicate/TimeSync/ReschedulePrecision 全綠（byte-identical）。`teacherTrust` 同款 subquery 未改，留待後續。

## 2026-06-01 — chore(perf): /class-sessions 日期視窗改索引友善（TD-062 Phase 2）

開發備註：`ClassSessionController::index` 的 `start`/`end` 過濾由 `whereDate('cs.SessionDate',…)` 改為裸欄位比較 `where('cs.SessionDate',…)`。`SessionDate` 為 DATE 欄位，故結果 byte-identical，但不再以 `DATE()` 包裹欄位 → range 可命中 `(StudentClassID, SessionDate)` 複合索引。characterization 測試 `ClassSessionDateWindowFilterTest` 鎖定閉區間 [start,end] 行為；250 條 class-session/代課/調課/點名相關測試全綠。

## 2026-06-01 — chore(perf): 行事曆換週/換日視窗快取（TD-062 Phase 1）

開發備註：`SmartCalendar` 換週/換日原本每次都全量重抓 3 支 API（student-classes/schedules/class-sessions）。新增「視窗快取」：記錄上次抓取的 `{分校, ±21 天範圍}`，換週/換日若目標週仍落在此視窗內（同分校）即跳過網路、由既有 reactive computed 直接重渲染 → 命中時 0 net request。`loadCourses()` 與 occurrence 合併完全未動；所有 mutation（建課/請假/調課/點名…）仍走完整重抓，故無 staleness 風險。判斷邏輯抽成純函式 `isRangeWithinFetchedBounds` 並加單元測試（`calendarLoadPerformance.test.js`）。

## 2026-06-01 — chore(deps): composer 鎖定 PHP 8.2 平台 + 月初帳務測試健全化

開發備註：(1) `backend/composer.json` 設 `config.platform.php=8.2.30`，避免 dependabot/`composer update` 解析出需 PHP 8.3/8.4 的相依（如 `symfony/css-selector` v8、`zipstream` 3.2.2）而在 8.2 runtime 裝不起來（dependabot PR #643 即此症）。順帶安全升版：`symfony/routing` v5.4.48→v5.4.53、`symfony/polyfill-intl-idn` v1.33.0→v1.38.1（清掉 2 筆 OSV 發現，TD-061）、`guzzle` 7.10.5、`maatwebsite/excel` 3.1.69，並把 `laravel/framework` 由 dev 分支 pin 至穩定 `v8.83.29`。(2) `CoursePackageMonthlyBillingTest` 月結堂數測試夾住堂次日期 ≤ 今天，修正每月 1 號（月內未來日期被 `alerts/tuition` 正確排除）造成的時間敏感失敗。
