# AllTrue Agent Engineering System v1

> **狀態**：設計提案（2026-07-08）  
> **上游參考**：[addyosmani/agent-skills](https://github.com/addyosmani/agent-skills) — 見 [`GUIDE_AGENT_SKILLS.md`](GUIDE_AGENT_SKILLS.md)  
> **權威 SOP**：`CLAUDE.md`、`AGENTS.md`、`docs/INDEX.md` — **衝突時永遠以 AllTrue 為準**

---

## 目標

把通用 agent 工程技能**本地化**為 AllTrue 可重複執行的能力，讓每個 Cursor session：

1. 先讀對文件（INDEX → 模組 §）
2. 依風險分級走對流程（T0–T3）
3. 先測試再改 production 程式
4. 有證據才宣告完成（CI + deploy + smoke）
5. 寫回長期記憶（CHANGELOG / AI_REGRESSION）

**不做**：整包安裝 24 個上游 skills；不建立與 `CLAUDE.md`／`docs/sop/` 平行的第二套 SOP。

---

## 架構

```
使用者指令
    ↓
AGENTS.md（First-read + Risk Tier）
    ↓
.claude/skills/alltrue-*（任務技能 — 本系統）
    ↓
.claude/skills 內其餘技能 / CLAUDE.md 黃線（模組規格）
    ↓
docs/（權威業務規則）
```

### 技能目錄

| 技能 | 檔案 | 優先級 |
|------|------|--------|
| **In-app 產品閉環入口** | [`.agents/skills/alltrue-inapp-product-loop/SKILL.md`](../.agents/skills/alltrue-inapp-product-loop/SKILL.md)（`.claude/skills/alltrue-inapp-product-loop` symlink） | **P0 —「處理意見與建議」** |
| 除錯分診 | [`.claude/skills/alltrue-debugging/SKILL.md`](../.claude/skills/alltrue-debugging/SKILL.md) | P0 |
| 回歸測試 | [`.claude/skills/alltrue-testing/SKILL.md`](../.claude/skills/alltrue-testing/SKILL.md) | P0 |
| 上線發布 | [`.claude/skills/alltrue-release/SKILL.md`](../.claude/skills/alltrue-release/SKILL.md) | P0 |
| 安全審查 | [`.claude/skills/alltrue-security/SKILL.md`](../.claude/skills/alltrue-security/SKILL.md) | T3 |
| Code Review | [`.claude/skills/alltrue-code-review/SKILL.md`](../.claude/skills/alltrue-code-review/SKILL.md) | 每 PR |
| UI 品味（外部） | [`.claude/skills/design-taste-frontend/SKILL.md`](../.claude/skills/design-taste-frontend/SKILL.md) | 登入／landing 向；見 `GUIDE_AGENT_SKILLS.md` §taste-skill |
| UI 既有站打磨（外部） | [`.claude/skills/redesign-existing-projects/SKILL.md`](../.claude/skills/redesign-existing-projects/SKILL.md) | 「網站變好看」優先；須服從 `RULE_DESIGN_SYSTEM.md` |

### 與既有 Cursor skills 的關係

| 內建 skill | 分工 |
|-----------|------|
| `review-bugbot` | 自動 PR review — 與 `alltrue-code-review` 互補 |
| `review-security` | STRIDE 深審 — T3 時與 `alltrue-security` 並用 |
| `babysit` | CI 輪詢 — `alltrue-release` 引用 |
| `create-skill` | 擴充本系統時使用 |

---

## 啟動對照表

| 使用者意圖 | 啟動技能 | 必讀文件 |
|-----------|---------|---------|
| **處理 in-app 意見與建議** | `alltrue-inapp-product-loop` | `INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1` + `CHAT_BUG_SYSTEM` §3.6–§3.7 |
| Bug 回報 / 行為異常 | `alltrue-debugging`（或先走 product-loop） | `AI_REGRESSION_LESSONS` 模組索引 |
| 要改 `backend/` 既有檔 | `alltrue-testing` → DEV | `CLAUDE.md` Y1／Y2、P0 R1 |
| PR merge / 上線 | `alltrue-release` | 公開 `version.json`／`deployment.json`；禁 Pi SSH |
| auth / PII / RFID | `alltrue-security` | `alltrue-security` skill（STRIDE） |
| PR 準備 merge | `alltrue-code-review` | FR 對照 + 多校區隔離 |
| 網站變好看／UI 打磨 | `redesign-existing-projects`（± `design-taste-frontend`） | `RULE_DESIGN_SYSTEM.md`；一次一頁 |

---

## 反合理化（全技能共用）

| 藉口 | 反駁 |
|------|------|
| 「先改一下再補測試」 | P0 R1：CI 未綠禁止改既有 production 檔 |
| 「deploy 應該成功了」 | 必須公開 `version.json`／`deployment.json` + health（禁 Pi SSH） |
| 「in-app 先不留言」 | §R53：上線後必回公開留言 |
| 「production 跑一下 phpunit」 | 事故 C：RefreshDatabase 會清空 DB |
| 「直接 push main 比較快」 | 事故 A：force push 曾全站 15 分鐘 |

---

## 落地路線圖

| 階段 | 內容 | 狀態 |
|------|------|------|
| v1.0 | 5 個 SKILL.md + 本設計文件 | **本 PR** |
| v1.1 | `bug-fix` 流程明確引用 `alltrue-debugging` | 待辦 |
| v1.2 | CI nightly 對照 `alltrue-testing` 清單 | 待辦 |
| v2.0 | MemPalace 索引 alltrue-skills | 待辦 |

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | v1 初版：5 技能 + 架構 |
