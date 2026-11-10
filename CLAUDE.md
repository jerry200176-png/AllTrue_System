# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 任何 AI 讀取此專案時，先遵守 **[`docs/governance/COMPANY_CONSTITUTION.md`](docs/governance/COMPANY_CONSTITUTION.md)** 與 **[`docs/governance/PRECEDENCE.md`](docs/governance/PRECEDENCE.md)**。  
> **操作者：** 艦隊 [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md)。T0/T1 可在 required checks、review 與證據通過後由 Agent squash-merge；T2 另需 independent review 與 rollback boundary；T3 / protected 工作在任何 production activation、data repair、migration/schema cutover、billing／entitlement、identity/authz、破壞性動作、backup restore、security-sensitive credential 或重大產品方向前停下等 Founder GO；但 Founder 指示的工作（含 R3 程式）端到端進行、R3 range 通過自動控制即上線，閘門是擋外來輸入（見 `FOUNDER_STANDING_DECISIONS.md` 2026-10-21（二）之二）；哪些按鈕可以不按，只以同檔 2026-10-28（二）之二為準（此處不摘要）。Pi SSH 的歷史唯讀例外見 R6；不得用它覆蓋目前操作者適用的 machine ban（含間接觸發；見 DELIVERY_LIFECYCLE 自動化對照表）。
>
> **Founder 常設決定：** 唯一定義見 [`docs/governance/FOUNDER_STANDING_DECISIONS.md`](docs/governance/FOUNDER_STANDING_DECISIONS.md)（不在此摘要）。
> 本檔是 **Claude Code adapter**，**不是**凌駕 Constitution / Control Plane 的最高法。  
> **🗺️ 任何任務開始前：照 [`AGENTS.md`](AGENTS.md) 的必讀清單（唯一一份；含 `docs/INDEX.md` 的 Start here 與任務對應節）。禁止未讀就直接動手。**
> **現行工程主線：** [`docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md`](docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md) — 不要重寫整個前端／後端；排課資料模型根治見 [`RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)。  
> 通用入口：[`AGENTS.md`](AGENTS.md)。P0 紅黃線全文在下方（不要跳過）。

---

## 🧠 MemPalace — AI 記憶系統

**更新索引（唯一入口）：**
```bash
bash scripts/mempalace-ingest.sh
```

**搜尋（調查 bug 或回顧決策前）：**
```bash
~/.local/bin/mempalace search "關鍵字" --wing alltrue-sessions
~/.local/bin/mempalace search "關鍵字" --wing alltrue-docs
```

Palace：`~/.mempalace/palace`（local-first，cloud session 沒有，跳過即可）。權威文件仍在 git markdown；MemPalace 僅供召回。

---

## In-app Bug 回報（Claude 必讀）

處理「系統上 bug 回報」時，唯一長文 SOP：[`docs/CHAT_BUG_SYSTEM.md`](docs/CHAT_BUG_SYSTEM.md) **§3.6–§3.7**。從收件到結案的九個階段、車道與關卡（所有需求共用）：[`docs/sop/DELIVERY_LIFECYCLE.md`](docs/sop/DELIVERY_LIFECYCLE.md)。

| 階段 | 要做什麼 |
|------|----------|
| 分診 | §3.6 撈附件 → 開 GitHub issue → in-app `triaged` + **公開回覆** |
| 修復 | `docs/AI_REGRESSION_LESSONS.md` §復發家族（先查前例）→ branch → CI → PR merge |
| 上線後 | in-app `resolved` + 公開回覆 → `reporter-verify` → `closed` |

**禁止**只關 GitHub issue 而不回 App 留言（§R51、§R53）。

---

## ⛔ 5 條紅線（違反 = P0 故障，零容忍）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| R1 | 要修改 `/home/admin/` 內**既有** `.php` / `.vue` / config 檔 | ❌ 停。先寫測試 → CI 綠 → 才改。新增 migration / test / Export class 例外 |
| R2 | 要在 Pi 執行任何含 `test` / `phpunit` / `config:clear` 的指令 | ❌ 停。測試只走 GitHub Actions |
| R3 | 要執行 `git push --force` / `-f` / 直接 push main | ❌ 停。一律推 feature branch，等 PR merge |
| R4 | 要還原出錯的檔案 | ✅ `git checkout HEAD -- <file>` **完整**還原，禁止部分還原 |
| R5 | 要執行 `php artisan migrate` | ❌ 不可由 Agent 在 Pi／正式資料庫直接執行；一律由 `deploy.yml` 執行。**只新增**表／欄位／索引、且 PR 內文宣告 `Additive-Migration: yes` 的新 migration 合併時算 T2（`additive_migration_paths()`；身分／權限表除外（含 credential／token／security／PII），帳務表依 2026-10-28（二）之二在 #4560 合併後移出排除；須 Codex 在最終 head 確認；Founder 2026-10-14（三）④＋補充 #1），上線也不必按（`ADDITIVE_MIGRATION_AUTO_ACTIVATION`；#4560 上線後監看 20 分鐘、失敗自動回滾 code；回滾還原不了 schema，所以只限只新增）；會 drop／rename／change／寫資料的 migration 一律須 Founder 對確切範圍批准 |
| R6 | 要 SSH 到 Pi 直接編輯任何程式碼 | ❌ 停。所有改動依 [WORKTREE_POLICY](docs/governance/WORKTREE_POLICY.md)：雲端 fresh clone 從 `origin/main` 開 feature branch（必要的本機 WSL 任務才用隔離 task worktree）→ PR → CI → 依現行授權由 `deploy.yml` 控制部署。**歷史唯讀例外（Founder 2026-10-07；不適用於目前受 Pi SSH machine ban 限制的操作者，操作前先核對 `AGENTS.md`、Constitution 與 [SOP 操作者邊界](docs/sop/DELIVERY_LIFECYCLE.md#in-app-自動化對照表)）**：只為查 bug 可 SSH/SCP 讀附件、`tinker --execute` 跑 SELECT；禁止任何寫入、`artisan` 寫入指令、R2 指令；每次在對應 issue 留言記錄讀了什麼（只寫 ID，不寫姓名）；讀回的檔案看完即刪 |

## ⚠️ 黃線（違反 = CI 反覆失敗）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| Y1 | 要在測試插入任何 DB 資料 | 先查 NOT NULL 欄位。`Campus` 用 Factory。`schedules` 記 **S.D.B.**（student_id, day_of_week, branch_id）|
| Y2 | 要在測試用「今日日期」作為 future session | `start_time` 設 `23:00`，避免 `isEndedAtCreateTime=true` |
| Y3 | 前端有改動要上線 | CI 全綠後依合併與正式啟用授權推進；核對 `deploy.yml` 的實際部署結果，再驗 health / `version.json` |
| Y4 | PHPStan 報 `undefined property Model::$X` | 到 Model 補 `@property` docblock（欄位來自 migration），**不要**加 `backend/phpstan-baseline.neon`；baseline 只能縮不能長（Presubmit CHECK 0c），罕見例外貼 PR label `phpstan-baseline-growth` 後重跑 |
| Y5 | 遷移裡 `chunk()` 的 callback 會改自己 WHERE 用的欄位 | 改 `chunkById()`（OFFSET 漂移會漏列）；測試塞超過 chunk size 的資料，驗證 0 筆漏改 |
| Y6 | 新增帶 DEFAULT 的欄位 | MySQL 會把舊列全回填成 DEFAULT；同 PR 附 backfill migration（`chunkById`） |
| Y7 | 查自訂 `VoidedAt` 欄位 | 不用 `withTrashed()`，用 `DB::table()` |
| Y8 | 改前端 | 不手動 `npm run deploy`；不改 `frontend/dist_build/`（build 產物）；`.vue` 不 hard-code token（用 `supabase.auth.getSession()`） |
| Y9 | 改 Login／auth／品牌首屏 | 沒有 Founder 視覺 GO 不整體改版；token 化 ≠ 扁平化；PR 附前後截圖（桌面+手機）並寫明保留的 mesh／glass／motion |
| Y10 | 新增後端 query | 必帶 `CampusID`／`branch_id` 隔離；teacher 不可讀他校；`super_admin` 例外須明寫 |
| Y11 | 改 `AlertController::tuition`、`ApprovalSessionSyncService`、`SessionDeductionService` | 先讀 `docs/AI_REGRESSION_LESSONS.md` 對應 §；`docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 條件勿擅改 |
| Y12 | 寫給老師／主任／家長的公開留言 | 白話；不漏欄位名、SQL、class 名、PR／內部編號（`docs/CHAT_BUG_SYSTEM.md` §3.8） |
| Y13 | 同一階段的 CI／測試連修 5 次仍紅 | 停，回報已試清單＋卡點＋需要什麼，不無限燒 Actions minutes |
| Y14 | Review 發現 Minor 且本次 out of scope | 登記 `docs/TECH_DEBT.md`；Critical／Major 當下修，不登記 |

---

## ⛔⛔⛔ 生產事故紀錄（全部真實發生）

| 事故 | 日期 | 操作 | 後果 |
|---|---|---|---|
| **A** | 2026-04-21 | `git push --force origin main` | 生產 `.env`/routes 被覆蓋，全站 15 分鐘 |
| **B** | 2026-04-22 | Pi 執行 `php artisan config:clear` | session/auth 錯亂，全站 5 分鐘 401 |
| **C ⛔最高** | 2026-04-22 | Pi 跑 `php artisan test` | `RefreshDatabase` 清空 production DB，1h42m 資料損失 |
| **D** | 2026-04-23 | 未經 CI 改 `public/.htaccess` + 部分還原 | 全站變英文，再次破壞 |
| **E** | 2026-04-23 | production 跑 `vendor/bin/phpunit` | 污染 cache owner，全站 API 500，20 分鐘 |
| **F** | 2026-04-23 | 無測試直接改 production `SwipeRfidController.php` | 流程違規 |

---

## 開發環境（2026-04-24 起）

| 環境 | 說明 |
|---|---|
| **開發（2026-10-14 起）** | Claude cloud session：從 `origin/main` 開新 branch → PR → CI。不跑 `agent-start`／本機 preflight。見 [`docs/governance/WORKTREE_POLICY.md`](docs/governance/WORKTREE_POLICY.md) |
| **本機 WSL** | 只在必要時用隔離的 task worktree（`agent-start` 已退役）；**never** `/home/jerry/alltrue` / `~/alltrue`。見 WORKTREE_POLICY + `AI_REGRESSION_LESSONS` §Y6 |
| **多 agent 並行** | 每個 session 自己的 branch，不共編同一分支 |
| **生產伺服器** | Raspberry Pi `/home/admin` — ⛔ 禁止直接 SSH 進去改程式碼；歷史唯讀例外與目前操作者限制見 R6 |
| **部署方式** | `deploy.yml` 是正式應用部署控制面；CI 通過不等於已有正式啟用授權，依實際 environment gate 與完整 production→candidate 風險執行 |

---

## 核心資料表 Gotchas（bug 偵查前必讀）

全文在 [`docs/agents/GOTCHAS.md`](docs/agents/GOTCHAS.md)；碰到對應模組先讀那一節。

| ID | 一句話 |
|---|---|
| [G-001](docs/agents/GOTCHAS.md) | Teacher.id === User.id（同一人，兩張表 ID 相同） |
| [G-006](docs/agents/GOTCHAS.md) | GitHub Actions SSH Secrets 格式嚴格，含 `@` 就爆 |
| [G-007](docs/agents/GOTCHAS.md) | 智慧行事曆週檢視必須走 occurrence resolver，不可分散 if 合併 |
| [G-008](docs/agents/GOTCHAS.md) | 家長入口更新卡只吃 `docs/PARENT_UPDATES.yml`（禁止 CHANGELOG 關鍵字推導） |
| [G-009](docs/agents/GOTCHAS.md) | 課程「繳費狀態」是雙真相 OR 邏輯，`StudentClass.Paid` 壓不過帳單付款 |
| [G-010](docs/agents/GOTCHAS.md) | 行事曆來源真相 = 已物化的 `ClassSession`；「某天課很少」未必是 bug |
| [G-011](docs/agents/GOTCHAS.md) | 「一堂 = 幾分鐘」是**每門課自己的**設定，沒有全公司統一值；契約在第一次扣堂後鎖死 |
| [G-012](docs/agents/GOTCHAS.md) | 雲端／遠端 session（無 SSH、無 DB 連線）撈／回寫 in-app bug 資料——唯讀依既有來源，回寫先核對操作者禁令 |

完整 Gotchas G-001 ~ G-012：見 `docs/agents/GOTCHAS.md`。

---

## Land queue / Parallel agents

只在 [`docs/sop/MERGE_SOP.md`](docs/sop/MERGE_SOP.md#land-queue-and-auto-delivery-mechanics)（含 Parallel agents）。

## 任務完成後的記錄原則

記錄位置依 `AGENTS.md` §公司治理記錄原則；非直覺的 DB／流程 Gotcha
仍記在本檔 §核心資料表 Gotchas（`G-NNN`），不要在 adapter 複製發布 SOP。

---

## On-demand 快查（按需讀，不用全讀）

| 需要什麼 | 去哪讀 |
|---|---|
| **換手前先讀的交接狀態（SessionStart 會自動印出）** | `docs/RUNBOOK_HANDOFF.md`；SOP 對標大廠 roadmap 見 `docs/SOP_MATURITY.md` |
| 交付流程（需求／回報 → 上線 → 結案；車道、守門人、SLA） | `docs/sop/DELIVERY_LIFECYCLE.md` |
| 前端 deploy SOP | `docs/RUNBOOK_MERGE_TO_ACTIVATION.md` |
| 各模組已知坑 | `docs/AI_REGRESSION_LESSONS.md` |
| 繳費/續課提醒規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 已回報 vs 確認入帳（#1827） | `docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md` |
| 各角色測試帳號 | `.cursor/.local/test-credentials.md` |

## Agent skills

### Issue tracker

Issues live in GitHub Issues (`gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Label names are defined only in `.github/labels.yml` (`status:triage`, `status:ready`, `status:blocked`, `needs-founder`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `GLOSSARY.md` (created lazily) + ADRs in `docs/ADR_*.md` and `docs/adr/`. See `docs/agents/domain.md`.

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
