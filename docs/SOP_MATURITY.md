# SOP 成熟度與大廠對標（AI 接手地圖）

> 目的：讓任何 AI（Claude Code / Cursor）能快速了解我們的工程 SOP 對標大廠到哪、還缺什麼，並在 usage 上限或換手時無縫接續。
> 本文件被 `CLAUDE.md` 的「On-demand 快查」引用，請在交接前先讀此檔頂部的「進行中狀態」。

---

## 🔴 進行中狀態（交接區 — 完成後清空此節）

更新時間：2026-06-14（軍階系統 + 三批 in-app bug + 改善/SOP backlog）

**已合併到 main（待部署或部署中）**
- #852 任務中心去誤導 + SystemTrust 401 修復（in-app #163/#164）
- #853 軍階系統重校：徽章更正 + 長期曲線 + roster API + 老師管理顯示 + **重置 migration**（in-app #165）
- #856 bug 詳情手機自動捲入視野（in-app #166）

**尚未合併**
- **PR #854**（老師端「同事軍階」互看 strip）— CI 重跑中（update-branch 後），背景輪詢綠了會自動 admin-merge。

**⚠️ 分支保護目前為「縮減狀態」— 必須還原！**
今天 GitHub Actions 帳單/額度耗盡，GitHub-hosted 的 `gitleaks scan` 與 `Golden scenarios report` 無法跑，為了強制合併（經 Daisy 授權）暫時把這 2 個移出 main 的必要檢查（剩 5 項）。
**#854 合併後，立刻把必要檢查還原回 7 項：**
```bash
echo '{"strict":true,"contexts":["Presubmit Checks","PHPUnit Feature & Unit Tests","Vite Frontend Build","gitleaks scan","Golden scenarios report","Docs Integrity Check","PHPStan Advisory (php)"]}' \
  | gh api -X PATCH "repos/jerry200176-png/AllTrue_System/branches/main/protection/required_status_checks" --input -
```
（帳單修好後，self-hosted 仍正常；GitHub-hosted 的 gitleaks/Golden 會自動恢復。長期解：#867 把它們搬到 self-hosted。）

**部署後待辦**
- 把 in-app bug #163/#164/#165/#166 由 `triaged` → `resolved`（附公開回覆）→ 觸發回報者驗證。狀態機是序列的（見記憶 alltrue-bug-state-machine）。
- 軍階 reset migration 隨部署執行（R5：merge 後才 migrate）。

**今日新建的 backlog（供接手者參考）**
- UI 去 AI 化 v3：epic **#866** + 子 #857–865；CI 搬 job **#867**
- SOP 對標大廠 **milestone M4（#7）**：#868–873（staging / feature flags / CI 高可用 / merge queue / DORA / postmortem）

---

## 我們的 SOP 已對標到哪（M1–M3，皆已規劃/完成）

我們已有成熟度框架（標籤 R=Reliability、T=Testing、V=reView）：

| Milestone | 已涵蓋（對標大廠） |
|---|---|
| **M1 交付安全網** | AI/Bot review gate（#736）、Rollback 演練 + MTTR 量測（#733）、Playwright E2E merge gate（#730）、Vitest 元件測試（#729）、CI raw-hex guard（#689） |
| **M2 品質縱深** | PR Design Review Gate + checklist（#737）、Migration 向後相容 + rollback 安全（#734）、覆蓋率 advisory→blocking（#731） |
| **M3 工程韌性** | 第二 maintainer approval（#738）、漸進發布 + SLO 驅動自動 rollback（#735）、測試金字塔 + 視覺回歸 + flaky 治理（#732）、稽核日誌（#766） |

**既有自動化**（`.github/workflows/deploy.yml`）：health-check（`/api/v1/health`）→ post-merge smoke → 失敗**自動 rollback**（前端重 build + `migrate:rollback` + rollback health check）。Sentry DSN 已接、dependabot 已設、CI raw-hex guard 已上。

## 仍缺的下一層（M4，對標大廠，已開 issue）

| # | 缺口 | 大廠對標 |
|---|---|---|
| #868 | **staging/pre-prod 環境**（目前合併後直上 prod Pi） | GitHub Flow、Heroku pipelines、dev/prod parity |
| #869 | **Feature flags**（解耦 deploy 與 release、kill-switch） | Unleash、LaunchDarkly、dark launch |
| #870 | **CI 高可用**（消除單一 self-hosted runner SPOF + 用量告警；今日帳單事件暴露） | runner scale sets、ARC |
| #871 | **Merge Queue**（消除 up-to-date 競態、免手動 update-branch） | GitHub Merge Queue、Bors/Mergify |
| #872 | **DORA 四指標儀表板** | Google DORA / Four Keys |
| #873 | **Blameless postmortem 範本 + 行動項追蹤**（正式化事故 A–F） | Google SRE Postmortem Culture |

## GitHub Projects？
目前用 **milestone + label + issue** 已能組織工作（M1–M4、area:*、priority:*、type:*）。Projects（看板/跨 milestone 視圖）對單人 + AI 流程是「可有」而非「必須」。若要啟用，需 `gh auth refresh -s project,read:project`（Daisy 需互動授權），之後可把 M4 + 改善 epic 拉進一個 board 做跨里程碑視覺化。建議：先用 milestone 跑，待並行工作變多再開 Project board。

## AI 接手原則
1. 先讀本檔「進行中狀態」+ `CLAUDE.md` 5 紅線 + `MEMORY.md`。
2. 任何 CI 卡住先分清：**self-hosted（Vite/Presubmit/PHPUnit，不吃 GitHub 額度）** vs **GitHub-hosted（gitleaks/Golden/Dependency Review，受帳單影響）**。
3. `.vue` 註解禁寫 `#NNN`（會被 hex guard 當色碼）；hex guard 用 WSL/Linux 跑（Windows grep 會漏報）。
