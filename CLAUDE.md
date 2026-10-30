# AllTrue — CLAUDE.md（Claude Code 自動載入）

> 任何 AI 讀取此專案時，先遵守 **[`docs/governance/COMPANY_CONSTITUTION.md`](docs/governance/COMPANY_CONSTITUTION.md)** 與 **[`docs/governance/PRECEDENCE.md`](docs/governance/PRECEDENCE.md)**。  
> **操作者：** 艦隊 [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md)。T0/T1 可在 required checks、review 與證據通過後由 Agent squash-merge；T2 另需 independent review 與 rollback boundary；T3 / protected 工作在任何 production activation、data repair、migration/schema cutover、billing／entitlement、identity/authz、破壞性動作、backup restore、security-sensitive credential 或重大產品方向前停下等 Founder GO；但 Founder 指示的工作（含 R3 程式）端到端進行、R3 range 通過自動控制即上線，閘門是擋外來輸入（見 `FOUNDER_STANDING_DECISIONS.md` 2026-10-21（二）之二）；哪些按鈕可以不按，只以同檔 2026-10-28（二）之二為準（此處不摘要）。Pi SSH 只准唯讀（見 R6）。
>
> **Founder 常設決定：** 唯一定義見 [`docs/governance/FOUNDER_STANDING_DECISIONS.md`](docs/governance/FOUNDER_STANDING_DECISIONS.md)（不在此摘要）。
> 本檔是 **Claude Code adapter**，**不是**凌駕 Constitution / Control Plane 的最高法。  
> **🗺️ 任何任務開始前：先讀 `docs/INDEX.md` 的 Start here 與任務對應節（導航地圖）。禁止未讀 INDEX 就直接動手。**
> **現行工程主線：** [`docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md`](docs/architecture/ALLTRUE_ENGINEERING_NORTH_STAR.md) — 不要重寫整個前端／後端；排課資料模型根治見 [`RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](docs/architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)。  
> 通用入口：[`AGENTS.md`](AGENTS.md)。完整工作流程 / 角色規格 / P0 詳細全文：請讀 **`.cursorrules`**（不要跳過）。

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

## In-app Bug 回報（Claude／Cursor 必讀）

處理「系統上 bug 回報」時，唯一長文 SOP：[`docs/CHAT_BUG_SYSTEM.md`](docs/CHAT_BUG_SYSTEM.md) **§3.6–§3.7**。

| 階段 | 要做什麼 |
|------|----------|
| 分診 | §3.6 撈附件 → 開 GitHub issue → in-app `triaged` + **公開回覆** |
| 修復 | `bug-fix-plan.mdc` → branch → CI → PR merge |
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
| R6 | 要 SSH 到 Pi 直接編輯任何程式碼 | ❌ 停。所有改動依 [WORKTREE_POLICY](docs/governance/WORKTREE_POLICY.md)：雲端 fresh clone 從 `origin/main` 開 feature branch（必要的本機 WSL 任務才用隔離 task worktree）→ PR → CI → 依現行授權由 `deploy.yml` 控制部署。**唯讀例外（Founder 2026-10-07）**：只為查 bug 可 SSH/SCP 讀附件、`tinker --execute` 跑 SELECT；禁止任何寫入、`artisan` 寫入指令、R2 指令；每次在對應 issue 留言記錄讀了什麼（只寫 ID，不寫姓名）；讀回的檔案看完即刪 |

## ⚠️ 4 條黃線（違反 = CI 反覆失敗）

| # | 觸發情境 | 強制行動 |
|---|---------|---------|
| Y1 | 要在測試插入任何 DB 資料 | 先查 NOT NULL 欄位。`Campus` 用 Factory。`schedules` 記 **S.D.B.**（student_id, day_of_week, branch_id）|
| Y2 | 要在測試用「今日日期」作為 future session | `start_time` 設 `23:00`，避免 `isEndedAtCreateTime=true` |
| Y3 | 前端有改動要上線 | CI 全綠後依合併與正式啟用授權推進；核對 `deploy.yml` 的實際部署結果，再驗 health / `version.json` |
| Y4 | PHPStan 報 `undefined property Model::$X` | 到 Model 補 `@property` docblock（欄位來自 migration），**不要**加 `backend/phpstan-baseline.neon`；baseline 只能縮不能長（Presubmit CHECK 0c），罕見例外貼 PR label `phpstan-baseline-growth` 後重跑 |

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
| **本機 WSL（淘汰中）** | 只在必要時用；要用就 `agent-start alltrue <task-id>`，**never** `/home/jerry/alltrue` / `~/alltrue`。見 WORKTREE_POLICY + `AI_REGRESSION_LESSONS` §Y6 |
| **多 agent 並行** | 每個 session 自己的 branch，不共編同一分支 |
| **生產伺服器** | Raspberry Pi `/home/admin` — ⛔ 禁止直接 SSH 進去改程式碼；唯讀查資料見 R6 例外 |
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
| [G-012](docs/agents/GOTCHAS.md) | 雲端／遠端 session（無 SSH、無 DB 連線）撈／回寫 in-app bug 資料——唯讀依既有來源，回寫走受保護 dispatch |

完整 Gotchas G-001 ~ G-012：見 `docs/agents/GOTCHAS.md`；更早的見 `.cursorrules` §核心資料表 gotcha 或 `alltrue-system.mdc`。

---

## Land queue (merge, don't race update-branch)

Add the label `queue` to a green-ready PR instead of looping on `gh pr update-branch` or `gh pr merge --auto`.
`.github/workflows/land-queue.yml` takes the oldest labeled PR: it updates the branch when BEHIND, squash-merges when CLEAN with every required check green (no `--admin`), and removes the label with one comment on a conflict, failed check, or unresolved review thread (an unknown thread count never merges). Re-add `queue` after fixing.
**Batching (only while the main ruleset's strict up-to-date policy is off):** the queue cuts `chore/land-queue-batch-<run>` from the main tip, merges up to 4 oldest eligible heads into it (a conflicting PR is left out with a comment), dispatches the required-check workflows on it, and squash-merges the members in order only when every required context is green and main has not moved. Red or timed out: members get a `batch-failed` comment and land one at a time. One serial merge per run; a head behind main is always updated first (`behindBy`), so strict off never merges an untested combination. Fork PRs never queue.
GitHub's native merge queue is not used: it is unavailable for this user-owned repo (a `merge_queue` ruleset probe returned 422).
**Final-head review:** green checks and zero threads still wait (`review-pending`, label retained) for the exact-head evidence in the [canonical merge policy](docs/governance/RISK_BASED_MERGE_POLICY.md#final-head-review-evidence). Serial and batch squashes re-read that evidence; a running/incomplete current-head Codex review cannot be overridden by owner fallback. Native auto-merge stays disabled until the Founder pins `review/final-head` after the default-branch gate has published checks. Existing approval continuity and T3 boundaries remain.

## 任務完成後的記錄原則

記錄位置依 `AGENTS.md` §公司治理記錄原則；非直覺的 DB／流程 Gotcha
仍記在本檔 §核心資料表 Gotchas（`G-NNN`），不要在 adapter 複製發布 SOP。

---

## Parallel agents

- Before starting: run `node scripts/pr-overlap.mjs` (or read the PR's `<!-- pr-overlap -->` sticky comment). If another open PR touches the same files, coordinate with that session or wait. Prefer small PRs (under ~400 lines) that merge fast.
- Landing: add label `queue`. Don't loop `update-branch`, `--auto` or custom merge scripts.
- No stacked PRs: branch from main after the dependency merges.
- Before merging an agent PR, read every `-` line of `git diff origin/main...HEAD`; nothing outside the PR's scope may be removed (2026-10-06 PR-C2 #3631 stale-copy revert).
- Release notes: change fragments only (`docs/changes/…`). Never edit `CHANGELOG.md`, the generated JS or the exemption lists; `phpstan-baseline.neon` may only shrink.
- Stale-copy guard: presubmit `[CHECK 0d]` fails a PR that deletes lines another PR merged to main in the last 7 days (`scripts/check-recent-work-revert.py`). Rebuild from current main; if intended, add label `intentional-revert` + reason in the PR body, then re-run presubmit.
- Deploys: only `deploy.yml`. A range of R0-R2 PRs, or an R3 range that passes the R3 auto controls (final-head Codex review, no open threads, no freeze; no migration, data-repair, identity/credential or control-plane effect), deploys on its own CI with no Founder click; anything else waits for the release train (`FOUNDER_STANDING_DECISIONS.md` 2026-10-21（二）之二). Gates exist to stop untrusted input (in-app report text, non-Founder GitHub content, bot output), not Founder-directed work.
- Helpers (subagents) start from fresh `origin/main` in their own branch/session; never copy files from another worktree or session.
- Review: when Codex is rate-limited, run `/code-review` (control-plane changes: an independent adversarial reviewer) before merging.
- Presubmit size: 700 logic lines (tests, docs, generated and baseline excluded) and 1500 lines in all; split, don't stack.
- A job cancelled after ~15 min with 0 steps is runner starvation — just rerun it.
- Codex review: fix P1s and regressions; P2s get one fix round, later P2s become follow-up issues (AGENTS.md §PR review; `docs/governance/FOUNDER_STANDING_DECISIONS.md` ④). Reply to and resolve every thread.
- New classes need a mapping in `scripts/arch-contexts.json` (FIT-10).
- Anything that needs Jerry: collect it into one list and send it to the coordinating session, not one message per item.

## On-demand 快查（按需讀，不用全讀）

| 需要什麼 | 去哪讀 |
|---|---|
| **換手前先讀的交接狀態（SessionStart 會自動印出）** | `docs/RUNBOOK_HANDOFF.md`；SOP 對標大廠 roadmap 見 `docs/SOP_MATURITY.md` |
| 完整工作流程 + 角色規格 + SOP | `.cursorrules` |
| P0 紅線速查 + OPS checklist | `.cursor/rules/p0-gate.mdc` |
| API 路由 / DB schema / Gotchas | `.cursorrules` 或 `alltrue-system.mdc` |
| 測試規則（NOT NULL / Factory / 時間敏感）| `.cursor/rules/module-test.mdc` |
| Migration 規則（chunkById）| `.cursor/rules/module-migration.mdc` |
| 前端 deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| 各模組已知坑 | `docs/AI_REGRESSION_LESSONS.md` |
| 繳費/續課提醒規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| 已回報 vs 確認入帳（#1827） | `docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md` |
| 各角色測試帳號 | `.cursor/.local/test-credentials.md` |

## Agent skills

### Issue tracker

Issues live in GitHub Issues (`gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Default five: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `GLOSSARY.md` (created lazily) + ADRs in `docs/ADR_*.md` and `docs/adr/`. See `docs/agents/domain.md`.

## Portfolio governance overlay

Read `governance/PORTFOLIO_AGENT_CONTRACT.md` before writing. It is committed
for cloud/mobile agents and contains the company's PR, approval, production,
and Founder-approval boundaries.
