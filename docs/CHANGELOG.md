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

## 2026-07-15 — docs: Self-Governing Repository 機制

- **Changed**：新增治理「自我維護」政策（角色、自動驗證、失效/退休、衝突、Exit Criteria），明確不新增 agent 行為規則；目標是 Founder 無需持續介入。
- 開發備註：`docs/POLICY_SELF_GOVERNING_REPOSITORY.md`；實作以既有 CI lint 為主，planned jobs 僅列於政策內。

## 2026-07-15 — docs: Governance Design Review + AI Principles

- **Changed**：以外部官方證據 + token 實測推翻／修正 Round‑1 部分結論（mega INDEX 不可長期、分支定為 TBD short-lived PR）；新增 `RULE_AI_GOVERNANCE_PRINCIPLES` 與 SoT Matrix。
- 開發備註：`docs/reviews/REPO_GOVERNANCE_DESIGN_REVIEW_2026-07-15.md`；Round‑1 報告降為歷史。無 runtime 變更。

## 2026-07-15 — docs: Repository Governance Review（降 AI 認知負擔）

- **Changed**：完成全庫治理審查並修正權威事實漂移：INDEX runner 改對齊 `ubuntu-latest`（K11）、清空過期 `SOP_MATURITY` handoff、修正 AI_REGRESSION「127 行」誤述、PR template 補齊 size／docs sync 矩陣。
- 開發備註：報告見 `docs/reviews/REPO_GOVERNANCE_REVIEW_2026-07-15.md`；無 runtime／deployable 程式變更。

## 2026-07-12 — feat: 評量頁新增「只看已填」篩選

- **Added**：評量／學習紀錄頁新增「只看已填」篩選（就在「只看未填」旁邊，兩者互斥）——主任／管理者可一鍵只檢視已填寫評量正文的紀錄，方便回顧已完成的評量內容（in-app #199）。
- 開發備註：純前端顯示篩選（依 `hasLearningRecordBody` 過濾），無 API／資料／權限變更；additive、非破壞性；PR #1194。

## 2026-07-11 — feat: 主任總覽新增今日優先處理

- **Added**：主任總覽會從既有待辦中整理最需要先處理的三項，說明逾期、收款、點名、補課、評量或家長回饋為何需要留意，並可直接前往處理畫面。
- 開發備註：純前端排序 helper `directorPriorityRisks`，只使用主任頁既有且已分校授權的 aggregate counts；不新增 API、不改繳費或排課規則。

## 2026-07-11 — fix: 課程改排時，代課／例外時段不再被連續搬移

- **Fixed**：修改固定上課日並批次重排未來堂次時，相關代課或例外排程會跟隨各自堂次移動一次，不再因日期重疊而被後續堂次再次搬走。
- 開發備註：contract reflow 在任何寫入前 snapshot `schedules.id`，再於同一 transaction 同步 `ClassSession`、active `LearningRecord` 與 schedule anchor；回歸 `RealignReflowTwoPhaseTest`。

## 2026-07-10 — feat: 每日商業智能摘要（AI-native ops phase 0）

- **Added**：`ops:business-digest`（每日 04:10 唯讀）——營收風險（未排程的預付堂 × 費率）、留存風險（近 14 天無課的在籍生）、資料品質異常、未來 7 天課量,每早自動量化營運健康度。
- 開發備註：純唯讀,計算抽到 `BusinessDigestService`（ADR-003）;`docs/POLICY_AI_NATIVE_ROADMAP.md` 定義 Phase 0-5（BI dashboard → 異常偵測 → 留存/營收智能 → AI 輔助行政 → 自動化工程維運）。此為 AI-native 演進的 metric 底座。

## 2026-07-10 — fix: 評量「無法填寫」缺口回填 + 夜間自動任務正式啟用

- **Fixed**：部分已上課堂次因系統缺漏無法填寫評量的問題已修復（回填 268 筆待填評量；老師端即可正常填寫）。
- **Ops**：production Laravel scheduler 從未有 driver（#1127 事故）：`schedule:run` cron 已布線，8 個夜間任務（對帳/孤兒清理/stranded 稽核/LR 回填/復現閘門）自今晚起實際執行；`pi-health` 新增 scheduler 心跳 critical（R68）。
- 開發備註：`.env` 權限 644→640；PR #956 路由存儲上線（append-only 版本化，行為零變更）；merge train 收尾 11 個 PR。

## 2026-07-09 — fix: 重複堂次清理完成 + 加課/跨約重複資料修正（PCR-R2 執行）

- **Fixed**：課表與評量中「同一堂課出現兩筆」的重複堂次已全部清理（21 組），評量「未填」誤提醒與收據日期錯位一併修正（陳品承 6/13、6/20；吳夏妍 5/14）。
- 開發備註：PCR-2026-07-09-957-D1-R2 A1+B 獲 CEO GO 後執行；audit intra=0；snapshot + 表級備份齊備；執行紀錄見 `docs/runbooks/957-d1-r2-execution-record.md`。
- 開發備註：unique slot index 重設計為 active-only（`ActiveSlotFlag` generated column，PR #1121）；placeholder PCR 取消。deploy migration 失敗不再被吞（PR #1120，R67）。
- 開發備註：治理批次——issues 94→81（含證據關閉）；remote branches 35→22（tag-then-delete 可逆）；in-app #171/#172/#175/#189/#191/#195 收尾。

## 2026-07-09 — fix: #957 D1 cleanup scope aligned with audit (PCR-R2)

- **Fixed**：`classsession:cleanup-intra-duplicates` 僅刪 Type-A active conflicts（與 audit 同語意）；cancelled placeholder 改為分析 only。
- **Added**：`ClassSessionIntraDuplicateFinder`、regression test `ClassSessionAuditCleanupScopeAlignmentTest`；PCR-R2 runbook。
- 開發備註：2026-07-09 preflight STOP（806 vs 21 組）；production freeze 維持至 CEO GO `PCR-2026-07-09-957-D1-R2`。

## 2026-07-09 — docs: #190 對帳 + #189/#191 dry-run + #957 D1 設計

- **Changed**：`190-reconciliation-report`（6 筆 SC 逐筆對帳、Invoice #690/#691 建議 amend）；`189-191-dryrun-report`（72 組 before/after）；`957-d1-sprint-design`（unique index migration）。
- 開發備註：production 唯讀稽核 2026-07-09；零寫入；洪子勛 Payment void 2998/0 已查證。

## 2026-07-08 — docs: Reliability Engineering — bug closure gate + #190 historical audit

- **Changed**：新增 `docs/GUIDE_BUG_CLOSURE_GATE.md`（六項關閉閘門）；`docs/incidents/190-historical-billing-repair-plan.md`（週日 0 元歷史帳務唯讀 audit，6 筆合約）；`189-191` 計畫補 §7 dry-run audit 結果。
- 開發備註：T0 docs-only；production 唯讀查詢已執行，**零寫入**；#190/#194/#196 code fix 不重開。

## 2026-07-08 — docs: in-app #189/#191 跨約重複堂次資料修復草案

- **Changed**：新增 `docs/incidents/189-191-data-repair-plan.md`（影響分析、唯讀偵測 SQL、修復策略比較、draft migration 規格）。
- 開發備註：**禁止未經 CEO 核准前於 production 執行任何寫入**；長期修復仍依 Epic #957。

## 2026-07-08 — docs: AllTrue Agent Engineering System v1

- **Changed**：新增 `docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md`、`.cursor/skills/alltrue-*`（除錯／測試／發布／安全／code review）與 `docs/GUIDE_AGENT_SKILLS.md` 上游評估。
- 開發備註：T0 docs-only；不整包安裝 addyosmani/agent-skills；INDEX + AGENTS.md 導航更新。

## 2026-07-08 — fix: 請假後課程詳情不再多畫出不存在的 16-18 堂次

Fixed：登記請假後，課程詳情的「上課日期」若出現半透明的錯誤時段（例如週日 10-12 的課卻多出一個 16-18 請假），已修正；現在只會顯示真實堂次。

開發備註：session-dates API 的 `collectMaterializedFromRows` 把 `leave` 堂次排除在 materialized 之外，同日又從契約推算 projected slot；POST body 的 StudentClass select 缺 `time` 欄位 → `resolveSlotTimesForCourseDate` fallback 16:00 → 前端半透明 chip 顯示幽靈 16-18 請假（in-app #196／GitHub #1101，劉芯岑案例）。修正 = leave 納入 materialized + POST select 補齊 time/duration 欄位。回歸 `SessionProjectionLeaveGhostTest`。

## 2026-07-08 — fix: 家長請假「審核中」的堂次，出缺勤與課表顯示不再互相矛盾

Fixed：家長送出請假但主任尚未審核時，這堂課在「出缺勤管理」會消失、卻在「課表與評量」被列成待填評量。已統一：兩邊都會顯示「請假(待審)」，不需點名也不需填評量；若審核退回，堂次會自動恢復待點名/待填。

開發備註：ParentPortal 請假流程只把 `ClassSession.Status` 設為 `leave_requested`（不建 StudentSingIn 列）；出缺勤管理把該狀態整列過濾掉、`sessionConsistency` 與 `LearningRecord::scopeExcludeLeaveSessionPendingReview` 只認 `leave`/`excused` → 兩畫面認定分歧（in-app #194／GitHub #1099，陳品承 7/4 案例）。修正 = `leave_requested` 進 NON_FILLABLE + 統一 label「請假(待審)」+ attendance statusRows 顯示 + 後端 scope 補 session-status 分支。回歸測試 `sessionConsistency.test.js` + `LearningRecordLeaveExclusionTest::test_pending_lr_on_leave_requested_session_is_excluded`。


## 2026-07-08 — fix: 週日課程的月結金額不再算成 0 元

Fixed：排在「週日」的月結課程，續約時系統算不出堂數，繳費金額會顯示 0 元（新店 6/30 回報的繳費通知問題）；現已修正，週日堂次會正確計入金額與課表。

開發備註：`buildSessionsFromWeeklySchedule` 用 Carbon `dayOfWeek`（0=日）比對 ISO 星期（7=日）的 slot，週一～六兩套慣例值相同、唯獨週日永不匹配 → 週日 date-mode 課程生成 0 堂 → renew-monthly 算出 SessionCount=0/Charge=0 → NT$0 Invoice（in-app #190／GitHub #1096，洪子勛案例）。修正 = slot weekday 正規化 0→7 後以 `dayOfWeekIso` 比對（兩套慣例都吃）；Import 與 shadow ScheduleResolver mirror 同步；`ScheduleSlots` 入庫一律存 ISO。回歸測試 `WeeklyScheduleSundayBuilderTest` + `MonthlyRenewTest::test_renew_monthly_sunday_course_computes_sessions_and_charge`。


## 2026-07-08 — fix: 課程資料欄位對齊，避免課程匯出／新增課程隨機失敗

Fixed：修正一個內部資料欄位不一致問題，該問題可能讓「課程匯出」或部分「新增課程」流程出現錯誤，現已對齊。

開發備註：schema drift 對齊 — `StudentClass.RoomID` 已於 2026-06-30 在 production 被手動 migration 移除（batch 107/108，出自未合併的 `815ad275`），但 main 程式碼仍讀寫該欄位（Export 明確 SELECT、StudentClassController/CoursePackageController/Import 寫入、Model fillable）。本次把兩個 migration 檔＋後端 RoomID 移除 port 回 main（不含 `815ad275` 的行事曆前端與 #1087/#1079 回退部分），Export 改 SELECT `room_id` 保持 CSV 欄位對齊；121 個測試檔的 RoomID payload 一併清除；新增 `StudentClassRoomIdSchemaDriftTest` 鎖定 CI schema == production schema。

## 2026-07-08 — ops: 部署管線硬校驗 — 杜絕「回報成功但上的是舊版」

Ops：部署流程加上目標版本硬校驗：抓取失敗立即中止並亮紅，部署完成的版本必須等於 CI 驗證過的那一版。

開發備註：Pi repo config 被誤寫 `http.sslbackend=schannel`（Linux git 不支援）→ `git fetch` fatal；deploy step 無 `-e` 吞錯、`reset --hard origin/main` 落在 stale tracking ref，smoke 照樣綠。修正 = deploy.yml [1/7] self-heal unset + fetch fail-fast + `reset --hard $workflow_run.head_sha` + HEAD 校驗（§R62）。

## 2026-07-08 — fix: 同時段不同學生的堂次不再被合併吃掉（課程管理／班級行事曆）

Fixed：一對二／一對三同一時段的不同學生，畫面上會被合併成一筆導致其中一位漏顯（例如班級行事曆只看得到其中一位），已修正。

開發備註：`classSessionsApi.mergeSessionViewModels` slot key 原本只有 `(date,startTime)`，整包 payload 先合併再分課程 → 跨學生互吞（Phase C1 refactor `5bfaf4bd` 引入；R49/#187/#188 共享時段家族；in-app #182「仍存在」真兇）。key 補課程身分 + unkeyable 不合併；新增 `classSessionsApi.test.js` 掛入 `test:calendar`。

## 2026-06-29 — fix: ClassSession projection API — calendar completeness-safe (no pagination)

- **Fixed**：新增 `GET /api/v1/class-sessions/projection`（`api_kind: projection`, `completeness: full`），行事曆改走此端點，杜絕 list API `per_page=2000` 靜默截斷導致新莊等分校缺課。
- 開發備註：`ClassSessionProjectionTest`；SOP 見 `docs/GUIDE_PROJECTION_INTEGRITY.md`。

## 2026-06-29 — fix: calendar ClassSession branch projection aligns with course room campus

- **Fixed**：行事曆週檢視改以 `branch_id + 日期區間` 載入全部 `ClassSession`（不再綁定已篩選課程 ID）；分校篩選與課程管理一致（有教室用 `rooms.campus_id`，無教室用學生 `CampusID`），修復新莊等分校「出缺勤有課、行事曆缺課」。
- 開發備註：`ClassSessionBranchCampusFilterTest`；`useCalendarDataLoad` 補 session-only 課程 stub 供 `mergeWeekCalendarOccurrences` materialized pass。

## 2026-06-29 — security: untrack legacy PII dumps + backend-local stub

- **Security**：`git rm --cached` 19 個 `backups/**/*.sql.gz`（production PII，runtime 備份在 Pi `/home/admin/backups/`）與整個 `backend-local/`（Windows mock，`.gitignore` 早已排除但仍被追蹤）— 清除 Dependabot `path-to-regexp`/`qs` alerts
- 開發備註：secret-scanning #1（`AllTrue (3).sql` 歷史 blob）仍需 filter-repo + BotFather revoke（#1025）

## 2026-06-29 — security: npm 依賴修補 + composer audit gate 修正

- **Security**：前端升級 `vite` 6.4.3、`@vitejs/plugin-vue` 6.x（修補 GHSA path traversal / esbuild dev-server）；`jsdom` 連帶 `undici` 7.28.0；`npm audit --audit-level=high` 清零
- **Security**：CI `composer audit` 解析 bug 已定位（advisory dict 漏掃 HIGH）— 修正待 TD-014 Laravel upgrade 後一併上線，避免在 framework 未修補前誤擋 merge
- **Security**：`guzzlehttp/guzzle` constraint 升至 `^7.12.1`（lock 已 7.12.3）
- 開發備註：GitHub secret-scanning #1（Telegram bot token）與 Laravel 8→12 major upgrade 仍為 open blocker（#1025、TD-014）

## 2026-06-28 — fix: 班級行事曆漏顯已調課堂次 + LINE 綁定讀家長手機

Fixed：班級行事曆若週次篩選暫時隱藏某課程，已實際存在的堂次仍會顯示，與課程管理詳情一致。LINE 官方帳號「綁定 姓名 手機」改與家長入口相同，優先比對「家長手機」欄位。

開發備註：`calendarOccurrenceMerge.js` materialized pass 改掃 `allCourses`（#1035 / in-app #182–184）；新增 `StudentContactPhone` + `LineWebhookBindingTest`（§R10 LINE bind 對齊）。PR #1036、#1037。

## 2026-06-28 — security: secret exposure remediation (HEAD cleanup + webhook hardening)

- **Security:** Remove tracked `.env.monitor` and `.cursor/projects/**` from git; add `.env.monitor.example` and [`SECURITY_CREDENTIAL_ROTATION.md`](SECURITY_CREDENTIAL_ROTATION.md).
- **Security:** Mask campus swipe/Telegram secrets in `AdminCampusController` API (#975).
- **Security:** Telegram webhook `X-Telegram-Bot-Api-Secret-Token` validation (#1021) + `TelegramWebhookSecret` column.
- **Ops:** Add `scripts/security-filter-repo.sh` and `scripts/security-gitleaks-audit.sh` for pre-public history purge.

---

- **Changed**：後端 `ClassSessionMaterializationService::upsertSlot` 為唯一 production 寫入路徑；`session-dates` / `class-sessions` API 分開回傳 materialized 與 projected。
- **Changed**：前端 `classSessionsApi.js` 統一 `SessionViewModel`；課程管理、評量頁、行事曆 adapter 消費同一模型（含 legacy 欄位別名）。
- **Added**：`classsession:audit-duplicates` 唯讀稽核指令。

## 2026-06-27 — fix(course-mgmt): 課程重疊建立改走 in-app 強制建立視窗，不再卡死路 (in-app #174)

新增固定課程時，若和學生既有「同一位老師、同科目、上課日期重疊」的課程衝突，過去會跳出提示叫你「勾選強制建立」，但畫面上根本沒有那個勾選框，等於卡死路。現在改成跳出視窗，讓你選「加購堂數、延續原課程」或「我知道，仍要新增課程」。

開發備註：#805 後端新增 `overlapping_active_course` 409，但前端 `universalSchedulerApi.js` 只把 `duplicate_active_course` 設成 `isDuplicateCourse`，重疊碼落到 `UniversalClassScheduler.vue` 的原生 `alert(err.message)` → 無 force 入口。抽出無相依純函式 `isDuplicateInterceptCode()`（node 測試可直接 import）讓兩碼都導向攔截 modal；回歸測試加在 `universalSchedulerApi.test.js`（build 腳本會跑）。**Ops 例外**：GitHub Actions minutes 用完期間，依 `OPERATIONS_RUNBOOK.md` §139 走緊急手動前端部署——本機 `npm run build` 綠 → `rsync dist_build` → Pi `copy-to-backend.cjs`（含 index/asset 一致性 guard + OPcache flush）→ version `acf1251`，已驗 health ok、`assets/*.js` 皆 200 `text/javascript`、served chunk 含修正後 `isDuplicateInterceptCode`。**未動 Pi git／storage**（只覆蓋 `backend/public` 前端 bundle，已先備份至 `backups/emergency/pre174_*`）。待 Actions 恢復補 PR（branch `fix/course-overlap-force-create`）回 main。GitHub #931。

## 2026-06-21 — fix(parent): 家長 LINE 自動登入（共用網域分校）＋ 共用方案堂數顯示

家長從 LINE 開啟入口時，會依「所屬分校」載入正確的 LINE 入口，自動登入更穩定；若帳號尚未綁定，畫面會清楚告訴你「請用學生姓名＋手機登入，或先在 LINE 完成綁定」，不再卡在「正在自動登入…」又同時跳紅字錯誤的矛盾畫面。另外，多科共用同一方案堂數時，每一科會標示「共用方案」並顯示同一份共用剩餘堂數（扣堂一起計），剩餘總數不再被各科重複加總。

開發備註：**Bug 1（LINE 登入）**根因＝13 新莊中平與 15 大安共用 `daan.lifenet.com.tw`，但各自是不同 LINE Login channel／provider（同一學生在不同分校的 `line_user_id` 不同已於 prod 證實）。`resolveLiff()` 純 host 比對只回「第一個」分校（id 升序＝13）的 LIFF，導致 15 大安家長（19 筆綁定）拿到 13 的 LIFF → `getProfile().userId` 屬不同 provider → `loginWithLine` 查無綁定 404。修法：入口連結本就帶 `campus_id`（`LineWebhookController::getPortalUrl`），`resolveLiff` 改**優先用 `campus_id`** 定位該分校 LIFF；前端 `onMounted` 以 `campus_id` 解析 LIFF 覆蓋 build-time 預設。前端另把「自動登入失敗」從矛盾文案改為明確綁定/手動登入指引（`autoLineNotBound`）。**Bug 2（共用方案）**：家長 dashboard 對 `PackageID>0` 成員改以 `course_packages` 池子（remaining/used/total）為準，`sessionMetrics` 與顯示聚合每池只算一次，新增 `is_package`/`package_*` 欄位前端標示「共用方案」。新增 ParentPortalSharedPackageTest(2)、ParentPortalResolveLiffTest(2)；既有 Parent/Package/Session/StudentClass 315 綠、PHPStan clean。對應 in-app 家族 #158/#162。**不動收款/invoice/費率**，僅顯示與登入解析。

## 2026-06-14 — Ops: GitHub / SRE roadmap 對標大公司治理

開發備註：新增並整理 AllTrue Engineering Roadmap：M4 生產安全與流程自動化（#867–873）、M5 UI/UX 質感與可讀性（#866/#857–865）、M6 GitHub 治理與協作成熟度（#875–880）、M7 系統維護與 SRE 營運成熟度（#881–886）。Project board 已建立並連到 repo；`docs/SOP_MATURITY.md` 補上 Actions minutes 用完時的工作分流、GitHub Environments/CODEOWNERS/Project automation/release traceability/security advisory/ruleset 缺口，以及 PITR、Full server DR、incident response、observability、capacity management、maintenance window/status page 等維運缺口。純治理/文件/issue 規劃，無 production code 變更。

開發備註：補充 M8 資安/隱私/合規成熟度（#887–892：host hardening、IAM access review、PII inventory/retention、sensitive audit coverage、Threat modeling/ASVS、vendor risk register）與 M9 工作流程/組織營運 SOP（#893–898：service catalog/RACI、SOP review cadence、support SLA metrics、ADR/RFC、release train、AI/human onboarding）。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。純治理/文件/issue 規劃，無 production code 變更。

開發備註：補上「軟體公司跨部門 operating model」規劃，依 IT / SRE / Security / Engineering / QA / Product / Support / Data / Legal / Docs 視角新增 #899–908（RFID/device inventory、weekly ops review、data quality checks、security exception register、privacy request SOP、technical health scorecard、role-based QA matrix、product health review、public reply macro library、quarterly roadmap review）。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。純治理/文件/issue 規劃，無 production code 變更。

開發備註：依老師/主任/家長三種正式使用者視角做唯讀體驗審查，新增 #909–912：老師端 System Trust 分眾文案 bug（in-app #167，attachment #112）、老師首頁下一步說明、主任 cockpit drill-down/explanation layer、家長狀態時間線與主動通知。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。未改 in-app 狀態或留言、未動 production 資料。

開發備註：完成 GitHub milestone hygiene：關閉舊 Phase 1/2/3 milestones（#1–#3，皆 0 open），M1/M2/M3（#4–#6）維持已關閉；active roadmap 收斂為 M4–M9。將未歸檔的 in-app UX bugs #851/#855 併入 M5，避免「no milestone」漏追。同步更新 `docs/SOP_MATURITY.md`。純 GitHub metadata / docs 整理，不耗 Actions minutes。

開發備註：Actions-down 高價值工作交接（#907 / #851 / #855 / #909）。新增 `docs/GUIDE_SUPPORT_REPLY_MACROS.md`（10 個 in-app bug 公開回覆白話 macro，含公開留言＋內部備註＋禁用詞檢查＋對應狀態機，對齊 §3.8）並補 `docs/INDEX.md` 入口；對 #851/#855/#909 補 triage（白話問題＋驗收條件＋blocked-by-deploy，唯讀未改 in-app 狀態）；補 metadata（#851/#855 priority+area+status:blocked，#867/#870 status:blocked）；`docs/SOP_MATURITY.md` 補每 milestone Top 3、狀態分類與「CI 凍結時工程師 playbook」。純 docs / GitHub metadata，無 production code 變更。

## 2026-06-14 — feat(attendance): 出缺席新增試聽/輔導/值班/補課/停課狀態 (#765)

點名時除了到班/遲到/請假/缺席，新增「試聽有到、試聽未到、輔導有到、輔導未到、值班、補課、停課」等狀態（補登/詳細選單可選）。各狀態自動套用正確的扣堂與計薪規則：補課會扣堂並計薪、值班計薪但不扣堂、試聽/輔導不扣堂也不計薪、停課皆不算。既有四狀態行為完全不變。

開發備註：抽出 `App\Support\AttendanceStatus` 單一真相 registry（label/deductible/payable/requires_log/session_status），扣堂集（AttendanceController）與計薪集（FinanceController payroll，值班 duty 計薪不扣堂為唯一刻意差異）、session 狀態映射（AttendanceEffectsService，makeup→attended）全部路由到 registry。AttendanceStatusSemanticsTest 15 綠釘住競品表 + 241 attendance/payroll/finance 回歸全綠（零回歸）。requires_log 元資料供 #768 漏交追蹤。PR #837；GitHub #765。

## 2026-06-14 — feat(schedule): 批次排課 CSV 匯入前衝突檢查 (#770)

提供「排課衝突預檢」：上傳批次排課 CSV，系統在寫入前逐列標出「同時段同教室／同老師」衝突（紅）與「學生同時段已有課」警告（黃），避免撞堂撞教室。

開發備註：`POST /api/v1/schedule-import/preview`（純讀取）：解析 CSV，對 DB 既有非取消堂次 + 同檔對稱檢測時間重疊衝突 + 格式驗證。ScheduleImportPreviewTest 2 綠。原子 execute（實際建課）因扁平 CSV 缺計費欄位另行設計。PR #839；GitHub #770。另：`GET /api/v1/teaching-logs/missing`（#768）回傳各老師需教學日誌但逾 24h 未填的堂次清單（requires_log + 無 LearningRecord），PR #838。

## 2026-06-14 — style(ui): 全站 Toast 統一 + UI 去 AI 化逐頁/元件治理 (#687 系列)

成功/錯誤/復原提示改為全站一致的統一 Toast（白底 + 左語義色條），不再各頁樣式不一。同時完成「UI 去 AI 化」逐頁與共用元件治理（金流/老師/出缺勤/儀表板/行事曆等頁 + 表單/Modal/排課器等元件），移除硬編色票改用設計系統 token，介面更統一專業。

開發備註：純視覺、零行為變更（HSL codemod 僅作用 `<style>`+inline，計算 byte-identical）。新增 `useToast`/`AtToast`（#708）與 `AtInput/AtSelect/AtTextarea/AtField`（#702）設計系統元件。逐頁/元件 PR #820–#849；hex 大幅下降。GitHub #687/#693/#694/#695/#696/#699/#700/#701/#702/#703/#704/#708。

## 2026-06-13 — fix(schedule): 建課偵測「同生同科同師日期重疊」防重複排課 (#805)

主任建立課程時，若該學生已有「同科目、同老師、上課期間重疊」的進行中課程（常見於續報新期起始日早於舊期結束），系統會先提醒，避免兩期在重疊週各排一堂、造成點名名單同一時段重複出現。可改用「加購堂數」延續原課程，或把新課起始日改到舊課結束之後；確定要建立仍可勾選強制建立。

開發備註：`EnrollmentService::store()` 既有 `duplicate_active_course`（同科同型）外，新增 `overlapping_active_course`（同 StudentID+SubjectID+TeacherID 且 StartDate/EndDate 區間重疊，跨 class_type 亦偵測），回 409 + 重疊明細，`force=true` 可覆蓋。日期來源涵蓋 confirmed/future_dates 與 session_plan。對應 in-app #161／GitHub #805／復發家族 F1「重疊續報」變體。OverlappingCourseGuardTest 2 綠。資料修正（林立晴 SC#1684）另行處理。

## 2026-06-13 — security(pin): 老師頁 PII 後端欄位級遮罩 (TD-066)

開發備註：補上 #769 老師管理頁的後端 PII 邊界。`GET /teachers`／`/profiles` 為多頁共用端點（CourseManagement／StudentsList／LearningRecords 下拉復用），無法整路掛 require_pin。改抽 `App\Support\PinGate::isUnlocked()` 單一謂詞（super_admin／未設 PIN／token 已驗證 → 通過），`RequirePin` 改委派它（行為不變、去重），`ProfileController::index` 三個輸出點在未通過時遮罩老師 `phone／line_id／rfid／rfid_by_branch`。soft：未設 PIN 者零回歸；下拉頁本就不讀 PII 故無感。TeacherPiiPinRedactionTest 3 綠 + PinVerificationTest 14 綠；PHPStan baseline 納入 PinGate 的 AuthToken::where magic（零刪除）。TD-066 結案。計畫 `.cursor/plans/td066_teacher_pii_pin_2026-06-13.md`。

## 2026-06-13 — security(pin): 敏感頁 PIN 二次驗證前端 gate + 路由強制 (#769 Phase B/C)

開發備註：接續 Phase A，完成前端覆蓋層與後端強制（**soft，零回歸**）。**D1 soft**（未設 PIN 的主任可「暫不啟用，直接進入」）／**D2** 受保護頁＝兼職薪資、帳務中心、當月學收、老師管理／**D3** super_admin 不納管。Phase B：`PinLockModal.vue` 全螢幕覆蓋（設計系統 token、無 emoji；set／verify／locked／reset 四態、4–6 位數字、Enter 送出），`App.vue` `pinModalActive` gate 擋住 4 頁直到解鎖、10 分鐘解鎖 TTL、閒置 5 分鐘 + 切分頁 60 秒自動鎖（`POST /pin/lock`）；純判定抽到 `lib/pinGate.js`（15 個 node 測試，接進 build 鏈）。Phase C：`RequirePin` 經 `auth_role` 放行 super_admin（mirror `RequireRole`）；`require_pin` 掛於受保護頁**專屬**敏感端點（`finance/parttime-payroll*`、`finance/teacher-payroll`、`part-time-rate-cards*`、`finance/branch-monthly-tuition*`、`accounting/payments*`、`accounting/settled-courses`），**刻意不掛**共享端點（`teachers`／`student-classes`／`alerts/tuition`，避免誤傷已設 PIN 主任）；router 內省測試守住「該掛有掛、共享沒掛」。PinVerificationTest 14 綠、PHPStan clean。PR #815／#816；GitHub #769。老師頁 PII 後端邊界因端點共享延後 → TD-066。

## 2026-06-13 — security(pin): 敏感頁 PIN 二次驗證後端基建 (#769 Phase A)

開發備註：為薪資／財務／教師個資敏感頁的 PIN 二次驗證鋪設後端 primitives，**零行為變更**（未掛任何受保護路由，未設 PIN 者敏感 API 照舊可用）。新增可逆 migration（`User.pin_hash／pin_failed_attempts／pin_locked_until／pin_set_at`、`auth_tokens.pin_verified_until`，皆 nullable）、`PinVerificationController`（status／set／verify／reset／lock）、`RequirePin` middleware（soft：未設 PIN 放行，已設未解鎖回 423 `pin_required`）、Kernel alias `require_pin`、`me/pin/*` 路由（含 per-IP throttle）。失敗計數／鎖定一律走 DB（避開事故 E 的 file cache owner 污染）；解鎖狀態綁 `AuthToken` session，登出即失效。弱碼黑名單 + bcrypt 雜湊，回應不含 hash／attempts，429／423 generic。PHPUnit 12 綠涵蓋 AC1–AC8。PHPStan baseline 為新 Eloquent magic props 重產（619→624 distinct，零刪除）。PR #812；GitHub #769。Phase B（`PinLockModal.vue` 前端覆蓋層 + 自動鎖）／ Phase C（受保護路由掛 `require_pin` soft）後續，需 UX 驗收與 D1–D3 拍板。

## 2026-06-13 — fix(perf): 行事曆載入大幅加速 (#804)

行事曆（含跨分校、整月視窗）載入原本在資料較多時要數秒到數十秒，現已調整為約 0.1 秒內完成，主任／老師開行事曆會明顯變快。

開發備註：production EXPLAIN/ANALYZE 確認瓶頸為 `ClassSessionController::index()` 的 `si`（最新簽到）derived table 因 `StudentSingIn` 缺 `ClassSessionID` 索引被 access=ALL 全表掃描（r_loops≈4471 × ~4609 列 ≈ 2060 萬列，全 campus 視窗 ~33.5s）；對照 `LearningRecord` 有對應唯一索引故走 ref。補 `StudentSingIn(ClassSessionID, id)` 非唯一索引後 33.5s→~0.1s（si ALL→ref，部署後 ANALYZE 驗證）。純索引、byte-identical。候選「缺 SessionDate 索引」經 EXPLAIN 否證（日期範圍已由 `cs_scid_sessiondate_idx` 處理）。PR #810；GitHub #804；in-app #160。附 revert-proof schema guard 測試。

## 2026-06-13 — fix(audit): 排課稽核日誌實際生效 (#766 補修, #784)

主任端的「排課稽核日誌」（誰在何時建立／修改／刪除課堂）先前因技術問題完全沒有寫入、且依分校查詢一律為空；現已修正，會正確記錄並可在主任端依分校／日期查詢。系統自動產生的行事曆投影堂次不列入稽核（只記真人操作）。

開發備註：三個 root cause —（1）`AppServiceProvider` 在 `DatabaseServiceProvider` 之前註冊，`boot()` 時 Eloquent dispatcher 尚未綁定，`ClassSession::observe()` 靜默 no-op → 改用 `app->booted()` 延遲註冊；（2）`branchId()` 讀不存在的 `StudentClass->BranchID` 導致 `branch_id` 恆為 null → 改走 `StudentClass->Student->CampusID`；（3）observer 生效後對 `projected-*`／backfill 系統堂次造成行事曆熱路徑 N+1 → 依 `Note` marker 略過。PR #784；GitHub #766；本地 MySQL 全測 1184 綠 / PHPStan 綠。另記 TD-065（`NotificationObserver` LINE 推播疑同源失效，未在本次 scope 處理）。

## 2026-06-13 — fix(billing): 課程總費用不再被錯誤舊差額卡死（#798）

課程總費用與「每堂費用 × 堂數」對不上、又沒有單堂時間調整紀錄時，重新儲存費率即會重算為正確金額，不會再被舊的錯誤數字永遠拉回（新店分校張同學案例，金額已同步修正）。

開發備註：`StudentClassController::update()` preservedDelta 改為僅在存在 `ClassSession.session_charge` 調整時保留；PR #801；GitHub #798；in-app #159；一次性資料修正 SC#422 8000→8800（CEO 批准）。復發家族 F7。

## 2026-06-13 — fix(billing): 改「未繳費」遇收款紀錄改為明確提示（#799）

課程有收款入帳紀錄時，把繳費狀態改成「未繳費」不再悄悄跳回「已繳費」：系統會直接說明哪一天已有收款、請先到收費頁作廢，避免主任白改好幾次。

開發備註：後端 409 `payment_record_locked`（含金額/日期 warnings，涵蓋 payment_status 與清空 paid_at 兩路徑）；CourseManagement／StudentsList 移除「API 失敗仍本地假成功」死碼；PR #802；GitHub #799；in-app #158。復發家族 F7。

## 2026-06-13 — fix(learning): 老師底部「評量」紅點與評量頁未填數一致（#788）

老師版底部導覽的紅點數字，現在會把本週（週一到今天）已上課但還沒填的評量一併算進去，跟評量頁顯示的「未填」數量一致，不會再出現頁面寫 2、紅點只寫 1 的情況。

開發備註：`learning-pending-summary` 新增 `week_attended_sessions_without_record` 並計入 total；PR #792；GitHub #788；in-app #157。

## 2026-06-13 — fix(director): 主任儀表板「系統內完成率」不再超過 100%（#786）

完成率改為每堂課最多計一次：之前同一堂課若有多筆評量紀錄會被重複計算，導致比率超過 100%，現已修正並以 100% 為上限。

開發備註：`AdoptionInsightsController` 分子改以最新非空 Progress 的出席 ClassSession 計數並 cap 100；PR #791；GitHub #786；in-app #156。

## 2026-06-07 — feat(calendar): SmartCalendar composables 剝離完成（#740 Step 7）

- `useCalendarDataLoad` / `useCalendarLeaveExtra` / `useCalendarSubstitute` / `useCalendarReschedule`
- `SmartCalendar.vue` **5260 → 3308** 行；拖曳調課 handler 仍留父層
- P4-b：`GET /api/v1/student-classes` 支援 `start`/`end` 視窗過濾 + 前端傳參
- 測試：`npm run test:calendar` 全綠（含 4 組 composable vitest）

開發備註：PR #773/#777/#778/#782/#787/#789；行數 <3000 留作 Step 7c（course-edit composable）後續。

## 2026-06-07 — feat(audit): schedule_audit_logs + ClassSessionObserver (#766)
- Added `schedule_audit_logs` 資料表，記錄課堂建立／更新／刪除的完整 old/new JSON 快照及操作人員
- Added `ClassSessionObserver`，自動在每次 `ClassSession` CRUD 時寫入稽核日誌
- Added `GET /api/v1/schedule-audit` API，支援分校／日期範圍／課堂 ID 篩選（分頁）

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
