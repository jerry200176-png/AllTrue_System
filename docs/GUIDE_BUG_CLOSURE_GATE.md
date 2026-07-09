# GUIDE — Bug 關閉閘門（Closure Gate）

> **角色**：Reliability / QA / AI Agent 在宣告 bug「完成」前的強制檢查清單  
> **權威流程**：[`CHAT_BUG_SYSTEM.md`](CHAT_BUG_SYSTEM.md) §3.6–§3.7（in-app 狀態機）  
> **衝突時**：P0 規則（`.cursorrules`）> 本指南 > 一般工程習慣

---

## 目的

防止「程式 merge 了但沒驗證」「修了 A 壞了 B」「in-app 沒回覆」類復發。  
**沒有通過 Closure Gate 的 bug，不得標記為 engineering-complete。**

---

## 六項強制要求

| # | 要求 | 證據類型 | 存放位置 |
|---|------|----------|----------|
| 1 | **根因已文件化** | 白話 + 技術備註（可分 internal） | GitHub issue body / comment；in-app internal note 可選 |
| 2 | **回歸測試存在** | CI 綠 + 測試檔路徑 | PR；`backend/tests/` 或 `frontend/src/**/*.test.js` |
| 3 | **Production 驗證完成** | 命令 + 預期 + 實際 + 時間戳 | GitHub comment；incident plan §audit |
| 4 | **使用者面向更新完成** | 公開留言（白話、無欄位名） | in-app `bug_report_comments`（`is_internal_note=0`） |
| 5 | **文件已更新** | CHANGELOG 一行；必要時 AI_REGRESSION | `docs/CHANGELOG.md`、`docs/AI_REGRESSION_LESSONS.md` |
| 6 | **回滾方案可用** | revert PR 或 DB snapshot 路徑 | PR body；`docs/incidents/*-repair-plan.md` §回滾 |

---

## 與 in-app 狀態機整合

```
new → triaged → in_progress → resolved → (reporter-verify) → closed
         ↑                           ↑
    Phase A 分診              Closure Gate 1–5 完成後才可 resolved
                              Gate 6 在資料修復/高風險變更時必填
```

### 狀態轉換規則（勿跳步）

| 目前 | 可轉 | Closure Gate |
|------|------|--------------|
| `triaged` | `in_progress` | Gate 1 完成（根因假設或確認） |
| `in_progress` | `resolved` | **Gate 1–5 全部完成** |
| `resolved` | `closed` | 回報者 `reporter-verify`（Gate 4 延伸） |

⛔ `triaged` **不可**直接 → `resolved`（`BugReportService::VALID_TRANSITIONS` 會拒絕）。

### Phase 對照（`CHAT_BUG_SYSTEM.md`）

| Phase | 動作 | Closure Gate |
|-------|------|--------------|
| **A 分診** | 開 GitHub issue + in-app `triaged` + 公開回覆 | — |
| **B 修復** | branch → 測試 RED → code → CI 綠 → PR merge → deploy | Gate 2 在 merge 前 |
| **C 上線回寫** | `resolved` + 公開留言 + 等驗收 | Gate 3–5 在 `resolved` 前 |
| **D 關閉** | 回報者確認 → `closed` | Gate 4 驗收延伸 |

---

## 各閘門細項

### Gate 1 — 根因

- [ ] 能一句話說明「為什麼會發生」（老師看得懂的版本寫 in-app；技術版本寫 GitHub）
- [ ] 已排除至少 2 個候選根因（`bug-fix-plan.mdc` §B1）
- [ ] 高風險模組已對照 `AI_REGRESSION_LESSONS.md` 模組索引

### Gate 2 — 回歸測試

- [ ] 測試在 **修復前** 曾 RED（或 revert-proof 證明）
- [ ] CI 全綠（Agent 自己等，不叫使用者去看）
- [ ] 測試名稱或 comment 含 in-app # 或 GitHub # 便於追溯

**資料修復類**（無 code diff）：至少要有 **唯讀 audit 查詢** + 預期 after 表格（見 `docs/incidents/*-repair-plan.md`）。

### Gate 3 — Production 驗證

最低限度：

```bash
# 1. 版本
ssh admin@pi.lifenet.com.tw 'cd /home/admin/backend && git rev-parse --short HEAD'

# 2. Health
curl -sk https://daan.lifenet.com.tw/api/v1/health

# 3. 業務抽樣（依 bug 類型）
# 例：tinker 唯讀查詢、特定 API smoke、前端 bundle grep
```

記錄格式：

| 項目 | 命令/路徑 | 預期 | 實際 | 時間 |
|------|-----------|------|------|------|

⛔ 禁止在 Pi 跑 `php artisan test` / `phpunit`。

### Gate 4 — 使用者面向更新

- [ ] 公開留言通過 [`user-facing-communication.mdc`](../.cursor/rules/user-facing-communication.mdc) 禁用詞檢查
- [ ] 說明「已做什麼」+「請您如何驗收」+「確認已修好 / 問題仍存在」
- [ ] **禁止**只關 GitHub issue 而不回 in-app（§R51）

### Gate 5 — 文件

| 變更類型 | 必更新 |
|----------|--------|
| 使用者可感知修復 | `docs/CHANGELOG.md`（第一條白話） |
| AI 可重犯模式 | `docs/AI_REGRESSION_LESSONS.md`（§Rnnn） |
| 資料修復草案 | `docs/incidents/*.md` |
| 技術債本次不修 | 詢問是否登 `docs/TECH_DEBT.md` |

有前端使用者公告時：`npm run sync-release-notes`。

### Gate 6 — 回滾方案

| 變更類型 | 回滾方式 |
|----------|----------|
| Code deploy | `git revert <merge-commit>` + 等 deploy.yml |
| Migration | `migrate:rollback`（僅在有 down() 且已評估風險時） |
| 資料修復 | 修復前 `mysqldump` + snapshot JSON + `--rollback` 指令 |

高風險（帳務/堂數/auth）：**Gate 6 必填**才能執行 production 寫入。

---

## 快速檢查表（AI / QA 用）

複製到 PR 或 incident comment：

```
## Bug Closure Gate — in-app #___ / GitHub #___

- [ ] 1. Root cause documented
- [ ] 2. Regression test in CI
- [ ] 3. Production verification (HEAD + health + business sample)
- [ ] 4. In-app public comment posted
- [ ] 5. CHANGELOG / AI_REGRESSION updated
- [ ] 6. Rollback plan documented

Production HEAD: ______  Verified at: ______
Reporter verify: pending / passed
```

---

## 已完成修復的處理原則（2026-07-08 起）

| Bug | 狀態 | 備註 |
|-----|------|------|
| #190 / #194 / #196 | Code complete + resolved | 等回報者 verify；**勿重開**除非新證據 |
| #190 歷史帳務 | 見 `incidents/190-historical-billing-repair-plan.md` | 資料修復，非 code reopen |
| #189 / #191 | Draft repair plan + audit | 待 CEO 核准 |

---

## 相關文件

- [`CHAT_BUG_SYSTEM.md`](CHAT_BUG_SYSTEM.md) — in-app 狀態機與 Phase A/B/C
- [`GUIDE_SUPPORT_REPLY_MACROS.md`](GUIDE_SUPPORT_REPLY_MACROS.md) — 公開回覆範本
- [`.cursor/skills/alltrue-release/SKILL.md`](../.cursor/skills/alltrue-release/SKILL.md) — deploy 驗證 SOP
- [`.cursor/skills/alltrue-testing/SKILL.md`](../.cursor/skills/alltrue-testing/SKILL.md) — 測試閘門

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | 初版：六項 Closure Gate + in-app 狀態機整合 |
