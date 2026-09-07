# AllTrue 產品體驗成熟度審計（2026-09-08）

## 結論與範圍

本輪以 production 可驗證的主任流程、repo 內角色流程與近期已合併工作為基準，完成 12 條核心 journey 的 Gap Matrix。現有 onboarding、主任工作台、導覽、載入/空狀態/錯誤恢復與 mobile 導覽已有明顯收斂；真正高價值但不可在本輪直接改動的缺口，是跨學生、老師、課程與歷史資料的 global search，以及帳務/核薪、排課例外與家長溝通的成熟度與信任模型。

本輪只選兩個 T1、可逆、不改 API、資料、權限或 business semantics 的改善：明確標示導覽搜尋範圍；統一課程、出缺勤、學習紀錄的高頻搜尋欄位提示與 native search affordance。Global search 不在本輪實作，需 Founder 先決定資料範圍、權限、排名、audit 與錯誤恢復契約。

基準 commit：`ddd6c4a61e9a11b1bc0e3d4a0c04a8b8e42e0c36`。審計不執行 production data mutation；主任帳號僅作 read-only smoke。

## Evidence contract

- **L**：repo source、既有測試與 diff 可直接驗證。
- **P**：production real browser observed；主任 390/412/768/1280/1440 viewport smoke 通過。
- **D**：官方產品頁、官方文件或 release notes 的 documented evidence，不等同於實際 tenant 行為。
- **O**：maintained open-source pattern；僅作設計參考，不複製 GPL code。
- **I**：根據上述 evidence 的推論，會明確標記。
- 老師與家長 production credential 未提供，因此 teacher/parent browser journey 是 skipped，不把 skipped 當成 pass。

## AllTrue 現況證據

`frontend/src/lib/roleOnboarding.js`、`navigationRegistry.js`、`App.vue`、`DirectorDashboard.vue`、`StudentsList.vue`、`LearningRecordsPage.vue` 與現有 role/e2e tests 顯示：角色 onboarding、role-scoped navigation、desktop/mobile More search、Ctrl/Cmd-K、今日工作台、loading/empty/error/recovery 已存在（L）。Production 主任 read-only smoke 顯示 `主任總覽`、`今日課務進度`、`今天要處理的事`、`今日摘要`與 mobile bottom navigation；390px 無 horizontal overflow，五個 viewport dashboard smoke 通過（P）。

目前 Ctrl/Cmd-K 的 input placeholder 是「搜尋功能、報表或設定…」，且只會篩選 navigation registry；沒有跨 entity 的學生/老師/課程/歷史資料搜尋（L/P）。各頁仍有 page-local filters；本輪只改善其提示，不改查詢行為（L）。TeacherList recovery 已有 open PR #2519，in-app feedback 已有 open PR #2538，本輪不重複實作。

## Benchmark evidence

| 產品 | 可驗證 evidence | 對 AllTrue 的適用啟示 |
|---|---|---|
| [TutorCruncher release notes](https://tutorcruncher.com/changes) | 2026-09 release notes 描述 global search、qualifier（例如 Tutor/Invoice）、type-ahead suggestions，並持續改善 filter、not-found、performance。D | search 不只是入口名稱；需可預期的 object scope、suggestion 與 recovery。 |
| [Teachworks product](https://www.teachworks.com/tutoring-management-software)、[self-serve guidance](https://blog.teachworks.com/2025/01/ensure-a-positive-client-experience-with-teachworks-self-serve-options/) | scheduling、courses、attendance、lesson notes、billing、automated notifications、employee hours/earnings，以及 client self-serve calendar/history/invoices。D | 高風險流程需要可見狀態、歷史與自助查詢；不能直接推導為 AllTrue 應照抄。 |
| [ClassDojo teachers](https://www.classdojo.com/en-us/teachers/)、[Class Story](https://www.classdojo.com/en-us/classstory/)、[parent controls](https://help.classdojo.com/hc/en-us/articles/41204302339981-ClassDojo-Parental-and-Child-Controls-FAQ) | announcements、teacher/parent connection、translation、teacher-only posts、parent read receipts 與 messaging controls。D | 家長溝通的 audience、read state、translation 與 permission boundary 應先成為產品契約。 |
| [PowerSchool Families](https://help.powerschool.com/t5/Families/ct-p/Families)、[parent portal](https://support.powerschool.com/help/pcxp/45/Content/Topics/ParentCONNECTxp_Parent_Portal.htm) | 家長可依學生查看 grades、attendance、assignments、course plan、discipline、contact/school information。D | parent/student information hierarchy 要以 student context 與歷史可追溯性為核心。 |
| [Linear search](https://linear.app/docs/search)、[notifications](https://linear.app/docs/notifications) | global `/` search、qualifiers、recent items、rank/filter，以及 inbox 與 channel controls。D | global search 與 notification feedback 都要顯示 scope、context 與可控狀態。 |
| [Notion search](https://www.notion.com/en-gb/help/search)、[updates](https://www.notion.com/help/updates-and-notifications) | sidebar/Cmd-K search、recently viewed、sort/filter、inbox、jump to context、read/archive。D | navigation search 和 content search 應用語言與結果範圍清楚區分。 |
| [Stripe Dashboard search](https://docs.stripe.com/dashboard/search?locale=en-GB) | 跨 connected accounts/customers/invoices/payouts/products，支援 filters/operators/object identifiers。D | 高風險資料搜尋要有精確 object identity；這是 Founder-level data/permission contract。 |
| [Frappe sidebar](https://github.com/frappe/frappe/blob/9e8a89c6f1b9a94ce59d9bc4633aef06a859c2ca/frappe/public/js/frappe/ui/sidebar/sidebar.js)（MIT，commit `9e8a89c6f1b9a94ce59d9bc4633aef06a859c2ca`）、[Gibbon navigation](https://github.com/GibbonEdu/core/blob/3afc180f80d9a168096aaa7bb679da015a57295e/resources/templates/navigation.twig.html)（GPL-3.0，commit `3afc180f80d9a168096aaa7bb679da015a57295e`） | maintained OSS 將 module context、active route、mobile menu、keyboard escape/outside-click、role-aware menu 做成明確結構。O | 只採 pattern；不複製 GPL code，也不把成熟 OSS 的全套 IA 當作本輪需求。 |

## Gap Matrix

分數為 `Impact × Frequency × Confidence ÷ Cost`；Impact/Frequency/Confidence/Cost 各 1–5。排序同時考慮安全 scope；高分但需要資料、權限或 business semantics 的項目不在本輪直接做。

| ID / journey | AllTrue evidence | Benchmark / 真正 gap / harm | I×F×C÷C | 改善、成本、風險、over-engineering、決策 |
|---|---|---|---:|---|
| J1 首次登入/onboarding | L：director/teacher role onboarding 與 anchors tests；近期已合併。 | D：成熟產品把 role context 與 next action 做清楚。未見已證明的 director/teacher 缺口；parent orientation 未 live 驗證。 | 3×2×4÷2=12 | 不改；parent journey 需 credential。低成本但 evidence 不足，不 over-engineer。 |
| J2 首頁/工作台 | P/L：主任五 viewport dashboard smoke 通過，今日進度、待處理與摘要存在。 | D：Teachworks/PowerSchool 重視 actionable status。現況未見主要落後；傷害低。 | 4×3×5÷2=30 | 不改；維持 workbench contract。 |
| J3 navigation/IA | P/L：role-scoped registry、More search、mobile bottom nav 存在；scope 文案曾讓人誤以為可找資料。 | D：Linear/Notion/OSS 將 navigation search 與 content search 分開。歧義使使用者在 Ctrl-K 反覆嘗試，降低 trust。 | 3×4×5÷1=60 | **Selected A**：scope copy；成本低、回滾只需前端 revert、無 regression semantics；不 over-engineer。 |
| J4 global search | P/L：Ctrl-K 只搜功能；各頁是 local filters，無學生/老師/課程/歷史 cross-entity search。 | D：TutorCruncher qualifiers/typeahead、Linear、Notion、Stripe 都提供可界定的廣域搜尋。找資料要逐頁點擊，reach/frequency 高。 | 5×5×5÷5=25 | **Founder decision**：先定 entity/permission/index/ranking/audit；中高成本、高 regression risk，直接做會 over-engineer/越權。 |
| J5 排課/調課/例外/衝突 | L：近期有 recurring/exception contract guard 與 classroom flows；P calendar parity cases 因 credential/fixture 未通過，不宣稱 pass。 | D：Teachworks 強調 one-to-one/group/course scheduling。排課 correctness 直接影響堂數與信任。 | 5×4×4÷5=16 | 不改；任何 semantics、migration、repair 停請 Founder。 |
| J6 出缺勤/learning record | L：Attendance、LearningRecords 有 role-specific flows、loading/error；本輪只改 search affordance。 | D：Teachworks/PowerSchool 將 attendance、lesson notes、grades/history 放在可追溯 context。AllTrue UI 有基礎，teacher live 未驗證。 | 4×4×4÷2=32 | **Selected B**：placeholder/native search；成本低、只改善 discoverability、風險低；不改 record semantics。 |
| J7 請假/異常 | L：既有 preview/decision/recovery paths；核心狀態仍是 workflow semantics。 | D：成熟 scheduling products 以狀態與歷史降低 ambiguity。錯誤可能造成缺課/薪資錯誤。 | 5×3×4÷5=12 | 不改；需 Founder 定義狀態、通知與資料修復邊界。 |
| J8 家長/學生資訊與溝通 | L：ParentPortal 存在；parent browser 未因缺 credentials 驗證。 | D：ClassDojo read receipts/translation/messaging controls、PowerSchool student context。功能深度與 trust/read state 落後，但屬 product direction。 | 4×3×4÷4=12 | 不改；Founder 決定 audience、read receipt、translation、資料可見性。 |
| J9 繳費/月結/核薪 | L：相關高風險頁面存在，但本輪沒有 billing/payroll diff。 | D：Teachworks/Stripe 提供 billing/history/object identity。任何錯誤可能造成金錢與薪資損害。 | 5×3×5÷5=15 | 不改；Founder decision，禁止以 copy/UX 掩蓋 correctness gap。 |
| J10 notifications/feedback/states | L：dashboard/Students/Learning 有 loading/empty/error/recovery；feedback PR #2538 已 open，本輪不 duplicate。 | D：Linear/Notion inbox、read/archive、jump-to-context；AllTrue 的通用模型仍可深化。 | 3×3×4÷2=24 | 不改；先合併/驗證既有 PR，再做單一 bounded iteration。 |
| J11 mobile/performance/visual | P：主任 390/412/768/1280/1440 dashboard 通過，390 無 horizontal overflow；teacher/parent skipped。 | D：ClassDojo 多端溝通、成熟 SaaS 強調 responsive feedback。尚無 production evidence 指向本輪需 redesign。 | 3×3×4÷2=18 | 不改；補 credentials 後再做角色分層測量，不 over-engineer。 |
| J12 recovery/system trust | L：Students recovery 已合併；TeachersList 類似改善在 #2519 open；dashboard error/empty/retry 存在。 | D：TutorCruncher release notes 強調 not-found/performance；現況主要風險是未驗證 teacher/parent，不是可安全猜測的缺口。 | 4×3×4÷2=24 | 不改、不重複 #2519；待 PR/production evidence。 |

## 選擇、驗收與 rollback

Selected A/B 不改 API、資料、權限、feature flag、navigation registry 結構或查詢 semantics；只改 copy、`type=search`、autocomplete 與 placeholder。Source tests 會鎖定 scope wording 與欄位 affordance；production 驗收必須真的打開 Ctrl-K、確認新 scope 文案，並進入課程/出缺勤/學習紀錄頁確認新提示，而不是只打 health endpoint。

Deploy 前 rollback target：backend `ddd6c4a61e9a11b1bc0e3d4a0c04a8b8e42e0c36`、frontend asset `d37318ce2191dda902c9c4bc2083530ea0eb1efe`。本輪沒有執行 rollback；只在 deployment、smoke 或 affected workflow 驗證失敗時依 deploy workflow 回滾並重新驗證原流程。沒有 runtime flag；成功 deploy 後即為 runtime enabled，仍須以 affected workflow smoke separately verified。

## Founder decision / 未處理 Top gaps

1. 是否批准 global search discovery：entity scope（學生/老師/課程/歷史/帳務）、role/tenant permission、identifier/qualifier、ranking、not-found/error、audit 與 performance SLO。
2. 是否批准 parent communication product contract：可見資料、read receipt、translation、teacher-to-parent messaging、notification channel 與 retention。
3. 是否安排 teacher/parent 的最小化 read-only production credentials，完成 skipped journeys；並另案決定排課例外、請假、attendance、billing/payroll 的 correctness/trust work。

本輪到此停止，不延伸下一批 redesign。
