# AllTrue gotchas (G-001 ~ G-012)

> Moved verbatim from `CLAUDE.md` on 2026-10-31 so every session does not load ~10 KB of detail up front.
> `CLAUDE.md` keeps a one-line index; references like `CLAUDE.md §G-007` resolve through it to this page.
> Older gotchas live in `.cursorrules` §核心資料表 gotcha and `.cursor/rules/alltrue-system.mdc`.


### G-001：Teacher.id === User.id（同一人，兩張表 ID 相同）
`StudentClass.TeacherID`、`StudentSingIn.TeacherID` 存的都是 `User.id`。查 `Teacher` 或 `User` 用同一個 ID 都能命中。

### G-006：GitHub Actions SSH Secrets 格式嚴格，含 `@` 就爆
`PI_SSH_USER` 只能填 `admin`；`PI_SSH_HOST` 只能填 `pi.lifenet.com.tw`。詳見 `docs/OPERATIONS_RUNBOOK.md` §Pi + AI_REGRESSION_LESSONS §R18。

### G-007：智慧行事曆週檢視必須走 occurrence resolver，不可分散 if 合併
**唯一合法路徑**：`SmartCalendar.vue` → `calendarOccurrenceMerge.js` `mergeWeekCalendarOccurrences()`。
違反會導致課程消失或同一堂掛兩位老師。回歸測試：`npm run test:calendar`。
混班型容量（一對二與一對三同一格）見 [`docs/plans/2026-08-17-mixed-class-type-occupancy.md`](docs/plans/2026-08-17-mixed-class-type-occupancy.md)（#1889／**§R116**）；剩餘依即將加入的班型算。二次調課殘影見 §R114，不可回退。

### G-008：家長入口更新卡只吃 `docs/PARENT_UPDATES.yml`（禁止 CHANGELOG 關鍵字推導）
教職員卡唯一來源 `docs/STAFF_UPDATES.yml`（凍結）+ `docs/staff-updates/*.yml`（§R85）；家長卡 `docs/PARENT_UPDATES.yml`（凍結）+ `docs/parent-updates/*.yml`（§R45）。CHANGELOG 只產草稿、不得自動發布。**PR 只新增檔案、不改共用清單**：CHANGELOG 條目 = `docs/changes/<date>-<slug>.md`、卡片 = `docs/staff-updates/<id>.yml`（見 `docs/GUIDE_STAFF_UPDATES.md`）；`*.generated.js` 不進 git，build/dev/test 自動產生。

### G-009：課程「繳費狀態」是雙真相 OR 邏輯，`StudentClass.Paid` 壓不過帳單付款
`payment_status = Paid=1 或 Invoice 有效付款`（`StudentClassController.php` summary 段）。只要帳單有未作廢的 Payment，課程管理切「未繳費」會被靜默蓋回「已繳費」；要先到帳務作廢誤登款項。另：`update()` 的 preservedDelta 會把 `Charge − Rate×數量` 的差額當手動微調永久保留——若差額來自錯誤舊資料，UI 怎麼改都改不回（GitHub #798/#799，in-app #158/#159）。

### G-010：行事曆來源真相 = 已物化的 `ClassSession`；「某天課很少」未必是 bug
行事曆/點名/評量的來源真相是**已物化的 `ClassSession`**（非 `schedules` 模板），經 `class-sessions` API（`branch_id`+`start`/`end`）→ 前端 `mergeWeekCalendarOccurrences()`。**判定「課表漏顯/數量不對」前，先查 DB**：`ClassSession JOIN StudentClass JOIN Student.CampusID` by `SessionDate`，DB 數量 = API 數量 = 真相。週日/低量日只有少數堂是**正常**（補習班週日課少），主任週檢視是依時段聚合成「堂」（例：2026-06-28 新莊分校 = 3 時段 / 12 筆 student-session，皆正確，非資料遺失）。
**count 模式（預付包堂）的向前產生只涵蓋 active 課程**：`Kernel.php` 每日 03:45 跑 `sessions:generate-forward --execute --scheduled --horizon-weeks=4`（#1062 Track A，參數見 `SchedulerEvidence.php`），只替近期有固定每週節奏的課向前物化 4 週；**dormant（停滯）預付課刻意排除**（#1152），已付堂數仍可能未物化、行事曆向前看不到（**#1062** 系統性問題的剩餘部分）。`schedules:backfill-class-sessions` 只能從既有 `schedules` 物化。每日 03:40 已排 `sessions:audit-stranded` 稽核；根因修復（向前生成 + 永不遺失已付餘額）屬 owner-gated 紅區。產品／架構決策包（**Accepted — Phase 0 evidence authorized**，非 implemented）：[`docs/ADR_006_prepaid_session_horizon_and_commitment.md`](docs/ADR_006_prepaid_session_horizon_and_commitment.md)——Schedule Commitment → materialize → pool coverage；禁止餘額猜堂。

### G-011：「一堂 = 幾分鐘」是**每門課自己的**設定，沒有全公司統一值；契約在第一次扣堂後鎖死
`StudentClass.standard_lesson_minutes`（計費標準堂長）與 `SessionDuration`（排課時長）是**兩件事**。同樣一次 180 分鐘的課，在標準 90 / 120 / 180 分鐘的課程裡分別扣 **2.00 / 1.50 / 1.00** 堂；買 8 堂分別 = 720 / 960 / 1440 分鐘。
**後端沒有任何 fallback 到 120**：`resolvedStandardLessonMinutes()` 未設定時回傳 `null`，不會退回 `SessionDuration` 也不會退回 120；建課表單的 `120` 只是輸入框初值，一定會隨 payload 明確送出。任何「AllTrue 一堂固定 120 分鐘」「8 堂 = 16 小時」「3 小時課永遠扣 1.5 堂」的假設都是 bug。
**兩個旗標缺一不可且皆預設關**：`perfflags.actual_duration_deduction_enabled`（環境）＋ `deduction_basis`（每門課）。旗標關閉時，已標記 `actual_duration` 的課行為**完全等同** `fixed_session`（fail-safe，非 fail-open），因此關旗標＝完整回滾、無資料要清。**試辦名單（#3834）**：`perfflags.actual_duration_campus_ids`（`PERF_ACTUAL_DURATION_CAMPUS_IDS`，預設 `11,13`）限制哪些分校可以**新開**這個選項——建課與把既有課改成 `actual_duration` 在名單外回 422；已經是 `actual_duration` 的課不受名單影響（不改任何扣堂口徑）。
**第一筆扣堂 ledger 之後**，`standard_lesson_minutes`／`deduction_basis`／`SessionCount` 由 `BillingContractLockGuard` 在**後端**鎖定（回 422）；前端變灰只是 UX。一般編輯仍不提供扣堂後任意修正；但主任／超級管理員可使用具名的 `billing-correction` 例外流程，僅處理**未收款、非共用、按堂計費的堂數下修**，不得低於已使用堂數，且會留下稽核紀錄。此例外不修改 `standard_lesson_minutes`／`deduction_basis`，也不改寫已發生的扣堂歷史；已收款或付款回報案件仍須走帳務作廢／更正流程。
**超額永遠不擋點名**：確認只發生在建課階段（D5）。若出現「因額度不足而無法點名」＝ bug，先關旗標。上線／回滾見 [`docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md`](docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md)。

### G-012：雲端／遠端 session（無 SSH、無 DB 連線）撈／回寫 in-app bug 資料——唯讀依既有來源，回寫走受保護 dispatch
Claude Code on the web／其他雲端 session 不承接本機 WSL2 的環境；不得假設有 Pi SSH key 或 DB 連線，實際可用工具與網路依當前環境核對；Pi SSH 禁令不變。舊版本寫「`workflow_dispatch` 對雲端 agent 一律回 403」已不正確：2026-10-15 的原始觀測：在當時那一個 Claude cloud session、以當時的權限實測，`workflow_dispatch`（對 `land-queue.yml`）與 `repository_dispatch` 呼叫成功（未觸發任何寫入）。不代表所有雲端 session 或日後權限都能觸發；也不可把「能 dispatch」當成「可以 dispatch」。
**撈資料（唯讀）**：編輯 `operations/closeout/bug-queue-dump.request.md`（撈開放佇列，無參數）與 `operations/closeout/bug-detail-dump.request.md`（**要改檔內 `bug_id: <n>` 那一行**，workflow 真的會解析這行，不是只看 diff 有沒有變動）— 走 **`chore/`（禁止 `ops/`，`branch-policy.mjs` 只認 `chore|ci|fix`）** branch → PR → CI 綠 → merge 進 `main`（`push: branches:[main]` 才會觸發）→ 抓 run。
**log 只有 ID／數量（公開 repo，#3605）**：完整 JSON（標題／描述／留言）只在以 `DUMP_ARTIFACT_KEY` 加密的 artifact（`*.full.tar.gz.enc`）；缺 secret 時 workflow fail closed。artifact 能否下載依當前連線政策；曾觀測到的 `blob.core.windows.net` 403 不代表所有 session，遇到 egress 阻擋不得繞過。未持有既有合法解密金鑰的 session 不能讀全文；解密 custody 的具名批准及其他 secret 限制見 [`FOUNDER_STANDING_DECISIONS.md`](docs/governance/FOUNDER_STANDING_DECISIONS.md) 2026-10-19；依 [SOP Cloud decryption custody](docs/sop/BUG_INTAKE_TO_PRODUCTION.md#cloud-decryption-custody) 核對環境與 readiness、私下驗收及保留原始金鑰。dispatch 不新增金鑰或資料權限。
**兩者有 15 分鐘新鮮度限制**（`scripts/validate-bug-intake-evidence.py --max-age-minutes 15`）：queue-dump 與 detail-dump 必須在同一輪 15 分鐘內連續觸發並互相對得上，否則視為 stale、禁止拿舊 dump 直接分診。
**回寫（Phase A／公開 follow-up）已有受保護的雲端 dispatch 路徑**：[PR #4196](https://github.com/jerry200176-png/AllTrue_System/pull/4196) 已於 2026-10-16 合併。`bug-phase-a-triage.yml`／`bug-followup-comment.yml` 仍是 `workflow_dispatch`-only、只在 `main` 跑、沒有 push fallback；批次實作 #4386 已合併（main `7c6f3bdf`），目前為 `preview` → production 寫入，輸入為 `rows_json` 批次（1–20 筆，任一筆不合格整批不寫）。**Founder 2026-10-21 起不再逐次核准這兩個 workflow**（範圍見 `FOUNDER_STANDING_DECISIONS.md` 2026-10-21 第 1 條）；雲端 agent 備齊真實案件與證據後可直接 dispatch，但**技術可觸發不等於其他寫入已獲授權**。所需欄位與各階段證據見下方 SOP。
**批次 guard 的生效邊界（#4386 已安裝）**：以下控制已隨 #4386 合併至 main；preview（`.github/scripts/inapp-writeback-rows.py`）驗證每一筆，公開 summary 僅列案件／issue／狀態等 metadata 與每筆回覆長度（≤600 字元）、精確 UTF-8 SHA-256，不印回覆全文；寫入 job 才使用 main-only `production-ssh`。兩個 workflow 共用 `inapp-production-writeback` concurrency（`cancel-in-progress: false`、`queue: max`）。這些是已接受實作的控制，不是本文件自行新增的執行權限；未具真實案件、授權或必要證據時，仍不得執行，也不能把 guard 合併當成回寫成功。`bug-phase-c-allowlist.yml` 的 push trigger 只限「修復已上線」的 Phase C，不可拿來做未驗證的 Phase A。
**即時唯讀（2026-10-13 起；cloud 環境的具名例外見 `FOUNDER_STANDING_DECISIONS.md` 2026-10-14（三）補充第 3 條）**：若環境有 `ALLTRUE_TRIAGE_TOKEN`（超級管理員在「個人資料 → 安全性 → AI 分診唯讀金鑰」建立，預設 90 天到期），可直接 HTTPS 讀 `GET /api/v1/triage/bug-reports`（列表／單筆含留言／附件），不必走 dump。這把金鑰只能讀，其他 API 一律 403；回寫仍走上述 dispatch workflow。**repo 是公開的：絕不可把回報內容（姓名、電話、描述、留言、截圖）貼到 GitHub issue／PR／commit**，只寫 ID 與自己的技術判斷。
完整程序見 [`docs/sop/BUG_INTAKE_TO_PRODUCTION.md`](docs/sop/BUG_INTAKE_TO_PRODUCTION.md)。
