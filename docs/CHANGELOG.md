# AllTrue Changelog

> 格式：每條一行，分類 Added / Fixed / Changed / Security / Ops  
> 細節查 PR 說明或 `.cursor/plans/`  
> **版本公告（給老師／主任看的短卡）**：同一版建議 **第一條寫使用者白話**；技術細節請另起一行並以 **`開發備註：`** 開頭（`npm run sync-release-notes` 會略過不進 `releaseNotes.generated.js`）。  
> **閱讀**：依日期標題搜尋；本篇很長屬正常，**勿逐行通讀**。  
> **舊記錄（2026-04-19 以前）**：[CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)（archive，只搜尋）

---

## 2026-06-06 — feat(ui): 課程管理頁首與篩選列去裝飾、對齊設計系統（#691 第一階段）

課程管理頁的頁首從浮誇的漸層光暈 hero（多層放射/旋轉光暈、超粗大標題）收斂為乾淨的白底卡片，標題字級字重回到後台應有的沉穩感；篩選列、主要按鈕統一品牌色，整體更專業、更好掃讀。

開發備註：#691 reference page 治理第一階段（頁首 + 篩選列，內容區與 modal 留後續 PR）。`CourseManagement.vue` `<style>`：(1) 移除 `.course-page::before` 背景 gradient mesh 光暈、`.course-header-card::before`（grid mask）與 `::after`（conic 旋轉光暈）三組裝飾偽元素。(2) `.course-header-card` 改 `var(--ds-canvas)` + `--ds-hairline` + `--ds-shadow-1`，圓角 24→16。(3) `.page-title` font-weight 950→700、clamp 3.6rem→2rem；`.command-kicker` `#7dd3fc`→`--ds-ink-mute`、字重 900→700。(4) `.meta-pill`/`.btn-soft`/`.filter-bar`/`.filter-field` 色票全改 `--ds-*`，移除 inset 高光與 hover transform/大陰影。(5) `.btn-accent` 主 CTA 由深色 gradient → 實心 `--ds-primary`，hover `--ds-primary-deep`。`npm run build` 通過。



左側選單目前選中項目改為更沉穩的「左側色條 + 品牌色淡底」（參考大型後台軟體做法），取代原本較搶眼的漸層光暈；待辦數字標記顏色統一為品牌色與警示紅，整體更專業一致。

開發備註：#698 App 外殼治理第一階段（側欄）。`styles.css`：(1) 新增 `--sidebar-active-wash`/`--sidebar-active-bar`/`--sidebar-badge-bg` token（light + dark 各一組）。(2) `.sidebar-nav button.active` 移除舊 indigo gradient + indigo 外陰影（殘留 `rgba(83,58,253,*)`），改 `inset 3px` 左色條 + 半透明品牌色淡底。(3) `.nav-badge` 硬編碼 `#ff7043` → `var(--sidebar-badge-bg)`；urgent `#d32f2f` → `var(--ds-danger)`。`App.vue` loading 文案 `載入中...` → `載入中…`（`GUIDE_UI_COPY`）。`npm run build` 通過。topbar / 導覽 FAB / update-banner 留後續 PR。



啟動 UI 去 AI 化的元件化基礎建設：建立 4 個只吃設計 token 的共用元件，後續各頁面逐步替換，讓全站按鈕、卡片、空狀態、數字卡視覺一致。

開發備註：新增 `frontend/src/components/design-system/`（AtButton：primary/secondary/ghost/danger × sm/md，primary 改實心非 gradient；AtCard：default/inset + header/actions slot；AtEmpty：Material icon + 標題 + 下一步說明，禁 emoji；AtMetric：`tabular-nums` 數字 + delta tone + accent 邊條）+ README（用法 + 禁止清單）。全部僅消費 `--ds-*` token，零硬編碼色。示範：`LearningRecordsPage` 上一堂摘要空狀態改用 `AtEmpty`、loading 文案改全形省略號（對齊 `GUIDE_UI_COPY.md`）。`npm run build` 通過。Epic #687 Sprint 0 基礎建設。



開發備註：批次完成 Epic #687 文件/基礎建設層：(1) 新增 `docs/GUIDE_UI_COPY.md` — 空狀態公式、loading/error 規範、placeholder/按鈕文字規則（Closes #690）。(2) 新增 `docs/GUIDE_DESIGN_QA_SMOKE.md` — 逐角色 smoke 路徑 + 上線後 OPS 確認（Closes #705）。(3) 新增 `scripts/design-hex-count.sh` + `docs/design-hex-baseline-2026-06-06.json`（grand total 3800 hex，作為 #687 KPI baseline）+ `npm run metrics:design-hex`（Closes #706）。(4) `.github/pull_request_template.md` 新增 Design System 檢核區塊（Closes #697）。(5) `docs/RULE_DESIGN_SYSTEM.md` §9 新增 Rollout Tracker 表格連結所有子 issue（Closes #709）。(6) `docs/INDEX.md` 前端開發章節補 UI_COPY_GUIDE / DESIGN_QA_SMOKE 導航。(7) README：頁面數 30→33、近期重點更新改 2026-06、補 ReleaseNotesPage / BranchManagementPage。



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

## 2026-05-31 — chore: OSS 供應鏈深掃（OSV-Scanner），不等 GHAS（#544 完成）

開發備註：#544。供應鏈把關不依賴付費 GHAS：PR 逐次已由 `composer audit`（HIGH/CRITICAL 擋 merge）+ `npm audit --audit-level=high`（皆 required）覆蓋；新增 `osv-scanner.yml` 以 Google OSV-Scanner 每週深掃 `composer.lock`/`package-lock.json`（OSS、OSV.dev 資料庫、`upload-sarif=false` 不需 code scanning）。僅排程／手動觸發（掃受信任 main），規避官方 action 在不受信任 PR 的輸出注入風險（osv-scanner#2749）。`dependency-review.yml` 保留為購買 GHAS 後的逐 PR 升級路徑。控制矩陣見 `OPERATIONS_RUNBOOK.md` §R1c。

## 2026-05-31 — feat: 補課部分時數可按比例扣堂（#613 完成）

補課如果只上了一部分時間（例如原本 2 小時、只補 1 小時），系統現在會依「實際上課時間」按比例扣堂，不再一律扣整堂；學生會保留剩下的時數。

開發備註：#613（A1 minutes-based，PR1 #636 + PR2 #637 + PR3/PR4）。扣堂改以「分鐘」為權威單位（`StudentClass.PurchasedMinutes/RemainingMinutes`、`session_deduction_ledger.minutes`），`RemainingSessions` 變成 ROUND_HALF_UP 整數衍生顯示值（整數運算、無浮點）。比例扣堂**只**作用於 `schedules.type='extra'` 的補課且實際時長 < 每堂分鐘；正常課堂與完整時長補課維持整堂、byte-identical。單一 chokepoint `SessionDeductionService::deductOnAttendance` 自載 ClassSession 算分鐘；reverse 會沖回對應 deduct 的分鐘避免漂移；課程列表端點對 fractional 餘額不再以 count-based 覆寫並回傳精確 `remaining_minutes`。涵蓋 swipe／手動點名／堂次狀態／評量核准四個觸發點。

## 2026-05-31 — chore: PHPStan 升為 backend PR required gate（#545 完成）

開發備註：#545。PHPStan 在移至 GitHub-hosted runner + baseline、連續 3 個 PR（#632/#633/#634）綠燈後，正式加入 `main` required status checks（共 7 項）。job-level `if` 使非 backend PR skip＝中性、不卡 docs/前端 PR；baseline 豁免既有問題，只擋新增。回滾指令見 `OPERATIONS_RUNBOOK.md` §R1b。對齊 Google「static analysis presubmit」實務（Epic #535 Phase 1.4）。

## 2026-05-31 — chore: Epic #535 ops 收尾（release 標題清理 + DORA 月度 review SOP）

開發備註：#535。(1) `release.yml` 產生 Release 標題時清掉 `## YYYY-MM-DD — ` 前綴，標題變為 `vTAG — <type: title>`。(2) `OPERATIONS_RUNBOOK.md` §Y 新增 DORA Metrics 月度 review SOP（四指標來源、判讀門檻、每月檢查指令）。(3) §Z 文件化 Pi Health 告警門檻（Phase 2.4 Exit：磁碟 85/95%、溫度 75/85°C+throttle、備份 8h/24h、UptimeRobot），與 `pi-health.yml` 對齊。Phase 0.3（pre-commit 呼叫 git-index-audit）確認既有 `scripts/install-git-hooks.sh` 已實作。純 CI/docs。

## 2026-05-31 — chore: 自動 CalVer tag + GitHub Releases（Epic #535 Phase 3.1/3.3）

開發備註：#535 Phase 3.1/3.3。新增 `.github/workflows/release.yml` + `.github/scripts/changelog-latest.sh`：當 `docs/CHANGELOG.md` 變更合併進 `main` 時，自動以最新節標題日期建立 `vYYYY.MM.DD[.N]` tag + GitHub Release（notes 取該節全文）。採 CalVer 而非 SemVer，因本系統為持續部署的內部應用、無對外 API 相容性語意（理由與 version.json↔tag 對照見 `OPERATIONS_RUNBOOK.md` §X）。獨立工作流、不碰 `deploy.yml`；唯一副作用為建立 tag/Release，回滾為純中繼資料操作。本次 merge 後將產生首個 Release `v2026.05.31`。

## 2026-05-31 — chore: PHPStan 移至 GitHub-hosted runner + baseline（#545 選項 A 前置）

開發備註：#545（使用者選 A）。將 `Security Scan` 的 `changes` 與 `PHPStan Advisory (php)` job 從 self-hosted `wsl-ci` 移至 `ubuntu-latest`（消除單點，未來升 required 不會因本機 runner 離線卡死 backend 合併）；新增 `phpstan/phpstan:^2.0` dev 依賴 + `backend/phpstan.neon`（level 5、analyse `app`）+ `backend/phpstan-baseline.neon`（baseline 既有 1952 問題）。移除舊的 `|| true`：現在 PHPStan 會真正對「新增」問題報錯（既有問題經 baseline 豁免），本機驗證 `[OK] No errors`。仍維持 advisory（非 required）；待連續綠燈穩定後再於 #545 升為 backend PR required gate。composer audit 既有 advisories 皆 medium/low（laravel/symfony，與 phpstan 無關），不影響 HIGH/CRITICAL gate。純 CI/tooling，無 production 程式碼變更（deploy 用 --no-dev，不含 phpstan）。

## 2026-05-31 — chore: Docs Integrity 改 job-level skip 並納入 required（#543 完成）

開發備註：#543。`docs-integrity.yml` 移除 workflow-level `paths:` 過濾，改為 `changes` 偵測 job + `integrity` job-level `if`（非文件 PR → skip＝中性回報，不卡合併）；schedule/手動觸發一律跑。確認在每個 PR 都回報 `Docs Integrity Check` status 後，加入 `main` required status checks，#543 三目標全數完成（gitleaks / golden / docs-integrity）。剩 `PHPStan Advisory (php)` 因 self-hosted runner 單點維持 advisory（見 #545）。純 CI 設定。

## 2026-05-31 — chore: 強化 main 分支保護 required checks（#543 部分）

開發備註：#543。將 `gitleaks scan`（secret-scan.yml，每個 PR 無 paths 過濾）與 `Golden scenarios report`（ci.yml，`if: pull_request` job）加入 `main` required status checks。判準：只把「每個 PR 都會回報 status」的 check 設 required；workflow-level `paths:` 過濾的 check（如 Docs Integrity）若設 required 會讓不觸發的 PR 永遠 pending→卡死，故維持 advisory。`PHPStan Advisory (php)` 因跑 self-hosted runner（單點）暫不設 required（見 #545，待決策）。回滾指令與判準寫入 `OPERATIONS_RUNBOOK.md` §R1b。純文件/設定，無程式碼變更。

## 2026-05-31 — chore: 前端 UI smoke（Playwright）scaffold（#547）

開發備註：#547 / Epic #535 Phase 4.3。新增 `frontend/playwright.config.js` + `frontend/e2e/smoke.spec.js`（主任課程管理頁 + 老師 TeacherHome 載入兩條關鍵路徑）、`npm run test:e2e`、`@playwright/test` devDep。設計為「無 secrets 即 `test.skip()`」：未設 `SMOKE_BASE_URL`/`SMOKE_*_USER`/`SMOKE_*_PASS` 時整檔 skip、CI 仍綠，本機/CI 跑都安全。CI 走新增的 `ui-smoke.yml`（`workflow_dispatch` + 每週排程，**不掛每個 PR** 以省 Actions minutes，見 OPERATIONS_RUNBOOK §B2）。實際執行待 #537 提供 `SMOKE_*` secrets；屆時可把 spec 內 TODO 的 data-testid 補上讓選擇器更穩。npm audit gate 為 `--audit-level=high --omit=dev`，Playwright 帶入的 moderate dev 漏洞不影響 CI。

## 2026-05-31 — perf: 老師當日課表載入更快（class-sessions N+1 批次化，#546）

開發備註：#546 / TD-018。`ClassSessionController` 兩處迴圈 N+1 清償：(A) `autoMaterializeTeacherMonthlySessionsForRange` 老師當日載入時，原每堂做 2 次 `exists()`（隨課數線性成長，TeacherHome/SmartCalendar 熱路徑）→ 改為 2 次批次 SELECT 預載「抑制例外 + 既有堂次」into in-memory set，以 `classId|HH:MM` 為鍵（TIME 欄位比較忽略秒，語意與原 SQL 一致）；同請求內建立後即更新 set 防重複。(B) `logSessionCountMismatches`（flag-gated）每課程一次 `SessionCount` → 單次 `whereIn pluck`。主查詢輸出 JSON 合約未變。Code review 發現主查詢的 Subject/schedules/campus 早已單一多 join（非 N+1）、所需複合索引已存在；剩餘的代課 correlated subquery 去索引化（Offender C）風險高，拆 TD-058 待 Sentry payload 對齊。回歸測試 `ClassSessionsTeacherAutoMaterializeMonthlyTest` 新增 query-count 不隨課數成長 + 無重複建立。純後端，無 schema 變更。

## 2026-05-31 — feat: 兼職老師薪資明細顯示「本段採用最高時薪」併堂說明（#614）

主任在兼職老師薪資明細裡，現在每一筆併堂（同時段多位學生）的堂次下方會多一行綠色說明：「本段採用 $X 最高時薪（同時段 N 位學生 · M 分）」，讓主任一眼看懂這段薪資是用同時段最高時薪計算、以及當下有幾位學生併堂。金額計算邏輯完全沒變，只是把原本算給你看的依據顯示出來。請重新整理頁面後使用。

開發備註：#614。併堂「最高薪 time-slice」計算（`FinanceController::buildConcurrencyBonusMap`）早已實作，本次純呈現：為該 private 方法加一個可選 out-param `&$segmentsOut`，在 n>1 的 sweep-line 區段回填 `{max_base, headcount, minutes}`（不動 `$bonusMap`/`$attributed`/`$segPay` 任何金額邏輯）；`parttimePayrollSessions` 取得 `$segmentsMap` 後在 session row 附加 `concurrency_segments`（僅歸屬 primary LR 有值，其餘為 `[]`）。前端 `ParttimePayrollPage.vue` 在堂次列下方加併堂說明列。FinanceController 非 DEV-forbidden 檔；附加欄位 non-breaking。測試 `PayrollConcurrencyTest` 新增 2 案（primary 帶段明細且金額不變 800、無重疊為空陣列），既有 10 案金額回歸全綠。

## 2026-05-31 — fix: 主任「單堂調課」不會再被系統自動還原回原本時段（#556）

修正石牌等分校回報的「固定排課課程，有幾天出現在錯誤時段」問題。原因是主任用「單堂編輯」把某一堂改到不同時間後，系統沒把它記成「刻意調整」，於是之後對該課程按「編輯→儲存」時，系統會誤以為這堂跑掉了、把它「拉回」原本的固定時段，覆蓋掉主任的調整。現在改好了：只要單堂改到跟固定排課不同的時段，系統會自動記成「已調整」、不再被自動還原；若改回原本時段則自動取消標記。請重新整理頁面後使用。

開發備註：#556 / TD-055。`ClassSessionController::applyTimeAndNoteUpdates` 有時間異動時呼叫新增的 `syncContractExceptionFlag`，依新 (weekday, start, duration) 是否吻合契約設/清 `IsContractException`（鏡像 `StudentClassController::sessionMatchesContract`，避免跨 controller 私有耦合）。flag=1 使該堂同時被 `schedule_drift` 偵測與 `syncFutureScheduledSessionTimes`(force_partial_rebuild realign) 排除，保留主任刻意時段；改回契約則 flag=0。語意定為「單堂刻意調課＝契約例外」（沿用 add-session 既有例外語意）。回歸測試 `StudentClassScheduleDriftExceptionTest` 新增 3 案（標記/清除/force_partial_rebuild 不還原）。純後端，無 schema 變更。

## 2026-05-31 — chore: 本地 pre-commit 加入 git index 稽核護欄（#542）

開發備註：#542 / Epic #535 Phase 0.3。`scripts/install-git-hooks.sh` 的 pre-commit 新增呼叫 `scripts/git-index-audit.sh protected`，commit 前若保護路徑（`backend/ frontend/ scripts/ .github/ docs/`）的 tracked 檔被 `assume-unchanged`/`skip-worktree` 隱藏即擋下（防 §R58 重演）。修正 `git-index-audit.sh` 過濾 bug：skip-worktree 在 `git ls-files -v` 為大寫 `S`，原 `^[hs]` 只抓小寫、漏掉 skip-worktree，改為 `^[hS]`（h=assume-unchanged、S=skip-worktree）。新增 `--uninstall` 一鍵卸載；`OPERATIONS_RUNBOOK.md` 補本地 hooks 安裝/卸載/bypass 文件。純本地 hooks，不影響 CI checkout。

## 2026-05-31 — feat: 家長回饋可以雙向對話了，老師／主任能直接回覆家長

以前家長在「學習評量」留給老師的回饋，老師看得到卻沒辦法回覆，家長也不知道有沒有被看到。現在升級成雙向對話：老師或主任可以在評量裡直接回覆家長，家長在家長入口就會看到回覆，還能再追問；只要家長有新訊息，老師端「家長回饋待看」的提醒（含側欄學習評量的紅點）就會亮起，回覆過就會消掉。回覆內容家長看得到，主任原本「給老師的內部評語」維持只有自己人看得到、不會外流給家長（請重新整理頁面後使用）。

開發備註：System A（`learning_record_feedbacks`）。新增 `learning_record_feedback_replies`（feedback_id idx、author_user_id、author_role=teacher/director/parent、parent_session_id、content）+ `last_read_by_parent_at`（idempotent migration 含 down）。員工端 `POST/GET learning-record-feedbacks/{feedback}/reply|replies` 放在 `role:teacher,director,super_admin` + `require_campus`，ownership 鏡像 `index`（teacher 限自己 teacher_id、director 限 campus_id）；員工回覆只標記該角色已讀、不 touch `updated_at`，避免其他員工假未讀。家長端 `parentShow` 回傳 `replies[]`+`has_unread_reply` 並標記家長已讀；`POST parent/learning-records/{lr}/feedback/reply`（author_role=parent、touch `updated_at` 重新觸發員工未讀、`throttle:20,1`）。員工 `learning-records` 與家長 portal 清單批次載入 replies 避免 N+1。前端：ParentPortal 對話串+追問+「老師回覆了」紅點；LearningRecordsPage modal 回覆框（家長可見，與內部 `teacher_comment` 嚴格分離）；TeacherHome/Director CTA 文案；`api.js` 新增 reply/replies/parentReply helpers。未讀徽章沿用既有 `me/unread-feedback-count`（touch 後自動涵蓋家長追問）。順手收斂安全漏洞：System B `parent-feedback/{for-teacher,read,reply,replies}` 原本在任何 `role`/`require_campus` 群組外（等同未強制認證），改納入 `role:teacher,director,super_admin`+`require_campus`。KPI 儀表板／System A/B 合併列為 Phase 2。測試 `LearningRecordFeedbackReplyTest`（5 案）。計畫 `.cursor/plans/Parent Feedback Reply Thread-88fe50aa.plan.md`。

## 2026-05-31 — fix: 修復評量被「上課狀態調整」作廢後無法重新填寫（#146）

某堂課的上課狀態被調整過（例如曾改成未上課、後來又改回已上課）時，原本那筆評量被系統自動作廢；改回「已上課」後評量沒有自動恢復，老師重新填寫會被擋住、顯示「此堂評量已作廢」。現已修正：只要該堂最後是「已上課／已排定」狀態，老師重填就會自動沿用原評量、不再被擋，且堂數不會重複計算（請重新整理頁面後使用）。

開發備註：#146 / GH#618。實例：陳嘉軒 5/31 12:30-14:30（LR#7737, CS#9426, attended，VoidReason='由已上調整狀態'）。根因：`ClassSessionController` attended→scheduled 以 `voidAttendanceArtifacts('由已上調整狀態')` 作廢 LR 並 `SessionDeductionService::reverseForSession` 沖回堂數；之後 scheduled→attended 走 generic 分支、不呼叫 `restoreVoidedLearningRecord`（僅 leave→attended 才還原）。`LearningRecordController::store()` 的 resurrect（#125/#495 R55）原只認 `VoidReason='一般請假'` → 永久 409。修復：新增 `SYSTEM_RESURRECTABLE_VOID_REASONS` 白名單（`一般請假`/`由已上調整狀態`/`補請假：已上課改請假`/`單堂標記請假`），CS 為 fillable 才 resurrect；resurrect 只設 pending + `SessionDeducted=false`，扣堂走核准流程，不重複扣堂；人工作廢維持 409。回歸測試 `LearningRecordVoidedResurrectTest` 新增 #146 案。PR #619。

## 2026-05-31 — fix: 修復切換頁面後整頁灰白遮罩、無法點選的問題（#143）

先展開／聚焦某位學生的課程後再切換到別的頁面時，畫面有時會卡住、像被一層灰白色蓋住而且點不動、也無法捲動。現已修正：切換頁面會自動解除鎖定，不再卡住（請重新整理頁面後使用）。

開發備註：#143 / GH#600。根因：`CourseManagement.vue` 聚焦學生時 `lockScroll()`（`useScrollLock` 對 body 套 `position:fixed/overflow:hidden`，模組級 reference count），`focusedStudentKey` watcher 只在 `key→null` 解鎖；聚焦中換頁時 `onUnmounted` 不觸發 watcher → scroll lock 洩漏、count 不歸零 → body 永久凍結。修復：`onUnmounted` 聚焦中補 `unlockScroll()` + `App.vue` 換頁 `forceUnlockScroll()` 防護網 + 關閉行動版選單；新增 `useScrollLock.test.js` 回歸測試納入 build 鏈。PR #616。

## 2026-05-31 — feat: 補課案件可標記「不補課」結案，遇到不需補課的學生不再卡在待安排（#144）

主任在總覽的「補課案件」遇到不想補課的學生時，現在可以按「不補課」直接結案，案件會從待安排清單移除，且不會多扣或多加堂數。

開發備註：#144 / GH#609。新增 `POST /exception-workflows/{id}/waive`（director、多校區隔離）：`status='waived'`、`closed_reason='no_makeup_needed'`，payload 記 `waived_by_user_id`/`waive_reason`。對齊業界 forfeit/no-show：waive 不安排補課、不額外扣堂/退堂（原缺席/請假堂次扣堂已在當初流程處理），避免重複扣堂或誤動 `SessionDeductionService`。已結案案件拒絕重複 waive(422)。前端 DirectorDashboard 補課案件列新增「不補課」按鈕（二次確認＋選填原因）。PR #612。

## 2026-05-31 — fix: 老師管理頁新增「停用」分頁，可查看與復職停用老師（#145）

開發備註：#145 / GH#608。`TeachersList.vue` 原本只有「正式老師(active)」「待審核(pending)」兩個分頁，`filteredTeachers` 無 `suspended` 路徑 → 停用老師被前端過濾隱藏（後端 `ProfileController::index` 其實已回傳全部狀態）。修復（純前端）：新增「停用」分頁 + `suspendedCount`，並讓狀態下拉（含「停用」）優先生效。PR #610。

## 2026-05-31 — fix: 主任補登的空白評量不再灌水老師科目數（#137/#575）

開發備註：#137。`ExcludeFromSubjectCount`（`LearningRecordController` 補登空白評量時設為 1，意為「不算入老師科目數」）只有程式碼引用、無對應 migration，且 `FinanceController::subjectUnits` 從未過濾 → 補登空白評量被誤計入科目數。修復：新增 `ExcludeFromSubjectCount` 欄位 migration（boolean 預設 0，既有評量維持原行為、不回溯改動科目數/薪資）；`subjectUnits` 排除 `=1`（`hasColumn` 防呆）。部署確認 production 原無此欄位（全新建立、零回溯影響）。孤兒評量（無對應堂次）已由 2026_03_15 FK migration 防止。PR #606。

## 2026-05-31 — fix: 主任可以隨時「取消請假」了，課表會正確回復（不再多出一堂）（#142/#596）

開發備註：#142 §1。原因有二：(1) `ScheduleController::undoLeave` 受 `LEAVE_UNDO_WINDOW_SECONDS=30` 限制（僅前端 undo-toast 用途），逾 30 秒回 `undo_window_expired`，主任之後無取消途徑；(2) `SessionEditModal` 的「改為未上」對 leave 堂次送 `PATCH /class-sessions/{id}` 純改狀態，後端 `update` 未反轉請假時自動順延的尾堂與 `EndDate`，課程憑空多一堂。修復：移除時間窗（撤銷安全改由 `CourseLeaveCascadeService::undoLeaveCascade` 的下游已上課堂次護欄把關，與時間無關）；新增 `POST /schedules/undo-leave-by-session`（以 class_session_id 取消）；前端 `doStatusChange` 對 leave→scheduled 改走此 cascade-correct 端點。PR #602/#603/#604。

## 2026-05-31 — ops: 修復 Pi 健康監控（pi-health.yml）SSH 連線被靜默略過

開發備註：`PI_HOST_KEY` GitHub secret 遺失導致 `pi-health.yml` 的「Run health checks on Pi」步驟長期 skip（known_hosts 驗證失敗 → SSH 不執行），Pi 端磁碟/溫度/備份指標未實際採集。以 `ssh-keyscan pi.lifenet.com.tw` 取回 host key 並重設 secret，重跑 workflow 確認 SSH 健檢恢復（Pi 磁碟 6%、備份新鮮）。教訓：監控 workflow「成功但跳過關鍵步驟」需與「失敗」同等告警。

## 2026-05-31 — ops: WSL2 開發機磁碟告警（93%）根因為 MemPalace 向量索引損毀，已回復

開發備註：Telegram 資源告警（磁碟 93%）來源為**本地 WSL2 開發機**（非 Pi）。根因為 `~/.mempalace/palace/.../link_lists.bin`（ChromaDB HNSW 索引）損毀膨脹成稀疏檔（邏輯 ~3.3TB、實佔 870GB）。備份 `chroma.sqlite3` 後刪除損毀索引並由原始 transcript/docs 重新 mine 重建，磁碟 93%→2%。教訓：MemPalace 索引損毀會以稀疏檔吃滿磁碟；repair 失敗時 fallback 為從來源重建。

## 2026-05-30 — fix: 學習評量主任審核分頁的待審/已核准數量改用伺服器全量計算，與總覽一致（#139/#595）

開發備註：審核分頁 badge 原本由 client 端單頁 records 計算，與總覽（server 全量）對不起來。後端 `/learning-records` 加 `with_status_counts=1` 回傳全 scope per-status 總數；前端 badge 改讀 server 計數，主任審核分頁改走伺服器端 `status` 篩選（列表＝該狀態全量、分頁載入），切分頁/核准/退回後刷新計數。

## 2026-05-30 — fix: 月結課程「操作」選單把續約入口改名為「結算／續約下月」，主任找得到結算（#141）

開發備註：月結（非堂數制）課程的續約/結算入口原本標籤為「加購堂數」，語意不通。改用 `purchaseActionLabel` 依課程模式顯示，月結顯示「結算 / 續約下月」（仍開原 RenewMonthlyModal）。

## 2026-05-30 — fix: 主任點「家長回饋待看」現在一定看得到有回饋的評量（含較舊紀錄）（#138/#139）

開發備註：家長回饋篩選改走伺服器端（`feedback=has|unread`），不受預設 90 天視窗與單頁載入限制，跨頁/跨日期的回饋紀錄都撈得到；前端 CTA/篩選 chip 連動 server 篩選並解除時間窗口。待審計數與總覽完全對齊另以 #595 追蹤。

## 2026-05-30 — feat(ui): 排課精靈/異常回報彈窗/互動排行條殘留藍色改套品牌暖色（DS rollout 收尾零星元件）

## 2026-05-30 — feat(ui): 代課相關彈窗/卡片（選代課老師、批次請假、撤銷 Toast、近期代課）主色藍改品牌暖色（DS rollout 代課 modal 群）

## 2026-05-30 — feat(ui): 課程相關彈窗（加購/續約/請假/堂次編輯/課程編輯）藍色裝飾改套品牌暖色（DS rollout 課程 modal 群）

## 2026-05-30 — feat(ui): 課程管理頁殘留藍/靛色互動元素改套品牌暖色、報表按鈕統一中性（DS rollout 課程管理）

## 2026-05-30 — feat(ui): 學習評量頁移除重複的 KPI 卡列，狀態分頁成為單一控制、未填/未讀回饋以捷徑呈現（#583 Phase B）

## 2026-05-30 — feat(ui): 學習評量頁次要篩選（優先/家長回饋）收進「篩選」漸進揭露，預設畫面更乾淨（#583 Phase A）

## 2026-05-30 — feat(ui): 智慧行事曆載入列/評量摘要/提示框藍底改中性或品牌暖色（DS rollout 行事曆）

## 2026-05-30 — feat(ui): 出缺勤頁殘留藍色互動元素（focus/hover/spinner）改套品牌暖色（DS rollout 出缺勤頁）

## 2026-05-30 — feat(ui): 學生/老師清單頁殘留藍色互動元素改套品牌暖色（DS rollout 清單頁）

## 2026-05-30 — feat(ui): 繳費回報頁改套品牌暖色、金額等寬對齊（DS rollout 金流頁）

- Changed: 家長用的「繳費回報」頁原本是藍色頂條與藍色送出鈕，與品牌不一致；改成 logo 橘黃暖色，金額改等寬數字對齊更好讀。「當月學收」頁的統計數字也統一等寬對齊。功能不變。
- 開發備註：`PayReportPage.vue` `.pr-brand-bar`/`.pr-submit-btn` 藍漸層 → `--ds-brand-gradient`，`.pr-amount`/`.pr-info-value` + 焦點環改 `--ds-*`、加 `tabular-nums`；`TuitionReportPage.vue` `.tr-stat strong` 加 `tabular-nums`。`TuitionCollectionPage` 既已用 token + tabular，無需更動。對應 GitHub #563。無 DB／API 異動。

## 2026-05-30 — fix(learning): 主任點「家長回饋待看」導到評量頁能直接看到回饋（#138）

- Fixed: 主任在總覽點「家長回饋待看 → 去查看」，導到評量頁卻看不到任何家長回饋的問題。現在點過去會自動切到「未讀回饋」、放大資料範圍（全部分頁、解除近 90 天預設視窗），並捲到回饋篩選列，確保有回饋的紀錄一定列出；若剛好沒有未讀，會自動退回顯示「有回饋」。
- 開發備註：根因與 #54/#105 同類——CTA 未讀數來自 server 通知 badge，但列表預設用待審分頁＋90 天視窗載入，回饋多在較舊的已核准課次而被濾掉。`DirectorDashboard` 回饋 CTA 改 emit `{target:'learning',focus:'feedback'}`；`App.vue` 以 `learningFeedbackFocusToken` 傳給 `LearningRecordsPage`；新增純函式 `lib/learningRecordTarget.js::feedbackFocusState` + 單元測試，元件 `applyFeedbackFocus()` 套用篩選後重抓並含 unread→has 退回。in-app #138（GitHub #580）。無 DB／API 異動。

## 2026-05-30 — feat(ui): 老師工作台內層區塊改用一致色系，減少雜亂

- Changed: 老師「今日待辦」卡片內的「今日任務中心」「家長回饋追蹤」等小區塊，原本用藍色點綴，和全站品牌橘黃不一致、看起來雜。現在統一成乾淨灰底＋細邊框，標題用一致的深墨色，需處理數量徽章改品牌暖色，整張卡更協調。功能不變。
- 開發備註：`TeacherHomePage.vue` scoped CSS：`.th-mission-card`/`.th-mission-row` 藍 `#eff6ff/#bfdbfe/#cbd5e1` → `--ds-canvas-soft/-hairline`；`.th-mission-head`、`.th-feedback-metric__head` `#1e3a8a` → `--ds-ink`；`.th-mission-remain` 藍徽章 → `--ds-primary-wash/-deep` + tabular。無 DB／API 異動。

## 2026-05-30 — feat(ui): 學習評量頁的篩選列改套統一品牌色，減少雜亂

- Changed: 學習評量／我的課表評量頁面，原本分頁、家長回饋、優先篩選用了藍色、橘色等好幾種不同顏色按鈕，看起來雜亂。現在統一成同一套品牌橘黃（選中態）＋灰底膠囊（未選），數字統計顏色也對齊系統的待辦/警示/完成語意色，整頁更清爽好讀。功能與篩選邏輯完全不變。
- 開發備註：`LearningRecordsPage.vue` scoped CSS：`.lr-tab`（active 由 `#1a73e8` → `--ds-primary`）、`.lr-feedback-filter-chip`/優先 chips（橘 `#f97316` → 中性膠囊 + brand active）、`.lr-kpi-*` 與 `.lr-tab-count.warn/.ok` 改 `--ds-warning/danger/success/-wash`、`.lr-input:focus` 改 `--ds-focus-ring`、`.lr-batch-bar`/`.lr-phrase-*` 藍 → brand wash。數字加 `tabular-nums`。對應 docs/RULE_DESIGN_SYSTEM.md rollout（評量頁）。無 DB／API 異動。

## 2026-05-30 — feat(ui): 全站主色改回 logo 橘黃暖色（與登入頁一致）

- Changed: 系統的主要強調色（按鈕、連結、重點標示、焦點）統一改成 logo／登入頁那種溫暖的橘黃色，取代先前的靛藍，讓整體更有品牌一致感、更耐看。主任儀表板頂端也加了一條品牌暖色識別條。版面結構與功能完全不變。
- 開發備註：純前端，`styles.css` 把 `--ds-primary*` / `--ds-info*` token（light + dark）重新指向 amber→orange（`#EF6C00`/`#E65100`/`#FFB300`/cream `#FFF8E1`；dark 用 `#FFB74D` 系），新增 `--ds-brand-amber/-orange/-gradient`。因主色由 token 驅動，全站 60+ 頁自動套用。`TeacherHomePage.vue` 殘留 hover 光暈改回暖色；`DirectorDashboard.vue` header 加 `--ds-brand-gradient` 頂條、kicker 改品牌橘。同步更新 docs/RULE_DESIGN_SYSTEM.md。無 DB／API 異動。

## 2026-05-30 — feat(ui): 主任儀表板與老師工作台改套 Stripe 設計系統，畫面更乾淨一致

- Changed: 主任「總覽」與老師「教學工作台」改用統一的淺色設計系統，視覺更清爽不雜亂——標題與面板回歸乾淨白底細邊框、移除過重的陰影與彩色漸層底，待辦卡片不再用 7 種顏色，改為一致白卡＋單一語意色標示，數字統一等寬對齊，整體更好閱讀。功能與資料完全不變。
- 開發備註：純前端 scoped CSS 調整。`DirectorDashboard.vue` 移除 `.dash::before` gradient mesh、header 改 16px 圓角／輕陰影／標題縮至 spec、`.ac` 待辦卡彩虹 `::before` 收斂為單一 `border-left` 語意色＋icon 色、`.progress-board`/`.pb`/`.wp` 改 `--ds-*` token 與輕陰影；`TeacherHomePage.vue` 將殘留橘色 `rgba(255,167,38,*)` hover/底色重新指向 indigo。對應 docs/RULE_DESIGN_SYSTEM.md 逐頁 rollout #1。無 DB／API 異動。

## 2026-05-30 — feat(billing): 堂數制收據列出預期課程並標記「(預期)」（#554）

- Added: 堂數制（買 N 堂）的電子收據，除了已上課日期外，會把已購但尚未上課的堂次也列出並標記「(預期)」，補足到實付堂數，讓家長看到的堂數與實付一致（例：付 8 堂、已上 7 堂，收據會列 8 堂、最後一堂標「(預期)」）。月結制不受影響。
- 開發備註：`PaymentReportController::receipt` 新增 `session_dates`（`[{date, expected}]`），堂數制以 `scheduled/rescheduled` 排課補足到 `SessionCount`（已上課達已購堂數則不補）；`attended_dates` 保留相容。`ReceiptModal.vue` 改讀 `session_dates`，預期堂次以灰點＋「(預期)」呈現。新增測試 `PaymentReportApiTest::test_receipt_lists_expected_future_sessions_for_count_mode`、`..._does_not_add_expected_when_attended_reaches_purchased_count`。in-app #133。

## 2026-05-30 — fix(learning): 補填較舊課次評量後，列表不再因「近 90 天」視窗而看不到（#105）

- Fixed: 老師替較舊的課次補填評量後，若該日期已超出「近 90 天」預設顯示範圍，系統會自動展開全部歷史，讓剛新增的那一筆立刻出現在評量列表，不會再出現「我剛新增、列表卻看不到」的困惑。
- 開發備註：`LearningRecordsPage::submitForm` 存檔成功後，以新純函式 `lib/learningRecordTarget.js::shouldLiftDefaultWindowForDate`（剛存日期 < 視窗起點則 true）判斷，必要時設 `defaultWindowDisabled=true` 再 `fetchRecords`。既有空狀態／banner 已有「查看全部歷史」affordance。新增測試併入 `test:learning-record-target`。in-app #105。

## 2026-05-30 — fix(learning): 補填提醒跨分校點入也能正確開啟該堂評量（#54 / #82）

- Fixed: 從教學工作台「補填提醒」點入填寫評量時，若該堂屬於其他分校，現在也能正確開啟該堂的評量填寫，不再出現「提醒看得到、點進去卻找不到該堂課」。
- 開發備註：補填提醒跨分校列出 attended+missing 課次，但填寫頁深連結原以 `props.branchId` 查 class-sessions，分校切換 prop 更新與 target watcher 同 tick 競態時會查無。改由深連結事件帶 `branchId`（App.vue `onNavigateLearningFromTeacherHome` 補上），`LearningRecordsPage::fetchTargetSessionEvent` 以新純函式 `lib/learningRecordTarget.js::resolveDeepLinkBranchId`（目標分校優先、退回目前分校）決定查詢分校。新增 node 測試 `test:learning-record-target`（併入 build）。in-app #54 / #82。

## 2026-05-30 — fix(course-mgmt): 共用方案可直接設定總堂數（#553）

- Fixed: 共用堂數（多科共用方案）的課程，現在主任可在「加購／設定總堂數」中直接把方案總堂數設定為指定數字（不再只能加購）；調整會同步方案所有科目，且不可低於已使用堂數。
- 開發備註：`PurchaseSessionsModal` 在 package 模式新增「加購／設定總堂數」切換；新增純函式 `lib/packageSessions.js::computePackageNextTotal`（含 node 測試 `test:package-sessions`，已併入 build）。set 模式走既有 `PUT /course-packages/{id}` 絕對值更新（後端已支援、會同步成員），符合 §R22。編輯表單堂數欄提示改為指向此入口。in-app #132。

## 2026-05-30 — fix(schedule): 調課時請假學生不再佔用老師名額（#557）

- Fixed: 調課到某時段時，若該時段有學生請假，系統不再把請假學生算進老師人數，避免「明明有空位卻顯示老師人數已滿」而擋住調課。
- 開發備註：`ScheduleGuardService::buildTeacherDateOccupancyEntries` 的 ClassSession 佔用查詢，排除集合由 `['cancelled']` 對齊為 `['cancelled','leave','leave_adjusted','excused']`（與前端 LEAVE_STATUSES、`LearningRecordController` 一致）。新增回歸測試 `ScheduleGuardrailsTest::test_reschedule_allowed_when_a_slot_occupant_is_on_leave`。in-app #136。

## 2026-05-30 — ops(deps): 修補 symfony/mime HIGH 安全漏洞，解除後端 PR merge 阻擋

- Security: `composer update symfony/mime` v5.4.45 → v5.4.52（修補 CVE-2026-45067 CRLF/SMTP header injection，HIGH）；連帶小幅升級 guzzle/pusher、移除已不需要的 paragonie/sodium_compat。CI composer audit HIGH/CRITICAL gate 恢復通過。
- 開發備註：先前所有 backend PR 因此 HIGH CVE 被 composer audit gate 擋住（#557 等）。本次僅動 `backend/composer.lock`（無直接 constraint 變更）。

## 2026-05-30 — fix(attendance): 學生到班後不再同時顯示「請假自動順延」（#555）

- Fixed: 學生實際到班並點名後，出缺勤畫面不再殘留「請假自動順延」標記，避免「到班」與「請假順延」狀態矛盾。
- 開發備註：`sessionConsistency.js` `normalizeSessionRow` 在 attended/present/late 狀態時不輸出 `status_note`（順延 note 屬課次生命週期 provenance，到班後即失去意義）。新增回歸測試於 `sessionConsistency.test.js`。in-app #134。

## 2026-05-30 — feat(design): 全站介面改採統一設計系統（Stripe 風格地基）

- Changed: 系統介面換上全新統一視覺——更專業沉穩的藍色系、淺色乾淨底色，金額與數字對齊更整齊好讀；操作邏輯不變。
- 開發備註：新增 `docs/RULE_DESIGN_SYSTEM.md`（Stripe-inspired 單一真相來源，取代 `PORSCHE_VISUAL_SYSTEM.md`）；`styles.css` 建立 `--ds-*` token 並把 legacy/`--porsche-*` alias 至其上（token-level rebrand），primary 由橘改 indigo、加 `.ds-money/.ds-num` tabular 工具。逐頁精修以 Epic 追蹤。

## 2026-05-24 — fix(course-mgmt): 新增月結課程錯誤訊息改為白話提示（#539）

- Fixed: 當日期區間內沒有符合固定星期的排課日時，不再顯示 `end_date:` 技術欄位訊息，改為引導調整結束日或上課星期。
- Added: `universalSchedulerApi` 錯誤訊息映射測試，防止回歸。

## 2026-05-24 — fix(course-mgmt): 待補課列表只顯示真正補課（#538）

- Fixed: 課程管理「待補課」列表不再混入一般排程，避免主任點「取消補課」時看到錯誤提示。
- 開發備註：`GET /api/v1/schedules` 新增 `type` 篩選；前端待補課列表加 fail-closed guard。覆蓋 in-app #130。

## 2026-05-24 — ops(git): 防止 assume-unchanged 藏檔漏進 PR（#535）

- Ops: 新增 `scripts/git-index-audit.sh` 與 §R58 防再犯規則，週例檢查 protected 路徑是否被 `assume-unchanged` / `skip-worktree` 隱藏；同步把 audit 流程接進 Solo+AI §B5。
- Fixed: 清出先前被 Git index 隱藏的 `scripts/smoke-api.sh` 登入 payload / token parser 修正，避免 smoke secrets 設定後仍誤判登入路徑。

## 2026-05-24 — feat(course-mgmt): 補課取消流程 — 主任可取消待補課排程（#527）

- Added 主任端課程管理頁「待補課」列表：展開課程詳情後顯示尚未上課的補課排程（`type='extra', status='scheduled'`）
- Added 「取消補課」按鈕：點擊確認後將 `schedules.status` 改為 `cancelled`，對應 `ClassSession` 同步取消，`RemainingSessions` 不變
- 開發備註：後端 `POST /api/v1/schedules/{id}/cancel-makeup` + 前端 `pendingMakeupsByCourse` 狀態，7 個 PHPUnit 測試全通過

## 2026-05-24 — feat(learning): 老師評量頁 iOS 風格重設計 + 合約 ID 顯示（#128 PR#524）

- Changed 老師登入後評量頁改為 iOS 風格：KPI 數字條、課表列表、篩選 tab、評量卡片全面更新，字型採用系統 SF Pro，色彩使用 iOS 系統調色（藍/橘/紅/綠）
- Fixed 評量列表並存合約顯示問題：各記錄旁加上合約 ID（如 #810）幫助主任區分同學生同科目的兩個合約
- 開發備註：全以 `.lr-page--teacher` scope 隔離，不影響主任視角

## 2026-05-24 — fix(schedule+billing): 調課時間同步修正 + 費率切換 UI（#127 #129 PR#521 #522）

- Fixed 調課後老師評量頁面仍顯示舊時間（`schedules` 已更新但 `ClassSession` 未同步）
- Fixed 課程費率顯示：課程設定頁新增「按堂 / 按時」切換按鈕，舊 `rate_unit='hour'` 資料問題不再隱藏
- Fixed 4 筆未付費課程費用計算錯誤（蔡羽絜 SC#182/#1054、吳睿哲 SC#671/#673），金額從 ~17,600 修正為正確的 8,800 / 8,000
- 開發備註：ScheduleController `ensureClassSessionForScheduleData` 改用 updateOrCreate 避免 cancelled 狀態被 firstOrCreate 跳過；migration 2026_05_24_000100 backfill unpaid courses
- 待確認：已收費 8 筆課程（CampusID=9/17）需主任確認退費意向後再處理

## 2026-05-24 — docs(consolidation): 建立 GitHub issues #515–#520（Phase A–F docs restructure）

- 開發備註：docs consolidation Phase A–F 計畫逐步執行，issues 依優先序建立

## 2026-05-24 — chore(docs/sop): plain-language bug replies + docs consolidation plan

- Added: 新增 `.cursor/rules/user-facing-communication.mdc`（always-applied）規範 AI 對老師／主任／家長的留言一律白話，禁止欄位名 / SQL / class 名 / PR 編號漏到公開留言；同步 `docs/CHAT_BUG_SYSTEM.md` §3.8 加入「白話留言檢查清單 + 對照範例」。
- Plan: `.cursor/plans/docs-consolidation_2026-05-24.md` 列出 docs 整理提案（Tier 0/1/2/3 階層、命名 prefix、archive 規劃、MemPalace 自動化、doc owner 制度），參考 Google docs style guide / Stripe 三層金字塔 / Vercel flat 結構 / Kubernetes CONTRIBUTING+DEVELOPMENT 分工，**待 CEO 圈選 Phase A-F 再分 PR 推進**。

---

## 2026-05-24 — revert(login): iOS-style v0 回退為原版（#510 / #511）

- Reverted: 還原登入頁原本的金桔漸層 + glassmorphism 視覺。iOS-style v0（PR #510）經 CEO 看完後決定不採用，整組 revert 含設計 token、`.cursor/plans/ios-simplify-direction_2026-05-24.md`、CHANGELOG 條目。`--ios-*` design tokens 一併移除（後續若再做 iOS 風格頁面時重新引入）。

---


## 2026-05-23 — fix(course-mgmt/learning/session-dates): batch bug triage 收尾（#495 #496 #497）

- Fixed: 課程管理在調課完成後不再多顯示一筆「取消」狀態的同時段堂次；系統內部建立的 `cancelled-duplicate-reschedule-placeholder` 已改為預設不對前端 API 回傳（in-app #124 / Closes #496 / PR #499）。需稽核時可帶 `?include_internal_placeholder=1` 取得。
- Fixed: 學習評量若先前因「一般請假」自動連動而被作廢、但 `ClassSession` 後續恢復為已上課/已排定/已完成/遲到，老師重新提交評量會自動把舊評量復活並覆蓋為新內容，不再被 409 衝突永久卡住；手動作廢與真實取消狀態仍維持原本拒絕（in-app #125 / Closes #495 / PR #498）。
- Fixed: 課程建立時若 request 沒夾帶 `days_of_week`、且該課程在 package 內也沒有同伴可借 week*，sessionDates 現在會 fallback 讀自身 `week, week1..week6`；月度 24 堂課的後續週期日期不再消失（in-app #126 / Closes #497 / PR #500）。
- 開發備註：`StudentClassController::sessionDates()` 內 `$bodyClasses` 經 `merge()` 後會被 array_merge 重新索引整數鍵，新加的 self-week fallback 改用 `firstWhere('ID', $cid)` 才能命中；測試 fixture 需 `ScheduleMode='count'` 才會走入 body path（否則 GET path 會覆寫成空集合）。

---

## 2026-05-23 — fix(bugs): reporter-verify cross-branch access (P1 hotfix)

- Fixed: 回報者在 Bug 回報頁對「已解決」單按「確認已修好／問題仍存在」時，若當前分校與回報當時分校不同會誤回 404（#378 列表/詳情已跨分校，但 `reporter-verify` 未對齊）。修復後與 `show()` 共用 `canAccessBug()` 授權。

---

## 2026-05-23 — feat(ui): enterprise visual polish rollout phase 1（#461）

- Changed: `DirectorDashboard`、`TeacherHomePage`、`LearningRecordsPage`、`AttendancePage`、`ParentPortal` 套用統一 light-first 企業視覺語言（header surface、empty/loading 狀態、44px 觸控目標與 CTA 層級一致化），並以共用 `enterprise-*` utility class 收斂跨頁不一致樣式。Closes #461。

---

## 2026-05-23 — feat(parent-feedback): response program timing + template + digest v1（#459）

- Added: 家長端學習評量回饋導入三組受管控模板（鼓勵/提問/請老師加強）與未回覆摘要；後端新增 `learning-record-feedbacks/analytics` 供老師/主任查看 7 日回覆率、未回覆堂數與待處理預覽，並在 dashboard 回傳 `feedback_program`（trigger window、quiet hours、throttle/mute policy）作為提醒節流合約。Closes #459。

---

## 2026-05-23 — feat(adoption): staff mission center + activation funnel v1（#458）

- Added: 老師與主任首頁導入「今日任務中心」閉環（待辦剩餘件數 + 一鍵跳轉），並在 adoption 指標新增 `activation_funnel`（teacher/director 24h activation）供管理層追蹤採用率。Closes #458。

---

## 2026-05-23 — feat(adoption): KPI cockpit + trust layer v1（#462 / #460）

- Added: 主任儀表板新增 Adoption/Quality KPI 集群（老師/主任開啟率、家長回覆率、Bug 重開率、P1/P0 lead time、trust backlog），並支援 super_admin 跨分校比較。Closes #462。
- Added: 新增 `GET /api/v1/system/trust-summary`（教職端）與 `GET /api/v1/parent/system-trust-summary`（家長端）供前端顯示「最近改善 / 已知問題 / 穩定性快照」，已套用分校隔離與最小揭露。Closes #460。
- Changed: 新增可重用 `SystemTrustPanel`，已掛載到主任儀表板、老師教學工作台與家長入口（teaser），讓系統品質狀態可見且可追蹤。

---

## 2026-05-23 — fix(auth): 改密碼錯誤提示改為中文

- Fixed: 個人檔案改密碼時，新密碼規則統一為至少 8 碼，錯誤提示改成中文，不再顯示 `validation.min.string`。Closes #433，對應 in-app #118。
- 開發備註：本次補齊 in-app Bug 分診／上線後回寫 SOP：`CHAT_BUG_SYSTEM.md` §3.7、`AI_REGRESSION_LESSONS.md` §R53、`CLAUDE.md`／`AGENTS.md`／`INDEX.md` 導航。

---

## 2026-05-23 — docs(line): 串接教學改為新手友善三段式

- Changed: LINE 串接頁改為「3 步驟快速開始 → 完整教學 → 常見錯誤排查」，並明示 LINE Notify 已於 2025-03-31 終止，主流程改採 LINE Official Account + Messaging API。Closes #451。

---

## 2026-05-23 — fix(discrepancy): 課程回報管理補上頁內 SOP 與處理範本

- Improved: 課程回報管理頁新增「快速處理 SOP」與處理說明範本按鈕，降低主任/管理員首次處理門檻並提升關單說明一致性。Closes #453。

---

## 2026-05-23 — fix(onboarding): 問號教學改為角色分眾與 fallback 導覽

- Improved: 問號導覽新增角色首頁 fallback（側欄/分校/帳號）與老師工作台、課表回報管理的分眾導覽步驟，避免點擊 `?` 無反應並提升新手上手效率。Closes #452。

---

## 2026-05-23 — fix(bugs): super_admin 回報者可直接驗收已解決單

- Fixed: Bug 回報頁在 `resolved` 狀態下，若 super_admin 同時是原始回報者，現在也會顯示「確認已修好／問題仍存在」驗收按鈕，不再被角色誤擋。對應 in-app #122/#123 驗收流程。

---

## 2026-05-23 — fix(schedule): 後續堂數顯示補齊 + feat(payroll): 兼職費率卡生效日 v1

- Fixed: 課程管理堂數制在課程本身缺 week/time、但同方案兄弟課仍有固定週期時，`session-dates` 會自動套用 package sibling 週期補推算，避免只顯示歷史堂次漏掉後續應有堂次。Closes #440，對應 in-app #122。
- Added: 兼職老師個別費率改為生效日費率卡（effective-dated）：新增 `effective_from` / `use_branch_default`，薪資計算依月份 as-of 套用，不再僅看最新一筆。Closes #444，對應 in-app #120。
- 開發備註：新增 migration `2026_05_23_000100_add_effective_from_to_payroll_teacher_branch_rules.php`；`ParttimePayrollPage` 新增生效日與費率卡歷史顯示。

---
## 2026-05-23 — feat(learning): 上一堂已核准摘要 + feat(engagement): 軍階一覽 modal

- Added: 評量表新增「上一堂已核准評量摘要」，會自動帶出同課程最近一次已核准內容，填寫新評量時可快速延續脈絡。Closes #443。
- Added: 老師/主任軍階徽章可點開「軍階一覽」modal，顯示完整門檻與目前所在階級。Closes #445。

---
## 2026-05-23 — fix: 聊天附件、行事曆 EndDate、CourseEditForm、N+1 查詢（#431）

- Fixed: 聊天頁附件按鈕點擊無反應（TypeError: y.click is not a function）— Closes #421 #428
- Fixed: 手機聊天長訊息時輸入框被擠出視窗 — Closes #429
- Fixed: 新增課程時間段 ReferenceError: dayOptions is not defined — Closes #422
- Fixed: 已停課（EndDate 過期）課程仍出現在行事曆週檢視 — Closes #427
- Fixed: ensurePastRecords N+1 查詢（Subject 逐筆查 → 批次預載） — Closes #420

---

## 2026-05-17 — feat(finance): 自動催繳工作流（Closes #400）

- Added: 自動催繳規則引擎 — 依 `DIRECTOR_PAYMENT_ALERT_RULES.md` 規則自動評估未繳/低堂/逾期，每學生每週上限 3 則，cooldown 7~30 天
- Added: API `POST /api/v1/dunning/trigger`、`GET /api/v1/dunning/history`、`GET /api/v1/dunning/rules`
- Added: CLI `php artisan dunning:run`
- 開發備註：新增 migration `dunning_events`

---

## 2026-05-17 — feat(finance): 銀行入帳勾稽（Closes #397）

- Added: 銀行交易匯入 — `POST /api/v1/bank-reconciliation/import`（批次 max 500，自動去重）
- Added: 自動建議匹配 — `GET /{id}/suggest`（日期±3天 + 金額一致）
- Added: 手動勾稽 — `POST /{id}/reconcile`（防重複勾稽）
- Added: 查詢+統計 — `GET /api/v1/bank-reconciliation`（含 unmatched/matched/reconciled summary）
- 開發備註：新增 migration `bank_transactions`

---

## 2026-05-17 — feat(engagement): 遊戲化 XP 系統 + 帳務 AR 分析 + 家長回饋回覆

- Added: 軍階 XP 系統 — 老師/主任雙軌 15 種 XP 事件（教學評量、出缺勤、代課處理等），每日上限 200 XP，自動防重複。API: `POST /api/v1/engagement/award-xp`
- Added: 成就徽章系統 — 12 種徽章（初心者、七日勇者、百堂教師等），支援隱藏/顯示切換。API: `GET /api/v1/engagement/badges`
- Added: 軍階門檻 API — 前端不再 hardcode 19 階門檻表，改由 `GET /api/v1/engagement/rank-thresholds` 取得
- Added: AR 帳齡分析（30/60/90+ 天未收）— `GET /api/v1/finance/ar-aging`
- Added: 會計期間關帳/重開帳 — `POST /api/v1/finance/periods/close` + `reopen`
- Added: 多分校合併營收摘要 — `GET /api/v1/finance/consolidated-summary`
- Added: 總帳匯出 CSV — `GET /api/v1/finance/gl-export?format=csv`
- Added: 老師端家長回饋 inbox — `GET /api/v1/parent-feedback/for-teacher`（含未讀計數）
- Added: 家長回饋回覆機制 — `POST /api/v1/parent-feedback/{id}/reply`
- Added: 家長帳務查詢 — `GET /api/v1/parent/billing-history`
- 開發備註：新增 3 個 migration（accounting_periods, parent_feedback_replies, user_badges），PR #411。Closes #387 #388 #389 #390 #391 #392 #393 #395 #396 #398 #399 #401 #402 #408 #409 #410

---

## 2026-05-17 — fix(calendar): 代課／調課連續操作不再卡成「已滿衝堂」

- 連續做「調課後再換代課老師」時，系統會自動把代課例外接回原本的調課 anchor；老師候選不再因為自己留下的舊資料被誤判「已滿／衝堂」，行事曆也能正確顯示代課老師。
- 開發備註：`ClassSessionController::substitute` 吸收並修補 `original_schedule_id=NULL` 的歷史 scheduled row；新增 `schedules:backfill-substitute-anchors` dry-run/apply command 只修 `schedules` anchor，不碰 `ClassSession`／堂數／評量；補 `SubstituteWithRescheduleTest` regression。Closes #364，對應 in-app #95/#108。

---

## 2026-05-17 — fix(learning): 手機新增評量表單的快捷語句改為自動換行

- 手機開啟新增學習評量時，快捷語句按鈕（授課進度／作業範圍／週考範圍）會自動換行，不必再左右滑也能看到完整選項；按鈕高度提升到 44px，符合 Apple/Google 觸控規範。
- 桌面版維持原本的橫向捲動，避免長條按鈕擠壓表單。
- 開發備註：`LearningRecordsPage.vue` `@media (max-width:640px)` 新增 `.lr-phrase-row--hscroll` wrap override；CSS-only 改動，無 logic／後端／DB 變動。覆蓋 #376。

---

## 2026-05-17 — feat(bugs): 自己的 bug 回報跨分校都看得到

- 老師／主任在 Bug 回報頁切到任何分校，都會看到自己之前提交的全部回報，而不再因為分校切換漏掉舊單。
- 紅點計數同步跨分校：別的分校有人回覆你的單，目前分校的紅點也會亮。
- super_admin 處理流程不變（仍按目前分校 scope 過濾）；別人的回報也不會被你看見。
- 開發備註：`BugReportController` 新增 `resolveReporterCampusIds()`；`index/show/unreadBadge` 對非 super_admin 改用此 scope；`BugReportService::belongsToCampusForReporter()` 守護 detail 端點。新增 5 個測試覆蓋 reporter 視角／regression／PII 邊界。Closes #378。對應 in-app bug #106。

---

## 2026-05-17 — fix(learning): 「未填」KPI 與列表對不上時顯示明確指示

- 老師點「未填優先」如果列表是空的、但右上角 KPI 顯示還有 N 堂未填，會出現新文案告訴你：「這些堂次還沒建立評量草稿，請從『今日待辦』或『行事曆』直接點該堂次填寫」。不再讓人對著空白列表困惑。
- 開發備註：`LearningRecordsPage.vue` 空狀態新增 isTeacher + teacherPriorityFilter='unfilled' + weekTotalMissingCount>0 分支；短期 UX 緩解，不改 KPI 與列表的資料源（KPI 用 buildEvents 堂次層級，列表用 LearningRecord 表）。長期方案另案規劃。覆蓋 #377、in-app bug #107 後續。

---

## 2026-05-17 — chore(docs): AI 處理 bug 回報前必查附件（§R51）

- 開發備註：避免 AI 處理 in-app bug 時忽略 `bug_report_attachments`／reporter 歷史／跨分校紀錄，重複問使用者「請補截圖」（2026-05-17 實際發生於 #107）。新增 `AI_REGRESSION_LESSONS.md §R51` 與 `CHAT_BUG_SYSTEM.md §3.6` SOP。

---

## 2026-05-16 — fix(calendar): 行事曆載入減少重複查詢

- 課表／行事曆開啟與切週時會少抓不必要資料，降低載入等待時間，尤其是課程較多的分校。
- 開發備註：`SmartCalendar` REST 成功後跳過 legacy fallback，週資料窗口縮為 ±21 天，初始資料載入集中並行；覆蓋 #358。

---

## 2026-05-16 — fix(calendar): 同學生同時段不再顯示兩張課卡

- 木柵今日行事曆若遇到舊課程契約與實體堂次重疊，會優先保留實體堂次，不再把同一學生同一時段畫成兩張卡。
- 開發備註：`calendarOccurrenceMerge` 改以學生+日期/星期+開始時間去重，實體 `ClassSession` 優先；覆蓋木柵洪家溱/陳宥霖型態。

---

## 2026-05-16 — fix(attendance): 代課堂只由代課老師點名

- 單堂換代課老師後，原老師的行事曆不再留下該堂待點名；點名權限也會轉給代課老師。
- 開發備註：`SmartCalendar` 載入代課例外供週曆合併；`AttendanceController` 以時段級 effective teacher 授權；覆蓋 #357。

---

## 2026-05-16 — fix(learning): 未填評量不再被日期範圍藏起來

- 老師點「未填優先」時，歷史未完成評量也會出現在列表，不再只看到數字卻找不到要填的評量。
- 開發備註：`LearningRecordsPage` 未填優先豁免預設 90 天窗口；`learningRecordsWindow` 補 query regression；覆蓋 #359。

---

## 2026-05-16 — fix(course): 編輯課程保留授課老師

- 編輯既有課程時，授課老師欄位會保留目前老師；即使老師清單稍晚載入或暫時缺少該老師，也不會變空白導致無法儲存。
- 開發備註：`CourseManagement` 注入目前老師 fallback option；`courseTeacherOptions` 補 regression；覆蓋 #365。

---

## 2026-05-16 — fix(billing): 共用方案合併繳費金額對齊

- 共用方案在繳費提醒會顯示一筆合併帳務，金額依方案總堂數計算；核帳後會同步標記整個方案與所有科目已繳，避免同一方案被拆成單科金額。
- 開發備註：`AlertController::tuition` 新增 count-mode package row；`PaymentReportController::directorRecord` 同步 package/member paid；覆蓋 #362/#363/#366。

---

## 2026-05-16 — fix(learning): 課表、評量與出缺勤顯示一致

- 老師的課表與評量列表會依同一個分校範圍顯示；缺席、請假、取消等堂次也會用更清楚的狀態呈現，不再看起來像評量突然不見。
- 開發備註：`LearningRecordsPage` 老師課表帶 `branch_id`；`sessionConsistency` 統一缺席/請假/取消文案；`AttendancePage` 補列已標記狀態堂次；覆蓋 #360/#361。

---

## 2026-05-16 — feat(calendar): 行事曆取消單堂課 + Bug 回報驗收流程 (#355)

- 行事曆「單堂操作」面板新增「🚫 取消本堂」按鈕（主任限定），可直接從行事曆取消當堂課程，無需跳轉課程管理頁。
- Bug 回報系統新增驗收環節：Jerry 標記修復後，原回報老師／主任看到「✅ 確認已修好」與「❌ 問題仍存在」按鈕，親自確認才關閉，不再有「標了已解決但老師說沒修好」的問題。
- Bug 回報表單標題改為選填（自動帶入頁面名稱），降低老師回報門檻。
- 開發備註：`POST /api/v1/bugs/{id}/reporter-verify`（reporter 限定）；`resolved→closed` 需回報者確認；加課按鈕因業務流程未完整暫時隱藏。

---

## 2026-05-16 — chore(release-notes): 版本卡改日曆版號 + 白話與開發備註分流

- 版本更新頁的版號改為 **YYYY.MM.DD**（與 `version.json` 建置時間分開）；CHANGELOG 可用「開發備註：」承接技術行而不洗版
- 開發備註：`scripts/changelog-to-release-notes.mjs` 略過 `開發備註／Dev note` 行；補齊軍階／行事曆／堂數制／課程日期等白話對照規則

---

## 2026-05-16 — feat(engagement): 新增士官長三階 + 修正 ROC 軍階徽章 (#353)

- 側欄軍階圖示更貼近實際領章：尉官橫槓、校官梅花、將官星星；經驗值升階多了「三等／二等／一等士官長」三個階段。版本更新頁的版號改為西元年月日（例：2026.05.16），方便對照每次上線內容。
- 開發備註：新增 master_sergeant_third/second/first（XP 275/355/445）；`RocRankBadge.vue`；`EngagementRankProgressionTest.php` 防回歸。

---

## 2026-05-16 — fix(scheduling): 堂數制取消堂次後不再於同日重插排課

- 堂數制課程若取消某一堂，系統不會又在同一天自動補回一堂。
- 開發備註：`StudentClassController::extendSessionsIfNeeded` 將 `cancelled` 堂次佔用 `date|start` 槽位，避免補齊 `SessionCount` 時誤以該日為空而依契約週期重建 `scheduled`。

---

## 2026-05-16 — fix(calendar): 行事曆週檢視漏格與資料未隨換週更新

- 智慧行事曆「週」檢視：換週後會載入正確區間的堂次；較不會漏格、課表空白或出現幽靈課；調課／重試留下的特殊資料也不會把當天仍要上的課整排吃掉。
- 開發備註：`calendarOccurrenceMerge`（SessionCount 週上限、`rescheduled` 同日略過條件）；`loadCourses` 依週視窗對齊 `class-sessions` API；`calendarOccurrenceMerge.test.js` 補案例。

---

## 2026-05-16 — fix(session-dates): 修正課程管理日期 chip 無故灰頻 (#344)

- 課程管理裡，日期旁的小標記較不會無故變灰；讀不到堂次資料時會用紅字提示，而不是靜默怪怪的。
- 開發備註：`POST student-classes/session-dates` 套用 `range_start`/`range_end`；`ScheduleMode='date'` 過濾 `cancelled`/`leave`；`classSessionsByCourse` 錯誤 UI 與 chip tooltip。

---

## 2026-05-10 — feat(engagement): 前端軍階／XP 摘要（#326，epic #323）

- 老師與主任畫面可看到軍階與經驗值進度；不想顯示可在個人資料關閉；動態效果會尊重系統的「減少動態」設定。
- 開發備註：教學工作台／主任總覽；`engagementRankProgress.js` 與後端門檻對齊；`prefers-reduced-motion`。

---

## 2026-05-10 — feat(engagement): 軍階／XP（#324–#325，epic #323）

- 核准評量後，系統會為授課老師累積經驗值並換算軍階；超級管理員維持專用最高軍階顯示。
- 開發備註：`user_engagement`、`me.engagement`、`user_engagement_xp_events`；`EngagementRankProgression`；作廢／rollback 撤銷 XP。

---

## 2026-05-10 — fix(director): 評量「已核准／全部」可篩「只看未填」（#322）

- 主任在「已核准／全部」列表可一鍵只看「還沒寫內容」的評量，數字也會跟著對齊，比較不會漏追。
- 開發備註：頂部「未填」KPI 與篩選聯動。

---

## 2026-05-10 — feat(teacher): 連續使用天數摘要（本機、預設關，#314）

- 老師可在個人資料「教學設定」選擇顯示連續使用天數（只存在這台裝置，預設關）；工作台會顯示低調摘要。
- 開發備註：`localStorage`、登入日更新；`npm run test:teacher-streak`；PRD `.cursor/plans/teacher_engagement_streak_prd_2026-05-10.md`。

---

## 2026-05-10 — docs(plan): 評量「已核准未填」UX + ROC 軍階／XP 分階段

- Docs `.cursor/plans/alltrue_engagement_ranks_and_lr_ux_prd_2026-05-10.md`；GitHub Issues [#322](https://github.com/jerry200176-png/AllTrue_System/issues/322)（主任篩選）、[#323](https://github.com/jerry200176-png/AllTrue_System/issues/323)（epic）、[#324](https://github.com/jerry200176-png/AllTrue_System/issues/324)–[#326](https://github.com/jerry200176-png/AllTrue_System/issues/326)（Phase 1–3）

---

## 2026-05-10 — fix(learning): 課表補齊 recordId、409 回傳 existing_id、手機評量 Modal 上下安全區

- Fixed 評量頁課表事件在 API 未帶 `learning_record_id` 時改由 `ClassSessionID`／時段對應既有清單補 `recordId`，避免誤開「新增」而撞 409；409 回應統一含 `existing_id`（並相容 `existing_record_id`）；手機 Modal 草稿列改固定於標題下並加底部 safe-area／捲動留白
- Added `GET learning-records?for_conflict_lookup=1&class_session_id=` 供 409 後精準載入該堂評量（含作廢列）；作廢仍占 unique 時改回明確 409；前端 409 自動補拉並開啟既有評量
- Fixed 主任端評量列表「授課老師」與編輯表單預設：API 依 `schedules` 單堂代課解析 `effective_teacher_id`／`teacher_name`，避免 `LearningRecord.TeacherID` 與代課不同步時仍顯示正班老師
- Docs `AI_REGRESSION_LESSONS.md` §R46：代課／評量多來源與 read reconciliation（對齊 §R39、§R42、§R44 同族問題）
- Docs `AGENTS.md` 開工 SOP、`p0-gate` 黃線 Y4：高風險模組必讀 `INDEX` + `AI_REGRESSION` 模組索引後再改程式

---

## 2026-05-10 — feat(teacher): 可選介面操作音效（換頁／側欄／開評量）

- Added 老師於個人資料「教學設定」可開啟 Web Audio 短提示音（預設關），與待辦提醒音分開

---

## 2026-05-10 — docs: 長文導航與歷史檔狀態橫幅（RUNBOOK / CHANGELOG archive / 舊 PRD）

- Changed `OPERATIONS_RUNBOOK` 開頭章節導航表、§I2 避免重複標題；`CHANGELOG`／`CHANGELOG_ARCHIVE` 閱讀提示；`更新網站前端`／主任說明／技術報告／兼職 PRD／`api-swipe-rfid` 狀態說明；`INDEX`＋`AI_DOC_LITERACY` 收錄「歷史／易誤導」表

---

## 2026-05-10 — docs: AI 讀檔協議與 README／INDEX 導覽（防長文漏讀）

- Added `docs/AI_DOC_LITERACY.md`（速讀卡、CHANGELOG→公告鏈、MemPalace 參照）；更新 `README`、`INDEX`、`AGENTS`、`DOCS_GOVERNANCE_SOP`、`docs-integrity-check` 導覽與連結檢查

---

## 2026-05-10 — docs: 家長入口分眾與工程交接（對齊大廠式可追溯）

- Added `AI_REGRESSION_LESSONS` §R45、模組索引列；更新 `ROLE_PLAYBOOK` 家長 SOP、`INDEX` 導覽、`ENTERPRISE_WORKFLOW_ALIGNMENT` 多角色列；`G-008`（`releaseNotes` 分眾）

---

## 2026-05-10 — fix(parent): 家長入口進度中心與版本公告精簡（手機優先）

- Changed 家長進度中心隱藏「待處理提醒／待處理事項」與內部向「處理進度」區塊；繳費改為全寬一列；版本更新僅顯示與家長相關之短摘要（最多兩則），教職員向條目不再洗版

---

## 2026-05-10 — feat(parent): 家長入口與「給老師留言」可發現性（參考雙向溝通產品模式）

- Changed 登入頁補充「逐堂留言給老師」與一站瀏覽價值說明
- Added 進度中心一鍵「想跟老師說什麼」→ 切換學習分頁並捲動至留言區
- Added 學習評量區引導條、摺疊卡「可留言／已回饋」標籤與「留言給老師」捷徑、快速帶入開頭句
- Changed 校方意見箱上方標示與「逐堂給老師回饋」分區，減少誤填
- Changed 「學習」Tab 角標顯示尚未留言堂數（藍底）

---

## 2026-05-10 — fix(learning): 總覽待審自開課起 + 手機評量版面 + 主任快速評語

- Changed 主任總覽「待審核評量」API 改 `only_started=1`（開課時間起可見），取代 `only_due`（下課後才見）；後端新增查詢參數 `only_started`
- Fixed 評量頁 modal／草稿面板手機直向：內層捲動 + 底部提交欄固定、overlay z-index 高於底欄；快速語句列全寬橫向捲動與觸控高度
- Added 主任於評量列表／卡片可點「主任評語」開輕量面板（不必先進完整編輯）

---

## 2026-05-10 — fix(learning): 評量 409 bug 修復 + KPI bar + 快速語句擴充

- Fixed 評量頁從課表點已填堂次時誤觸 CREATE → 409 alert：改為先 refresh 本地清單再重試，409 fallback 改自動開啟衝突記錄
- Added 評量頁頂部 4 格 KPI bar（未填 / 待審 / 需修改 / 已核准），teacher & director 點擊即切換 filter
- Changed `changes_requested` status tag 改為橙色，與 `pending` 黃色視覺區分
- Changed 評量表「學習進度與家長溝通」快速語句從 10 → 20 個，預設顯示 8 個

---

## 2026-05-10 — feat(teacher-ui): 工作台資訊架構整合 + 手機評量表體驗優化

- Changed 移除 `TeacherHomePage` 重複的「今日最重要 3 件事」與「每日任務清單」區塊，工作台子區塊從 5 降至 3
- Changed `LearningRecordsPage` 手機（≤640px）初始預設課表 today tab，消除週檢視 7 欄橫向溢出
- Changed 評量表快速語句 chip 在手機改為橫向捲動，不換行破版

---

## 2026-05-10 — fix(teacher): 教學工作台評量數與已取消堂次可見性

- Fixed 教學工作台「待填／待修改」數改走 `GET me/learning-pending-summary`，與側欄角標同源，避免把全時段 pending 與今日缺評量錯誤加總
- Changed 老師 `GET learning-records` 排除已取消堂次之綁定列；主任清單仍可查（行政追蹤）
- Added `me/learning-pending-summary` 回傳 `changes_requested_learning_records` 供工作台「需修改」計數
- Changed 老師評量頁課表不再列出已取消堂次（主任視角不變）

---

## 2026-05-09 — feat(parent): 家長互動狀態流、更新卡與通知整併

- Added 家長入口新增互動狀態流（`submitted / in_progress / resolved`）與最後更新時間，家長可直接知道學校是否已接手處理
- Added 家長入口新增白話版「版本更新」卡片，可查看最近更新重點
- Added 家長端新增通知整併顯示（同目標事項聚合），降低重複提醒造成的疲勞
- Added 家長入口補上非阻塞事件追蹤（進度卡點擊、版本卡開啟、請假送出、回饋送出）

---

## 2026-05-09 — feat(ux): 主任總覽核心檢視與家長入口進度中心

- Added 主任總覽新增「核心檢視 / 完整檢視」雙模式，預設只顯示今日必處理事項，把次要分析（履歷/填寫率/代課）收進完整檢視以降噪
- Added 家長入口新增「進度中心」四卡（本週學習、下次課程、待處理事項、繳費狀態），每卡都有一鍵 CTA 直達對應分頁，把家長從「查資料」變成「採取下一步」
- Added 家長 dashboard API 補上 `progress_summary` 摘要欄位，給進度中心使用並支援後續通知與報表治理
- Changed 主任總覽工作區左右視覺重量重新平衡，避免卡片偏重某一側

---

## 2026-05-09 — feat(adoption): 主任/老師首頁新增任務追蹤與使用率指標

- Added 主任與老師首頁新增「優先待辦卡」與一鍵深連結，讓每天先處理最重要事項，降低回到紙本/Excel 的機率
- Added 主任首頁新增「流程追蹤、近期操作履歷、每週使用率 KPI」，可直接看到未完成工作、近況與系統採用率
- Added 課程請假/調課/取消流程補上送出前影響提示與操作追蹤事件，提升關鍵流程可預期性與信任感
- Changed 主任總覽桌機工作區改為左右等寬對稱版面，降低閱讀偏重與視覺失衡
- Added 老師登入若仍有未完成事項會播放一次提示音，並提供「提示音開關＋今日靜音」避免提醒疲勞
- Added 通知中心加入企業視圖（待處理優先 / SLA 優先 / 高風險）與同類通知聚合，讓主任可快速鎖定真正要先處理的事件
- Changed 採用率指標加入「今日應處理/已完成/已逾期」與「較上週開啟率差值」，提升管理層判讀效率

---

## 2026-05-09 — chore(docs): 文件治理與記憶保鮮自動檢查

- Added 新增文件治理 SOP（每日/每週/每月節奏）與 MemPalace 保鮮流程，降低 AI 文件失憶風險
- Added 新增 docs integrity 自動檢查（PR + 每週排程），驗證 INDEX 導航、核心文件存在與 Markdown 連結完整性

---

## 2026-05-09 — fix(learning): 未到課堂次禁止填評量並修正主任待審顯示

- Fixed 後端評量寫入改為一律擋下未來堂次（即使缺少開始時間也不放行），避免老師提前填寫
- Fixed 主任總覽待審評量改為只顯示已到期堂次，避免把 6 月等未來課堂列入今日待辦

---

## 2026-05-09 — fix(course): 一般請假新增 30 秒內撤銷

- Added 一般請假提交後提供 30 秒「復原」入口，可自動回復請假堂次與順延尾堂（補請假維持不可即時撤銷）

---

## 2026-05-09 — feat(course): 請假操作加入影響預覽與必讀確認

- Added 課程管理的單堂請假、補請假與連假批次請假加入送出前影響預覽，需確認堂數、評量與順延影響後才可送出

---

## 2026-05-09 — feat(ui): 登入 Logo 動畫升級為爆發式縮放

- Changed 登入成功動畫改為爆發式 Logo 放大回彈（含衝擊波與白閃掃光），強化第一眼的震撼感

---

## 2026-05-09 — fix(ui): 桌機登入動畫在 reduced-motion 設定下仍可見

- Fixed 登入成功後品牌進場層不再因桌機 `prefers-reduced-motion` 而整段跳過，確保桌機與手機都能看到登入提示

---

## 2026-05-09 — fix(ui): 版本號改三段式並強化登入後動畫可見性

- Changed 版本更新代號改為全歷史連續三段式（`1.0.1` 起依時間遞增）並維持白話摘要
- Fixed 登入成功後品牌動畫層級與觸發條件，避免被更新提示覆蓋導致看不到動畫
- Changed 版本更新頁說明文案移除「像遊戲公告」描述，改為正式語氣

---

## 2026-05-09 — feat(ui): Dashboard 欄位重排與系統待機動畫

- Changed 主任總覽桌面版改成左側主作業、右側監控摘要，降低左右欄不平均感
- Added 登入成功後品牌進場動畫與系統內閒置 Logo 待機動畫，互動後自動淡出

---

## 2026-05-09 — feat(ui): 版本更新改為白話版本卡

- Changed 版本更新改為日曆版本卡（如 v2026.5.9）與白話分類摘要，技術細節保留在完整 CHANGELOG

---

## 2026-05-09 — feat(ui): 版本更新由 CHANGELOG 同步、主任總覽桌面版更緊湊

- Added `scripts/changelog-to-release-notes.mjs`＋`npm run sync-release-notes`：`build` 與 CI 先從 `docs/CHANGELOG.md` 產生課程向更新卡（略過純維運／chore／docs 類標題）
- Changed 主任總覽 `≥1100px` 縮短上下節奏；長列表區塊改為區內捲動以降低整頁長度
- Added「版本更新」頁底 GitHub CHANGELOG 連結

---

## 2026-05-09 — Fixed（ui）: Super Admin 「版本更新」空白

- Fixed `notesForRole`：super_admin／admin 對齊可看主任／老師向發布備註；CI 增加 `npm run test:release-notes`

---

## 2026-05-09 — Added（ops）: 主任／維運合併重複老師帳號 Artisan

- Added `php artisan teachers:merge-users --keep-login=… --merge-login=…`（預設 dry-run；`--apply` 於備份後交易內重設 FK、停用重複帳號）；搭配 PHPUnit `TeacherUserMergeCommandTest`

---

## 2026-05-09 — fix(learning): 暫停課程最後堂 scheduled 未回寫仍可見待審評量

- Fixed `LearningRecord::excludePausedCoursePendingReview`：課程已 Stop 且堂次結束時間已過但仍為 `scheduled` 時，保留 `pending`／`changes_requested` 於列表（避免最後一堂評量永遠載不出、主任無法退回）

---

## 2026-05-09 — fix(substitute): 調課時代課老師被合約老師時段誤阻修正

- Fixed `ScheduleController::store` FR-003：建立調課目標排程列時，若合約老師(A)已有代課老師(B)指派，改以B為基準做衝堂檢查，避免A的其他課程錯誤阻擋有效的調課操作

---

## 2026-05-09 — fix(substitute): 代課老師衝堂誤報修正（#275）

- Fixed `SubstituteService::collectTeacherBusySlots` / `collectTeacherBusySlotsWithCapacity`：合約老師的課若已有代課安排，不再誤標為忙碌；修正代課選人 modal 錯誤顯示「在其他分校有課」的 false positive

---

## 2026-05-09 — fix(schedule): 代課後調課寫 schedules 自動採 effective 代課老師

- Fixed `POST /schedules` 對 `scheduled` + `original_schedule_id`（調課目標列）先做 anchor 鏈結代課老師消解，避免請求沿用 `StudentClass.TeacherID` 觸發假性撞課；行事曆 `submitReschedule` 同步將 `teacher_id` 對齊已存在的代課列

---

## 2026-05-09 — feat(learning): 老師評量待辦角標與一鍵開填、主任填寫率報表

- Added 教學工作台優先開下一筆待填／`GET me/learning-pending-summary` 角標；主任儀表板近 14 天各老師已到班堂次之評量進度填寫率（`GET reports/teacher-learning-fill-rates`）

---

## 2026-05-09 — feat(ui): 系統內建「版本更新」頁面（老師/主任）

- Added 老師與主任側欄新增「版本更新」入口，集中顯示近期版本新增功能與修正重點，降低口頭公告成本

---

## 2026-05-09 — feat(ui): 首次登入顯示「新版重點」導覽卡

- Added 老師與主任登入後首次會看到簡短新版提醒，支援「立即查看 / 稍後再看」；文案改為非技術語言，讓現場同仁更容易理解

---

## 2026-05-09 — fix(ops): `artisan fix` 防呆提示（避免 namespace 例外）

- Fixed 新增 `artisan fix` 說明命令，當只輸入 `fix` 時改回傳可用修復命令提示，避免 `NamespaceNotFoundException` 進入 Sentry（#271）

---

## 2026-05-09 — td(attendance): TD-016 停用課程孤兒堂次修復（#270）

- Added `artisan fix:orphan-scheduled-sessions`（支援 `--dry-run`）：掃描 `Stop=1` 課程殘留未來 scheduled ClassSession 並取消；生產執行清除 `StudentClass#526` 的 4 筆孤兒堂次；附 regression tests

---

## 2026-05-09 — chore(ci): Golden scenarios 自動報告（取代人工勾選）

- Added `.github/scripts/golden-ci-report.sh`（放於 `.github/` 下，避免僅 CI 工具卻觸發 `deploy.yml` 的 `scripts/` deployable）；Presubmit CHECK 6、`ci.yml` **Golden scenarios report**；`QA_GOLDEN_SCENARIOS`／`INDEX`／PR 模板；`ENTERPRISE_WORKFLOW_ALIGNMENT.md`／`CONTRIBUTING` 導航

---

## 2026-05-09 — fix(import): 學生名冊 CSV/XLSX 標題列與 0 列匯入

- Fixed `ImportController`：標題列掃描加深至前 30 列、`normalizeHeader` 支援全形空白；若僅有表頭無資料列回 **422** 並寫入可讀 `ErrorLog`；補 `StudentImportTest` 迴歸案例（#205）

---

## 2026-05-09 — docs(ops): Pi 溫度告警與老師簽到診斷 SOP

- Changed `docs/OPERATIONS_RUNBOOK.md` §B2b／B2c：`pi-health`／`teacher-signin-diagnose` 使用方式與紀律（#195、#197）

---

## 2026-05-09 — docs(ai): CLAUDE 移除重複 MASTER WORKFLOW 區塊

- Changed `CLAUDE.md`：長版 Phase／角色規格改指向 `.cursorrules` 為單一權威，避免與 always-loaded 規則重複分叉（#203）

---

## 2026-05-09 — ci(security): PR Dependency Review workflow（供應鏈，選用 GHAS）

- Added `.github/workflows/dependency-review.yml`：`fail-on-severity: high`、`ubuntu-latest`；預設略過官方 action（未開 GHAS 時避免 PR 全紅），Repo 變數 `ENABLE_DEPENDENCY_REVIEW=true` 後啟用；`CONTRIBUTING`／`INDEX` 補說明；主線仍靠 `ci.yml` audit

---

## 2026-05-09 — chore(github): PR 模板補 Refs／Closes 規則

- Changed `.github/pull_request_template.md`：多階段／Epic issue 預設用 `Refs`，全案驗收完成才用 `Closes`；並調整 merge 前 CI／CHANGELOG／高風險模組檢查清單

---

## 2026-05-09 — chore(github): Issue 模板、CONTRIBUTING、SECURITY 與 CODEOWNERS 補強

- Added `.github/ISSUE_TEMPLATE/*` + `config.yml`、根目錄 `CONTRIBUTING.md`／`SECURITY.md`；補 `CODEOWNERS` 納入 `backend/routes/api.php` 與 `docs/DANGEROUS_OPERATIONS.md`；`INDEX`／`README`／`AGENTS` 導航更新

---

## 2026-05-09 — ops(ci): presubmit alwaysApply soft warning（>400）

- Changed `presubmit.yml`：alwaysApply 總行數超過 400 先輸出 GitHub warning（不干擾 merge），超過 500 仍 hard fail

---

## 2026-05-09 — fix(calendar): 行事曆改用 occurrence 合約合併

- Fixed 智慧行事曆週檢視以單一 occurrence resolver 合併 `StudentClass`、`ClassSession` 與 `schedules`，避免同一堂課重複掛兩位老師或被 scheduled 例外互相抵消而消失

---

## 2026-05-09 — fix(calendar): 停用舊課程不再重複掛兩位老師

- Fixed 智慧行事曆載入課程時排除 `status=inactive` 或 `Stop=1` 的舊課，避免同學生在舊/新老師欄位同時出現

---

## 2026-05-09 — ops(ci): PHPStan 明確改為 advisory gate

- Ops 將 workflow job 名稱改為 `PHPStan Advisory (php)`，並同步 main branch protection required checks 移除舊 `PHPStan (php)`，避免「看似硬擋實際不擋」語意落差

---

## 2026-05-09 — ops(db): PITR/binlog 評估決策（先 defer）

- Ops/DBA 在 runbook 明確記錄 `#207` 決策：目前先不啟用 production binlog，補齊觸發條件與 pre-enable checklist（限 drill DB 驗證）後再啟動

---

## 2026-05-09 — ops(backups): nightly 移除 Pi 端 git push/tag

- Ops 調整 `scripts/nightly-backup.sh` 僅保留 DB 備份與保留策略，不再從 Pi 嘗試 `git-sync` 或 nightly tag push，與 protected-main 治理一致

---

## 2026-05-09 — ops(actions): 降低排程 Actions 依賴並補 fallback

- Ops 將 `pi-health.yml` 降為每日、`branch-hygiene.yml` 降為每週，並在 runbook 補齊「minutes 耗盡時由 Pi 本機 monitor-alert + UptimeRobot 承接」與恢復後 rerun 清單

---

## 2026-05-09 — ops(backups): 新增一鍵備份稽核腳本

- Ops 新增 `scripts/backup-audit.sh`，集中檢查本機備份、manifest、Google Drive 異地同步、restore drill 結果與只讀 row-count sanity，並以 GREEN/YELLOW/RED 輸出總結

---

## 2026-05-08 — ops(ci): 補齊 WSL2 runner 硬化維運 SOP

- Ops 新增 self-hosted runner 邊界、健康檢查、離線恢復與疑似入侵停用流程，確保 CI-only runner 不承載 production deploy secrets

---

## 2026-05-08 — security(ops): 維運 SSH 改為 host key pinning

- Security 將 `pi-health.yml`、`backup-restore-test.yml`、`slow-query-report.yml` 移除 `StrictHostKeyChecking no`，改用 `PI_HOST_KEY` + `known_hosts` pinning，並在 Presubmit 增加禁止回歸檢查

---

## 2026-05-08 — ops(ci): docs-only PR required checks 對齊

- Ops 調整 `ci.yml` 改為所有 PR 皆觸發並保留 required check context，再用 changed-areas job 決定是否執行 PHPUnit/Vite，修復 docs-only PR 缺少 required checks 無法合併

---

## 2026-05-08 — fix(schedule): 代課堂次顯示在代課老師欄

- Fixed 同一堂代課若殘留原老師與代課老師的重複排程紀錄，行事曆會優先顯示在代課老師欄位

---

## 2026-05-08 — Changed: 課表捲動與學生姓名可讀性

- Changed 智慧行事曆捲動時固定老師/日期標題列，並放大課程卡片學生姓名以改善滿段時的辨識度

---

## 2026-05-08 — fix(schedule): 調課後行事曆避免重複顯示

- Fixed 拖曳移動課表時段後可能留下重複調課目標紀錄，導致行事曆多顯示一堂的問題

---

## 2026-05-08 — fix(attendance): 手機版補點名操作入口可見

- Fixed 手機版出勤頁「待補點名」日期查詢與補登操作容易被底部導覽遮住，改為直向排列並加安全留白

---

## 2026-05-08 — fix(schedule): 行事曆顯示現任課程老師

- Fixed 行事曆堂次老師名稱會被舊學習評量老師覆蓋的問題，課程改老師後圖表改顯示現任老師

---

## 2026-05-08 — fix(schedule): 補請假指定同日正確堂次

- Fixed 出缺勤頁補請假會帶入 `ClassSessionID`，後端依指定堂次處理，避免同一天同課程多堂時誤改第一堂

---

## 2026-05-08 — security(deps): 升級 PhpSpreadsheet 修復 CI audit HIGH 漏洞

- Security 升級 `phpoffice/phpspreadsheet` 至安全版，清除 Composer audit 的 HIGH/CRITICAL 阻擋項目

---

## 2026-05-08 — fix(schedule): 調課時取消自動補建的重複堂次

- Fixed 調課流程若先由 `schedules` 自動補建目標日堂次，移動原 `ClassSession` 時會取消該占位堂，避免目標日出現兩筆有效堂次

---

## 2026-05-08 — fix(attendance): 同時段重複堂次不可重複扣堂

- Fixed 點名頁遇到同課程同日期同開始時間的重複 `ClassSession` 時只顯示一筆，且後端阻止第二筆有效點名再次扣堂

---

## 2026-05-08 — fix(billing): 課程編輯繳費日期同步課程欄顯示

- Fixed 個別課程編輯調整 `paid_at` 後，會同步更新該課程最新有效收款日期，避免課程欄仍顯示舊繳費日期造成「改了但看起來沒生效」的誤解

---

## 2026-05-08 — fix(schedule): 例外堂補建 ClassSession 避免灰色堂次消失

- Fixed `schedules` 新增/更新為可上課狀態時會冪等補建對應 `ClassSession`，並新增 backfill 指令修復歷史漏建，避免課程列表有堂次但行事曆不顯示

---

## 2026-05-08 — fix(learning): 補填提醒跳轉與評量課表對齊

- Fixed 教學工作台補填提醒點入時會同步切換至對應分校並定位堂次，且評量頁可顯示近期需補填的非 active 課程，避免提醒可見但無法填寫

---

## 2026-04-30 — feat(workflow): 請假補課前端入口

- Added 家長端請假申請入口與主任端補課案件卡片，可產生候選時段並確認建立補課

---

## 2026-04-29 — feat(workflow): 補課候選確認 API

- Added 主任確認補課候選時段後，系統會冪等建立補課 schedules 與 ClassSession，並將 workflow 標記為 confirmed

---

## 2026-04-29 — feat(workflow): 補課候選時段產生器 API

- Added 主任可為請假 workflow 產生補課候選時段快照，依老師同時段容量避開滿載時段並保留分校隔離

---

## 2026-04-29 — feat(workflow): 家長請假申請與主任閉環 inbox API

- Added 家長請假申請會建立冪等 exception workflow，並新增主任依分校查詢請假補課 workflow inbox API

---

## 2026-04-29 — feat(workflow): 請假補課閉環核心資料層

- Added 例外事件閉環與補課候選快照的核心資料表、Model 與冪等 service skeleton，作為後續請假補課自動化基礎

---

## 2026-04-29 — feat(course): 多科共用堂數方案入口與加購分流

- Added 多科共用方案進階入口與方案成員加購分流，方案課程續報會增加共用方案總堂數，並補強舊課程綁定 guard

---

## 2026-04-29 — fix(billing): 課程繳費日期與帳單視窗一致

- Fixed 課程管理歷史課程繳費日期優先顯示實際帳單收款日，並修正帳單/對帳記錄視窗寬表格造成右上按鈕偏移

---

## 2026-04-29 — fix(course): 例外堂請假取消狀態標籤優先

- Fixed 課程管理例外堂被請假或取消後，日期 chip 外層標籤優先顯示請假/取消，例外堂改保留在提示資訊
- Ops Security Scan 對所有 PR 產生 required check，避免 frontend/docs PR 因 `PHPStan (php)` 缺席被 branch protection 卡住

---

## 2026-04-29 — docs(sop): 規劃研究加入開源專案參考

- Changed PRD/Bug/Agent SOP 規劃研究順序，除本專案文件與業界做法外，必須補查相關開源專案實作與取捨

---

## 2026-04-29 — fix(learning): 代課老師評量權限精準匹配時段

- Fixed 同一學生課程同日多時段時，代課老師評量列表與儲存權限改以同日期同開始時間判定，避免看到非自己時段後儲存 Forbidden

---

## 2026-04-29 — fix(parent): 家長端月結與繳費提醒語意

- Fixed 家長端月結課程改用課程帳期月份顯示，已繳停課月結課保留已繳費主狀態，且已繳低堂數舊約不再顯示成家長待繳費

---

## 2026-04-29 — ops(ci): WSL2 self-hosted runner 啟用

- Ops 將 CI / Presubmit / PHPStan checks 移至 WSL2 self-hosted runner，保留 `deploy.yml` 只用 GitHub-hosted runner，並更新文件避免 runner / DB secret 舊說法誤導

---

## 2026-04-29 — fix(import): 學生名單匯入標題列容錯

- Fixed 學生 CSV/XLSX 匯入可跳過檔案前方說明列尋找真正標題，並在失敗時顯示匯入錯誤而非誤導為 0 筆

---

## 2026-04-29 — fix(auth): 老師註冊密碼規則同步

- Fixed 老師自行註冊、主任新增老師與主任申請頁的密碼提示/前端檢查同步為 8 碼，並以緊急前端靜態檔部署上線

---

## 2026-04-29 — fix(attendance): 大直今日點名重複資料修復

- Fixed 大直周宏謙今日點名總表因停用舊課程殘留 scheduled 堂次而重複顯示，已備份後取消單筆舊堂次

---

## 2026-04-29 — fix(attendance): 老師登入帳號診斷補充 User fallback

- Changed read-only teacher sign-in diagnostic to report matching `User` / `UserCampus` / disabled `Teacher` rows when a login has no active teacher profile

---

## 2026-04-29 — fix(attendance): 老師打卡診斷支援登入帳號

- Changed read-only teacher sign-in diagnostic workflow to support exact `LoginName` lookup when the displayed teacher name is unknown

---

## 2026-04-29 — fix(attendance): 老師打卡只讀診斷工具

- Added read-only teacher sign-in diagnostic command and manual workflow to inspect missing sign-ins without modifying production data

---

## 2026-04-29 — ops(attendance): 老師打卡補登受控 workflow

- Added manual teacher sign-in recovery workflow with dry-run default, allowlisted command execution, and automatic DB backup before apply

---

## 2026-04-29 — fix(attendance): 老師 RFID 衝突歷史補登工具

- Added dry-run-first recovery command for historical teacher RFID collisions, so swallowed teacher sign-ins can be audited and restored without deleting original attendance data

---

## 2026-04-29 — chore(ci): GitHub Actions 額度節流

- Changed PR/main workflows to avoid waking heavy CI/security runs for docs-only changes and cancel stale Presubmit runs on repeated pushes

---

## 2026-04-29 — fix(mobile): 課程編輯儲存按鈕可點擊

- Fixed 手機版 modal 會被底部導覽列蓋住的層級與 safe-area 間距，避免 iPhone 12 mini 編輯課程時按不到儲存

---

## 2026-04-28 — fix(billing): 未收款錯帳作廢容錯

- Fixed 未收款錯帳即使從沖銷作廢入口送出也會安全落到一般作廢，避免歷史幽靈應收無法處理

---

## 2026-04-28 — fix(billing): 對帳視窗帳單作廢入口

- Fixed 學生帳務對帳視窗依 ledger 權限顯示一般作廢或沖銷作廢，避免未收款錯帳誤走沖銷流程

---

## 2026-04-28 — fix(billing): 錯帳沖銷作廢與 ledger 狀態

- Fixed 課程帳單對已收足額但狀態未繳的歷史錯帳顯示 ledger 例外，並提供保留收款稽核的沖銷作廢入口

---

## 2026-04-28 — fix(dashboard): 代課動態卡片標題對齊

- Fixed 總覽代課動態卡片在子元件內補齊 header/badge 樣式，貼齊今日課表排版並避免裸排版

---

## 2026-04-28 — fix(calendar): 請假卡被調課例外遮蔽

- Fixed 智慧行事曆同課程同日同時存在請假與 scheduled 例外時保留請假卡，避免張正樂 4/29 請假在課表消失

---

## 2026-04-28 — fix(billing-ui): 帳單作廢入口與代課卡片字型

- Added 課程管理月結帳單作廢入口，限制未收款帳單必填原因並保留稽核紀錄，同時修正總覽代課動態卡片排版與 CJK 字型 fallback

---

## 2026-04-28 — ops(backups): 備份健康檢查防假綠

- Ops 備份還原與 Pi health 檢查改以 sixhour 檔名時間選最新備份，避免檔案 mtime 被更新時誤判備份仍新鮮

---

## 2026-04-28 — fix(rfid): 老師分校卡優先辨識

- Fixed 同一張 RFID 同時命中大安老師分校卡與學生卡時，刷卡會優先建立老師打卡，避免老師出缺勤列表看不到本人刷卡紀錄

---

## 2026-04-28 — fix(records-billing): 歷史評量與作廢帳單排除

- Fixed 已上課歷史評量不再因課程後續停用而消失，並讓作廢 Invoice 排除於家長應收、課程帳單與主任催繳統計

---

## 2026-04-28 — fix(billing): 已付課程殘留帳單防重核帳

- Fixed 已付課程即使殘留未繳 Invoice 也不可再被主任核帳或家長回報確認入帳，避免同一門課產生錯誤對帳紀錄

---

## 2026-04-28 — feat(dashboard): 總覽儀表板高級視覺校準

- Changed 主任總覽儀表板與近 7 天代課紀錄卡片的字體、間距與資訊層級，延續 Porsche-inspired light-first 視覺系統

---

## 2026-04-28 — fix(accounting): 已結清查詢與例外處理

- Changed 帳務中心新增完整已結清查詢並將 AR ledger 例外帳集中提供撤銷/沖銷處理入口，堂數制課程也可從課程管理開啟帳單/對帳

## 2026-04-28 — fix(substitute): 單堂代課可回正班老師

- Fixed 課程管理單堂代課後可直接回復正班老師，避免正班老師被代課選擇器排除而搜尋不到

## 2026-04-28 — fix(accounting): 帳單套用與溢收標示

- Fixed 學生 AR 對帳表將同一帳單多筆收款依應收金額分流為已套用、溢收/待沖銷，並改用正式帳單/收據/課程編號避免 DB ID 混淆

## 2026-04-28 — fix(accounting): 學生帳務對帳表

- Added 帳務中心與課程帳單共用學生 AR 對帳表，將 Invoice、Payment、Receipt 與沖銷流水收斂到同一視圖並標示異常

## 2026-04-28 — fix(scheduling): 請假順延補堂防半套資料

- Fixed 行事曆請假失敗時不再直接寫入 `schedules`，並補上既有請假半套資料可安全重跑順延、補足購買堂數的 regression coverage

## 2026-04-28 — docs(agents): 外部 agent playbook 引用準則

- Added `agency-agents` 可參考但不可整包覆蓋 AllTrue P0/SOP 的整合準則

## 2026-04-28 — fix(accounting): 收款與帳單稽核口徑

- Changed 帳務中心區分有效收款流水與對應課程數，並在課程帳單展開付款/沖銷明細與收據號以利錯帳更正

## 2026-04-28 — fix(billing): 月結提醒使用逐期帳單應繳日

- Fixed 月結課程已預建未來期帳單時，繳費提醒改以未結清 Invoice 的 DueDate/billing_period 判斷，避免未來月份被誤判成本月逾期

## 2026-04-28 — fix(accounting): 逐筆錯帳撤銷入口

- Changed 收款與收據紀錄可直接逐筆撤銷已核帳收款，強制填寫原因並保留負值沖銷稽核，不刪除原付款資料

## 2026-04-28 — fix(attendance): 出缺勤紀錄日期顯示

- Changed 出缺勤紀錄預設顯示今天，最近 7 天模式新增日期欄避免只看時間難以判讀

## 2026-04-28 — fix(accounting): 收據與繳費單品牌語意

- Changed 繳費單與收據抬頭顯示完整補習班品牌與分校，並合併帳務中心收款／收據紀錄避免重複分頁混淆

## 2026-04-28 — fix(courses): 改正式老師保留跨日時段

- Fixed 課程編輯只改正式老師時，週三+週日等跨日固定時段不再被剩餘舊堂次洗成只剩週三

## 2026-04-28 — fix(courses): 固定時段契約不再被舊堂次覆蓋

- Fixed 課程編輯新增週日固定時段後，後端不再用舊未來堂次反寫覆蓋新排課契約

## 2026-04-28 — fix(courses): 固定時段列式編輯與核帳防重補強

- Fixed 課程編輯新增固定時段可直接在每列選週日，並補上家長回報核帳確認路徑的已繳課程重複入帳防護

## 2026-04-28 — fix(courses): 編輯多日課表與重複核帳防護

- Fixed 課程編輯週三+週日等多日固定時段被漏存，以及已繳課程可被再次核帳產生多筆付款的問題

## 2026-04-28 — fix(courses): 帳單日期與編輯多時段同步

- Fixed 月結帳單 Modal 區分應繳日與實際付款日，並修正課程編輯新增同日多固定時段時未來堂次仍只保留單時段的問題

## 2026-04-28 — fix(billing): 月結續報新一期與堂數超排防呆

- Fixed 月結續報改為建立新一期課程並結算舊期，且堂數詳情會區分補課例外與超出購買堂數的異常堂次

## 2026-04-27 — feat(ui): Porsche 視覺系統規格

- Added AllTrue Porsche-inspired light-first 視覺系統規格與共用前端 token/class，作為後續頁面升級的單一設計依據

## 2026-04-27 — feat(dashboard): Porsche-style 工作面板

- Changed 主任儀表板今日課表、繳費提醒、待審評量、通知與 KPI 面板為 light-first Porsche-style 霧面工作卡

## 2026-04-27 — feat(ui): Porsche-inspired 視覺校準

- Changed 課程管理與主任儀表板首屏為 light-first 高級霧面視覺，收斂過重 HUD/雷達感並統一 Porsche-inspired 設計語言

## 2026-04-27 — feat(dashboard): 主任指揮艙首屏

- Added 主任儀表板首屏的高級營運指揮艙視覺，包含分校 hero、每日待辦任務列與 performance HUD 統計

## 2026-04-27 — feat(courses): AAA 狀態與 Modal 補強

- Added 課程管理空狀態、loading skeleton、歷史課程卡與高風險 modal 的 AAA 視覺一致性補強

## 2026-04-27 — feat(courses): 戰術課程列表視覺

- Added 課程管理學生群組、課程 row、操作按鈕與詳情面板的戰術資訊列視覺，提升掃描速度與高風險狀態辨識度

## 2026-04-27 — feat(courses): Porsche-inspired command center 首屏

- Added 課程管理首屏的高級性能指揮艙視覺：深色霧面 hero、精準控制列、性能儀表統計與玻璃質感背景

## 2026-04-27 — feat(courses): 高質感課程列表

- Added 課程管理主列表、學生群組卡、課程狀態標籤、歷史課程卡與空狀態的 premium 視覺層級，並補上初次載入 skeleton

## 2026-04-27 — feat(courses): 高質感課程操作 Modal

- Added 課程管理加購、月結續約、暫停/恢復、刪除確認 modal 的 premium command-card 視覺層級，提升高風險操作的可讀性與辨識度

## 2026-04-27 — fix(courses): 複雜流程送出中防呆

- Fixed 課程管理加購、月結續約、暫停/恢復、刪除在送出中會鎖定按鈕與 modal，降低雙擊或重送造成重複操作的風險

## 2026-04-27 — fix(billing): 續報重複送出防護

- Fixed 續報確認與舊加購入口會鎖定來源課程並拒絕相同學生、科目、開課日與堂數的重複批次，避免雙擊或重送產生重複課程

## 2026-04-27 — fix(courses): 課程管理顯示月結逐期帳單

- Added 課程管理月結課程「帳單」入口，主任可查看各期 billing period 的已繳、未繳、部分繳狀態

## 2026-04-27 — fix(billing): 續報重複送出防護

- Fixed 續報確認與舊加購入口會鎖定來源課程並拒絕相同學生、科目、開課日與堂數的重複批次，避免雙擊或重送產生重複課程

## 2026-04-27 — feat(billing): 續報 preview/confirm 防呆 API

- Added 續報預覽與確認 API，確認前回傳帳單、排課與風險摘要，並用 state hash 防止課程資料變更後誤送舊確認

## 2026-04-27 — fix(billing): 0元課程可核帳結算

- Fixed 輔導課、試聽課等 Charge=0 課程可用 NT$0 完成主任核帳，避免免費課程永遠停留在未繳狀態
- Fixed 通知中心「確認已繳費」同樣支援 NT$0 免費課程結算
- Fixed 智慧課表同一學生同一天同開始時間已有基底課程時，不再重複渲染 scheduled 例外列

## 2026-04-27 — feat(billing): 月結制逐期帳單，修復白嫖漏洞

- Fixed 月結課程「月結續約」後 `StudentClass.Paid` 不再維持 1；renew-monthly 同步重置 Paid=0 並建立新期 Invoice（billing_period YYYY-MM），杜絕後續月份免費白嫖
- Added `Invoice.billing_period` 欄位（migration），實現業界「合約與帳單分離」設計
- Changed `directorRecord` 優先對應當月 billing_period 的未繳 Invoice，確保逐期帳務可追溯
- Added 新 API `GET /api/v1/student-classes/{id}/invoices`，回傳月結課程逐期帳單列表
- Added 前端月結課程新增「帳單」按鈕，可查逐期帳單 modal（期別、金額、狀態 chip）

## 2026-04-27 — fix(courses): 課程管理補課與暫停 UI SaaS 化

- Changed 課程管理將「新增堂次」統一改為「補課／補登」，重做暫停課程確認 modal 與狀態列，並修正單堂備註／時段的費用預覽語意

## 2026-04-27 — feat(courses): 統一課程方案建立入口

- Added 主任建立課程時可先選「每科獨立堂數」或「多科共用堂數」，保留既有一般課程與共用方案核心邏輯

## 2026-04-27 — docs(ops): 補 token 與 Actions 節流 SOP

- Changed Runbook 補低風險 docs 小修先累積、同類 docs 批次送出、避免混合 deployable diff 與 token conservation 規則
- Changed AGENTS 補 Agent Orchestration SOP：任務分級、bounded context 切分、artifact-only handoff 與 architecture boundary 原則
- Changed AI 公司組織圖新增 ORCH、INT、DOCS/MEM 職責，並明確小任務不啟動全流程
- Changed INDEX/Runbook/AGENTS 清理長篇 archive 判讀規則、branch protection 舊 reviewer 說法與過時協作者入口
- Security 必讀安全警示補強 code backup source of truth、DB offsite manifest、restore drill 與 production 禁令
- Changed Workflow 補 Risk Tier、Definition of Ready/Done、Stop-the-line 與 post-merge learning loop，對齊大廠與 AI orchestration 實務

## 2026-04-27 — docs(readme): 補 Architecture Diagram 與 ERD

- Changed README 新增 Mermaid Architecture Diagram、核心 ERD、Engineering Maturity 與 Known Gaps/Roadmap，方便對外展示系統成熟度

## 2026-04-27 — ops(governance): GitHub Pro 與備份成熟度補強

- Ops 啟用 `main` branch protection required checks，補強 Drive 備份 manifest、RPO/RTO/DR 演練 SOP，並登記 MySQL PITR/binlog 為 P1 技術債
- Ops 將 `scripts/` 納入 deployable diff，確保備份腳本變更會透過 `deploy.yml` 同步到 Pi

## 2026-04-27 — docs(governance): 清理 AI 導航過期連結

- Changed 文件導航、FAQ、部署與角色手冊移除不存在的舊入口連結與 `jerry-sync-main` 說法，統一為 feature branch → PR → CI → `deploy.yml`

## 2026-04-27 — changed(courses): 多科共用方案入口降噪

- Changed 新增課程預設直接進入一般課程建立，保留多科共用方案舊資料維護能力但不再作為主任日常新建入口

## 2026-04-27 — fix(courses): 推算月結堂次可單堂編輯

- Fixed 舊月結課程的推算預排日期可點擊建立實體堂次並進入單堂編輯，且同日期同時間不重複建立

## 2026-04-27 — fix(courses): legacy 月結詳情固定日期

- Fixed 舊月結課程缺 `EndDate` 或只有部分 `ClassSession` 時，課程詳情仍依固定星期顯示查詢月份的每週上課日期

## 2026-04-27 — fix(courses): 加購批次詳情顯示

- Fixed 堂數制加購成功後明確提示新批次課程與上課日期，並依課程期間載入詳情堂次，避免主任誤看原課程以為未更新

## 2026-04-27 — feat(learning-records): 主任給老師評語

- Added 學習評量表新增主任給老師的內部評語，老師可讀取並標記已讀

## 2026-04-27 — fix(courses): 預排堂次補齊與缺口校正

- Fixed 月結課程續約或編輯固定時段後補齊未來 `ClassSession`，讓課程管理詳情立即顯示該月固定預排堂次且不重複建立
- Fixed 堂數制補建堂次優先補中間缺口，避免漏排日期後在尾端多出第 N+1 堂

## 2026-04-27 — fix(courses): 結案課程取消未來堂次一致性

- Fixed 課程直接改為歷史狀態時也會取消未來 scheduled 堂次，避免結算後仍顯示未來排課

## 2026-04-27 — chore(campus): 新增新莊分校 & 中平分校

- Added 新莊分校（code: xinzhuang）、中平分校（code: zhongping）上線，migration 冪等零 downtime

## 2026-04-27 — feat(accounting): 帳務中心收款與收據紀錄

- Added 帳務中心新增已核帳收款清單、收據紀錄、預收標籤、CSV/PDF 匯出，並強化收據跨分校存取檢查

## 2026-04-27 — feat(monthly-courses): 月結建立課程日曆調整

- Added 月結制一般課程建立時可在日曆排除或加入初始堂次，避免建立後逐堂調課

## 2026-04-27 — chore(security): Composer audit warning 清償

- Security 升級 `league/commonmark` 至 2.8.2，並登記 Laravel major upgrade 技術債以追蹤剩餘 framework audit warning

## 2026-04-26 — feat(ambient-music): 工作音樂小彩蛋

- Added 主任／老師後台新增手動開啟的工作音樂播放器，使用瀏覽器本地合成音景避免第三方音檔授權風險
- Changed 工作音樂改用 AllTrue 自製 MP3（Tutoring Loop / Paper Window / Paperwork Rain）取代本地合成音景

## 2026-04-26 — fix(parent-feedback): 修復已讀反彈並強化回饋定位

- Fixed 家長回饋 mark-read 不再更新回饋內容時間，避免刷新後又變未讀；學習評量表新增有回饋／未讀回饋篩選

## 2026-04-26 — feat(learning-records): 家長溝通快捷片語優化

- Changed 學習評量「學習進度與家長溝通」快捷片語擴充為 10 個家長友善選項，預設顯示 6 個並可展開更多，降低老師撰寫評語負擔

## 2026-04-26 — feat(notifications): 通知中心核帳流程升級（PR #104）

- Added 通知中心「標記已繳費」可填繳費日期、方式、金額與備註，並同步建立 Payment / Invoice 核帳記錄
- Changed 繳費與堂數提醒的「前往處理」導向催繳名單，通知卡片補充學生、科目與金額摘要

## 2026-04-26 — fix(sentry): 處理 MIME type 錯誤 #100 及 N+1 誤判 #101（PR #102）

- Fixed 加入 `window.addEventListener('error')` 捕捉 MIME type 錯誤並自動 reload；Sentry ignoreErrors 加入對應樣式
- Fixed `classSessionsApi.js` CHUNK_SIZE 60→200，消除分批請求被 Sentry 誤判 N+1 的根因

## 2026-04-26 — fix(students): 月結課程在學生管理被誤判歷史課程而隱藏（PR #98）

- Fixed `isHistoricalCourse()` 對月結制（payment_type=monthly）課程缺少保護，RemainingSessions=0 + 已繳費導致課程被過濾，補習科目/堂數欄顯示「尚未設定」

## 2026-04-26 — feat(parent-feedback): 家長建議回饋系統（PR #94, #95）

- Added: 家長入口學習 Tab 底部新增「台北全真一對一補習班自主研發」品牌回饋卡片（分類 Chip + 星評 + 文字輸入，mobile-first）
- Added: 後端 `parent_feedback` 資料表 + `ParentFeedbackController`（4 支 API）
- Added: super_admin Bug 回報頁新增「家長回饋」Tab，含未讀 badge、分類篩選、標記已處理

## 2026-04-26 — feat(parent-portal): 三 Tab 架構改版，學習優先（PR #90）

- Changed: 家長入口頁改為三 Tab（學習/課表/帳務），預設進入「學習」Tab 顯示評量與出缺勤
- Changed: 移除 Profile Card 的剩餘堂數環形圖，降低「催費感」
- Changed: 繳費提醒移至「帳務」Tab，改柔和藍色 info-card 取代橙色警報樣式
- Added: 帳務 Tab 有待繳費項目時顯示紅色數字 badge

## 2026-04-26 — fix(ops): 還原 GitHub Actions SSH Deploy 三組 secrets（PI_SSH_KEY / PI_USER / PI_HOST）

- Ops: `PI_SSH_KEY` 還原為原始 `rpi_actions_deploy` 私鑰（指紋 `B/tQBH...`），修復 deploy.yml Permission denied
- Ops: `PI_USER` 由錯誤的 `admin@pi.lifenet.com.tw` 格式改回 `admin`，修復 pi-health.yml Invalid user
- Ops: `PI_HOST` 改回 `pi.lifenet.com.tw`，修復 pi-health.yml 連線

## 2026-04-26 — fix(security): 密碼改完撤銷全部 token + CSP Report-Only + debug_mode 監測 (PR #84)

- Security: `PUT /api/v1/me` 改密碼時刪除所有舊 AuthToken，簽發新 token（`new_token` 欄位），其他裝置自動失效
- Security: frontend 收到 `new_token` 自動更新 localStorage，當前工作階段不中斷
- Security: `GET /api/v1/health` 新增 `security.debug_mode` 欄位，可即時確認 production 是否 APP_DEBUG=true
- Security: `SecurityHeaders` middleware 加入 `Content-Security-Policy-Report-Only`，透過 Sentry CSP 端點接收 violation report（Report-Only 不擋截，安全）
- Test: 新增 `PasswordChangeTokenRevokeTest`（4 tests）；修復 `TeacherBulkAccountOnboardingTest` 使用密碼改後的新 token

## 2026-04-26 — chore: 補上最後 4 個工程缺口 (PR #83)

- Security: Composer audit 改為 HIGH/CRITICAL 才擋 CI，MEDIUM/LOW 僅印警告（目前 2 個 medium 不阻斷）
- Security: npm audit `--audit-level=high` 移除 fallback echo，HIGH+ 真正 fail CI
- Added: `GET /api/v1/health` 新增 `sentry.configured` + `sentry.sdk_bound` 欄位，可 API 驗證 Sentry 是否載入
- Changed: App.vue 所有 22 個頁面改為 `defineAsyncComponent` 懶載入，initial JS chunk 從 1176 KB 降至 **137 KB**（-88%）
- Changed: `vite.config.js` 加入 `manualChunks` 分離 vue / sentry vendor 到獨立 chunk，共 38 chunks
- Added: 新增 `RoomControllerTest`（6 tests）、`CampusControllerTest`（3 tests）、`TeacherBranchControllerTest`（5 tests）覆蓋 3 個先前 0% controller；coverage 64.6% → 65.0%

---

## 2026-04-26 — chore: 補上 3 項工程缺口 (#82)
- Changed: Coverage gate 從 warning 改為真正擋關（block < 60%，warn < 70%，目前 64.6%）
- Added: CI 自動生成 API route 文件（`php artisan route:list`）並上傳為 artifact
- Changed: `SENTRY_DSN` secret 更新為正確值；deploy.yml 自動注入 Pi `.env` 的 `SENTRY_LARAVEL_DSN`
- Ops: Branch protection (Gap 1) 確認需 GitHub Pro；local pre-push hook 維持現有保護

## 2026-04-26 — feat: 通知中心 v2 (#81)
- Changed: 類型下拉改為分類 Tab（全部/繳費/評量/刷卡/堂數/系統），各 Tab 顯示未讀計數徽章
- Changed: 「標記已繳費」改為彈出核帳確認 Modal，避免誤觸直接異動 DB
- Added: 「清除已解除」批次操作，將已解除通知標記已讀一鍵清除
- Added: 深度連結擴充 — `low_sessions` → 課程管理，`schedule_change/substitute_confirm` → 行事曆
- Changed: 通知 item 視覺層次強化 — 未讀藍色左邊框、urgent 紅色背景、已解除灰化刪除線
- Added: API 30 天封存過濾 — 已讀且超過 30 天的通知預設不顯示，未讀永遠顯示
- Added: 支援 `schedule_change`、`substitute_confirm`、`low_sessions` 類型 label 與樣式

---

## 2026-04-25 — fix: 家長入口跨家庭學生資料洩漏修復 (#74 #75) [SECURITY]
- Fixed: parent portal showed 7 unrelated students from different campuses as switchable siblings
- Root cause: 2026-04-16 backfill migration copied invalid/shared Student.LineID into student_line_bindings
- Fix 1 (code): filter line_user_id to valid LINE format (U+32hex) in sibling detection (login, switchStudent, dashboard)
- Fix 2 (data): migration to remove invalid-format LINE IDs from student_line_bindings
- Fix 3 (data): migration to remove cross-campus LINE user IDs (impossible for real family groups)

## 2026-04-25 — chore: P1 workflow improvements — PR template, PHPStan, smoke test, CODEOWNERS (#71)
- Added `.github/pull_request_template.md` for structured PR descriptions
- Added `.github/workflows/codeql.yml` with PHPStan level 5 static analysis (CodeQL requires GHAS on private repo)
- Enhanced `deploy.yml` smoke test: verify `/branches`, `/swipe-rfid`, `/auth/login` after deploy
- Added `.github/CODEOWNERS` to auto-request review for high-risk modules

## 2026-04-25 — feat: 學習評量頁家長回饋視覺化 (#67)
- Added orange left-border on unread feedback cards/rows; grey border for read
- Added `💬 未讀回饋` / `💬 家長回饋` chip with click-to-expand inline preview (marks as read)
- List view rows now show colour-coded left-border for feedback state

## 2026-04-25

- Added Sentry 錯誤追蹤整合：前端 @sentry/vue + 後端 sentry-laravel，production exception 自動上報（PR #61）
- Added P1+P2 工程改善：CI 安全掃描（npm audit + composer audit）、bundle size budget、pre-push/pre-commit git hooks、Dependabot（PR #56）
- Fixed 前端 bundle 與 version.json 被 git 追蹤舊版，每次 backend-only deploy 後 git reset 還原舊檔案導致 UI 退化；移除所有 build artifact 出 git、deploy 加 ASSETS_MISSING fallback 強制重 build（PR #55）
- Added 家長回饋系統內通知：側欄 badge + 5 分鐘輪詢、老師/主任首頁待辦卡、評量頁卡片/列表切換（PR #54）
- Fixed 學習評量表 API 全線 500：migration 偵測用 `grep "Pending"` 在 Laravel 8 失效（應為 `| No`），導致 `learning_record_feedbacks` 表未建立；同時加 `Schema::hasTable` guard 防止表不存在時 crash（PR #52）

- Fixed 跨校支援老師在出缺勤頁面提交「回報出入」回傳 403 Forbidden（PR #49）
- Fixed 教學工作台「補填提醒」點擊後無法找到昨日課程：自動切到正確的週並開啟填寫 modal（PR #50）
- Added 家長可在評量表留下回饋，老師與主任可在評量頁查看（PR #51）

## 2026-04-24

- Changed: 強制繳費核帳流程 — 標記已繳費必須填繳款日期（PaymentEntryModal），API 層同步加 422 guard（#48）

## 2026-04-24（深夜 v5）

### Fixed
- 版本標籤顯示「早上 8:00」：`App.vue` 的 `formatBuildTime` 將純日期字串（`YYYY-MM-DD`）傳入 `new Date()` 後被解析成 UTC 午夜，台灣 +8 小時後變 08:00。修復：偵測純日期格式直接回傳，不做 Date 轉換（見 `fix/version-display-timezone`）

## 2026-04-24（深夜 v4）

### Changed
- 版本顯示改回日期格式（`建置 2026-04-24`）：`vite.config.js` 的 `version.json` 輸出由 commit hash 改為 build 日期（`t` 欄位），同時保留 `hash` 欄位供開發者追溯（見 `chore/version-display-date`）

## 2026-04-24（深夜 v3）

### Fixed
- 出缺勤管理頁面一片空白（P0 regression hotfix）：`AttendancePage.vue` 中 `quickForm` ref 初始化時呼叫 `localTodayYmd()`，但 `localTodayYmd` 為 `const` arrow function 且宣告在後面（line 1620）→ JavaScript TDZ ReferenceError → `setup()` 中止 → 整頁空白。修復：將 `localTodayYmd` 宣告移到 `quickForm` 之前（line 1467）。由 PR #41 引入，PR #45 hotfix 修復。

## 2026-04-24（深夜 v2）

### Fixed
- 出勤頁「出缺勤紀錄」管理員預設改顯示最近 7 天（而非只有今天）：`AttendanceController::index` 新增 `start_date`/`end_date` 區間參數，無參數時預設最近 7 天；前端管理員加「最近 7 天 / 今天」快捷切換（見 `fix/attendance-range-view`，bugfix plan `bugfix_attendance_range_view_2026-04-24.md`）

## 2026-04-24（深夜）

### Fixed
- 補課流程三連修：(A) `ScheduleController::store` 補建補課時同步 `ClassSession::firstOrCreate`，出勤與評量頁面可見補課堂次；(B) `submitQuickAttend` 補上 `StudentID` 防 422 靜默失敗；(C) 老師快速點名加日期選擇器（最多回溯 14 天）；出勤頁加日期篩選器，管理員可查詢過去紀錄（見 `fix/makeup-attendance-flow`，bugfix plan `bugfix_makeup_class_session_missing_2026-04-24.md`）

---

## 2026-04-24（晚上）

### Fixed
- Bug 回報：附截圖時回傳 500 → 修 `deploy.yml` 補 `storage:link --force` + `chmod 775`；`BugReportService::attachUploadedFiles` / `AuthController::toAvatarUrl` 加 try-catch 讓存檔失敗降級（不中斷主流程），回傳 201 + `attachment_errors` 欄位（RC-1，見 `fix/bug-attachment-storage-500`）
- 家長入口評量：科目名稱顯示不一致（有的顯示 `English`、有的顯示 `英文課`）→ `ParentPortalController::resolveSubjectName` 邏輯修正：(1) 有課程科目且非 '課程' 時優先用課程名稱；(2) 課程無科目時對 `LearningRecord.Subject` 原始值套 `mapSubjectLabel`；(3) `mapSubjectLabel` 補中文別名（`英文課→英文`、`數學課→數學` 等）（PR #39 — 見 R11）

---

## 2026-04-24（下午）

### Fixed
- 家長入口：登入驗證改讀 `parent_phone`（UI「家長手機」欄），舊 `Phone` 欄 fallback 相容（PR #38 — 見 R10）

---

## 2026-04-24

### Security / Ops
- CI/CD: 移除 `ci.yml` 中的明碼 DB 密碼，改用 GitHub Secret `CI_DB_PASSWORD`（FR-007）
- CI/CD: `deploy.yml` 移除 `StrictHostKeyChecking=no`（`ssh-keyscan` 已在 Setup SSH 建立 known_hosts）（FR-008）

### Fixed
- CI/CD: `deploy.yml` 將 `php artisan optimize:clear` 改為 `config:cache && route:cache`，消除部署時短暫快取空白期（FR-004）

### Added
- CI/CD: `deploy.yml` 新增自動 rollback 機制—health check 失敗時自動執行 `git reset --hard`、前端重 build、快取重建、migration rollback（若有），並二次確認 health check（FR-005/006）
- CI/CD: `scripts/git-sync.sh` 加入 main branch 守門，在 main 執行時直接 abort 並提示建 feature branch（FR-009）
- CI/CD: `scripts/git-sync.sh` push 後自動執行 `gh pr create --fill --draft`（FR-010）

### Changed
- Docs: `README.md` GitHub 同步工作流程更新為 feature branch → PR → CI → merge 流程，移除過期的 `jerry-sync-main` 說明（FR-011）

### Ops（CI/CD 部署通道修復，同日下午）
- Pi: `StrictModes no` 加入 `/etc/ssh/sshd_config`（根因：`/home/admin` 為 `admin:www-data 775`，SSH 拒絕 public key — 見 R7）
- Pi: fail2ban unban 9 個 GitHub Actions runner IP + 永久白名單 GitHub Actions IP 範圍（`jail.local`）
- `deploy.yml`: `git pull` 改為 `git fetch origin main && git reset --hard origin/main`（根因：Pi 本地 nightly auto-commit 造成 divergent branches — 見 R9）
- `deploy.yml`: 移除 `composer install` 的 `--no-dev` flag（根因：Pi vendor 有舊 dev 安裝，--no-dev 造成 Collision class not found → health 500 — 見 R8）
- `deploy.yml`: `bootstrap/cache/packages.php` 部署前清除（搭配 R8 修復，已不需要，但保留作保險）
- **首次端對端自動部署成功**：`push → CI → deploy → health ok` 全流程驗證通過（2026-04-24 14:17 TWN）
- `deploy.yml`: `git diff` 偵測 `frontend/` 有無變動，無變動跳過 `npm run deploy`（docs/backend-only commit 不觸發用戶更新通知）
- `vite.config.js`: `version.json` 版本識別碼從 build 時間戳改為 git commit hash（相同 commit 多次 build 結果穩定）

---

## 2026-04-23

### Fixed (cont.)
- Tests: 7 個 time-sensitive tests 加入 `Carbon::setTestNow(today 10:00)` 修復午夜跨日 flaky（CI 22:00+ TWN 後 EndTime 變 "01:xx" 導致 session 窗口失敗）（PR #36）

### Fixed (cont.)
- Teacher attendance: 補卡後主表 `SignInDT`/`SignOutDT` 同步更新，前端不再顯示「未簽退」；`unclosed` 清單也正確排除已補卡記錄（PR #35）
- Teacher attendance: super_admin 傳入 `campus_id` 時現在會過濾至指定分校（不傳則維持看全部）（PR #35）

### Security / Fixed
- Teacher attendance: `index`/`unclosed`/`export`/`exportMonthly` 四個 API 加入 `campus_id` 參數隔離，修復多分校 director 可看到其他分校老師出勤記錄的越界問題（PR #34）
- Teacher attendance: `auth_campus_ids` 為空的非 super_admin 用戶改回 403，防止 bypass 全分校過濾（PR #34）

### Added
- Teacher attendance: `teacher-signin:close-orphans` 每日 00:05 自動補登前日未簽退記錄（SignOutDT=23:59, Status=adjusted, Memo=系統自動補登簽退）（PR #25）
- Migration: `TeacherSingIn.Memo varchar(512)` 補齊 migration 記錄（欄位本已存在 prod DB）（PR #25）

### Fixed
- Swipe: RFID 刷卡後同步 `ClassSession.Status`（attended/late），修復老師「待點名」計數虛高（PR #23）
- Swipe: `TeacherID=NULL` fallback 查詢，防止刷卡記錄從老師出缺勤視角消失（PR #23）
- Data patch: `StudentSingIn.id=945`（游家豫）`TeacherID` NULL→17（PR #23 前的歷史資料）
- Teacher attendance: 行政出勤狀態誤顯示「系統待確認」，backfill migration 修復（PR #19）
- TD-004: `findMatchingClass` 排除 `Status=leave` 的 ClassSession（PR #19）
- TD-006: 刷卡 60s debounce，防 RF bounce 秒速簽退（PR #19）
- TD-007: sign_in 前先查 ClassSession 是否已有記錄，重複刷回傳 `duplicate_ignored`（PR #19）
- TD-009: `backfillPresenceWindow` 加 EndTime null guard（PR #19）
- RFID: 同分校同卡不再靜默覆蓋，回傳 422（composite unique index）（PR #18）

### Added
- `AttendanceEffectsService`: ClassSession 狀態解析共用 Service（resolveSwipeStatus + applySessionStatus guard）（PR #23）
- 老師月報 XLSX 匯出：每位老師獨立 Sheet，左流水/右月曆格式（PR #19）
- `DELETE /attendance/{id}`: 軟刪除出缺勤記錄，自動沖回扣堂（PR #17）
- `POST /attendance/{id}/convert-to-attended`: 自修記錄轉到班（PR #17）
- TD-008: `CloseOrphanStudentSignIns` 每日 02:30 自動關閉孤兒記錄（PR #19）
- `docs/SYSTEM_TECH_GUIDE.md`: 後端技術實作索引（Identity/Swipe/ClassSession/Service 職責）

### Changed
- TD-011: `findMatchingClass` 窗口從 ±30min 改為 `(StartTime-30min) ≤ swipeAt ≤ EndTime`（PR #19）

### UI
- 教學工作台打卡狀態卡片：手機雙 chip 並排 + 彩色左邊框 + skeleton（PR #22）
- 出缺勤頁：自修記錄橘色 badge + 自修篩選器 + 刪除 Dialog + 轉換 Modal（PR #17）

---

## 2026-04-22

### Fixed
- `StudentsList`: 方案課程剩餘堂數 mapper 遺漏 `PackageID` 等欄位，顯示錯誤（§FRONTEND-005）
- 代課後調課：`schedules` 表未同步，代課老師顯示原老師（Issue #3）
- `directors/pending`: 排除被誤標為主任的教師帳號（Issue #6）
- `retroLeave`: 補請假重複 INSERT `StudentSignIn` 導致 500，改 `updateOrCreate`（Issue #2）

### Security
- Route throttle: `auth/register` 10/10min、`forgot-password` 5/60min、`swipe-rfid` 30/1min（SEC-002/003/006）
- 密碼最低長度全部入口統一 `min:8`（SEC-004）
- HTTP 安全標頭：HSTS / X-Frame-Options / nosniff / Referrer-Policy / Permissions-Policy（SEC-005）

### Ops
- 備份: KEEP=12（3 天）、nightly 統計告警、gdrive-sync sixhour 異地快照
- CI: `bootstrap.php` 加 `DB_DATABASE=AllTrue` 斷路器，防測試誤操作 production
- 月度還原演練 `monthly-restore-drill.sh`（每月 1 日 02:00，四表 row count 比對）

---

## 2026-04-21

### Fixed
- b7: 試聽容量誤判（`one_on_three` 被算入試聽名額）+ OPcache 陳舊導致調課失敗
- B1: 代點名代課可見性復發（nightly auto-backup 意外覆蓋修復 commit）
- C1: 代課後單堂顯示原老師（`start_time HH:MM:SS` 格式容錯，SUBSTRING 雙側比對）
- b5: 歷史堂數制課程 Charge 欄位錯誤（純資料修正，StudentClass ID=171，24000→12000）
- b3: 月結制課程無法進入歷史課程（`effectiveClosedReason` 月結分支補齊）
- b4: 月結制加購錯誤變堂數制（`renewMonthly` API + 前端分流）
- 繳費狀態切換未清除 `paid_at`（+ `SessionDeductionService` 移除誤清 `Paid=0` 邏輯）

### Added
- b6: 課表回報管理頁 30s 輪詢 + Nav Badge 每 60s 更新
- `opcache-reset` 部署端點（`X-Deploy-Secret` 驗證），`npm run deploy` 自動觸發
- git-sync `CODE_REVERT_GUARD`（controllers/migrations/tests 路徑淨刪除 ≥30 行時 exit 1）
- 備份失敗 EXIT Trap → Telegram 告警（nightly + sixhour）

---

## 2026-04-20

### Fixed
- ClassSession 時間異動未同步 `schedules` exception（`syncScheduleExceptionTime`，16 筆歷史 drift 修復）
- 排課例外在無 ClassSession 時靜默寫入孤兒記錄（`no_class_session` 422 防護）
- 代課假陽性衝堂：更換代課老師時舊 `scheduled` 列被計入（`exclude_schedule_id`）
- 試聽課型容量守衛誤判（trial 豁免分支，不影響正式課型）
- 學生備註清除仍顯示舊值（`isset` → `array_key_exists` + Supabase mirror 補同步）

### Added
- 代課老師容量標籤三態：有空 ✓ / 尚有容量 ⚠ / 已滿 ✗（後端 `remaining_capacity` 欄位）
- 多科共用方案建立時支援設定排課星期+時間（`createMultiSubject` 正式啟用）
- 方案管理頁：成員格排課狀態 tag + 健康度 badge + 不完整方案統計列
- `PUT /course-packages/{id}` 支援 `total_sessions`，全成員自動同步補排/取消

---

## 2026-04-19 以前

→ 見 [CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)
