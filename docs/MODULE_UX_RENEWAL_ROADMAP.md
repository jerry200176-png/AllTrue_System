# AllTrue 全系統 UX Renewal Roadmap

> 版本：2026-08-01 · Owner：Product/UX + Engineering · Epic：[#1600](https://github.com/jerry200176-png/AllTrue_System/issues/1600)

## 目的與邊界

把全站視覺與 UX 拆成可獨立驗收的 bounded context。每個模組都依同一套 SOP 研究、設計、測試、發 PR、部署與留下 production evidence；不一次重寫全站、不改資料庫真相、不繞過既有權限與分校隔離。

## 現有基準

- 視覺 SSOT：`docs/RULE_DESIGN_SYSTEM.md`；白／冷灰底、navy 文字、單一橘色主行動、語意色只表達狀態、低裝飾高資訊層級。
- 主任總覽與學習評量表：目前上線基準，只做跨頁導覽與契約驗收。
- release SOP：`docs/GUIDE_RELEASE_EXECUTION_PACKAGE.md`、`docs/OFFLINE_MERGE_SOP.md`、`docs/GUIDE_DESIGN_QA_SMOKE.md`。
- regression SSOT：`docs/AI_REGRESSION_LESSONS.md`；高風險請假／補課優先回歸 F1、F3、F5、R10、Y5、Y6，以及 GitHub #1099、#1101、#1342。

## 角色／頁面／核心任務矩陣

| 角色 | 核心任務 | 主要頁面 | 第一眼要回答的問題 |
|---|---|---|---|
| 主任 | 處理會影響課務與家長的案件 | `DirectorDashboard.vue`、`CourseManagement.vue`、`SmartCalendar.vue` | 今天先處理什麼？這個動作會改變什麼？ |
| 老師 | 完成今日課務與學習紀錄 | `TeacherHomePage.vue`、`AttendancePage.vue`、`LearningRecordsPage.vue` | 我今天還有哪一件必做？ |
| 家長 | 申請請假、查看學習與付款狀態 | `ParentPortal.vue`、`NotificationsCenter.vue`、`ChatPage.vue` | 我的申請處理到哪裡？下一步是什麼？ |
| 管理者 | 維護人員、分校、課程與系統設定 | `StudentsList.vue`、`TeachersList.vue`、`BranchManagementPage.vue` | 我正在改哪個範圍？權限與影響是什麼？ |

## Bounded context 排程

| 順序 | Context | 頁面 | 狀態 |
|---:|---|---|---|
| 0 | 全站基準盤點 | matrix、state inventory、共用元件規格 | 已建立；Epic [#1600](https://github.com/jerry200176-png/AllTrue_System/issues/1600) |
| 1 | 課程與家長請假 | `CourseManagement.vue`、`ParentPortal.vue`、主任入口整合 | 已上線；Issue [#1601](https://github.com/jerry200176-png/AllTrue_System/issues/1601) |
| 2 | 行事曆與調課 | `SmartCalendar.vue`、`ScheduleDiscrepancyPage.vue` | 研究與規格中；Issue [#1605](https://github.com/jerry200176-png/AllTrue_System/issues/1605) |
| 3 | 老師每日工作流 | `TeacherHomePage.vue`、`AttendancePage.vue` | 待處理 |
| 4 | 評量跨角色整合 | `LearningRecordsPage.vue` 老師／主任／家長入口 | 研究與第一波預覽完成；Issue [#1611](https://github.com/jerry200176-png/AllTrue_System/issues/1611)，待 PR／production evidence |
| 5 | 繳費與財務 | `TuitionCollectionPage.vue`、`BillingList.vue` 等 | 高風險、待處理 |
| 6 | 學生／老師／課程管理 | 建立、搜尋、匯入、停用 | 待處理 |
| 7 | 家長入口與溝通 | `ParentPortal.vue`、通知、聊天、版本說明 | 待處理 |
| 8 | 系統管理與支援 | 權限、分校、登入、支援工具 | 待處理 |

## 全站狀態盤點規格

每頁都要記錄：正常、loading、empty、API error、長文字、資料很多、權限不足、分校切換、鍵盤 focus、手機單欄、主要 CTA 是否可見，以及是否有不預期水平溢出。驗收寬度固定為 390、412、768、1280、1440px，且 `scrollWidth <= clientWidth`。

## 共用互動判準

- 每個工作流顯示「目前狀態／影響／下一步／負責角色」，主要動作使用清楚的動詞，不用只寫「查看」。
- 狀態、篩選、tabs、table、modal、toast、empty state 必須沿用 Design System token 與可讀的文字層級。
- 高風險動作保留確認、原因、audit 與錯誤回饋；不把 backend 欄位名或工程術語暴露給使用者。
- 手機不靠水平滑動尋找主要操作；次要操作可折疊，但 primary CTA 永遠在可見操作區。

## 研究基準

- 企業產品：Power BI「重要資訊置頂、細節下鑽」、Grafana「每個 dashboard 回答單一問題」、Metabase「篩選與 drill-down 導向下一步」。
- Starred/open-source repo：`vbenjs/vue-vben-admin`（quiet shell、工作區分層）、`filamentphp/filament`（page header → table heading → filters → actions）、`GibbonEdu/core`（角色化教務檢視）、`chatwoot/chatwoot`（文字 tabs、狀態數量、窄螢幕處理）、Carbon、Fluent UI、Primer、Radix、shadcn。
- 互動細節：Jira 的 status/priority/resolution 工作流語言；Primer ActionList 的單欄操作、危險動作與 inactive reason；Primer content/button 的動詞導向與可理解 CTA。

## 交付門檻

每個 context 必須有研究紀錄、PRD/UX spec、風險與驗收條件、GitHub Issue、feature branch、測試、PR、CI、merge SHA、deploy run、health/version、桌面與手機 production evidence、CHANGELOG 與 regression lesson/tech debt 更新。未完成項目留在 Issue，不以「畫面看起來完成」結案。
