## Summary
<!-- 一句話說明這個 PR 做了什麼，以及為什麼 -->

> **單人 repo Review Gate（#736）**：無第二位強制 reviewer 時，以「自動代理人 + 強制檢查」近似第二雙眼——
> ①自動 AI review 留言（Bugbot/Copilot review，repo 設定啟用）②高風險檔強制附測試（required check `High-Risk Test Gate`）③下方 self-review checklist。請當成 reviewer 逐項自審，不要當作純自審 rubber-stamp。

## 關聯 Issue（Refs / Closes 規則）
<!-- 多階段、Epic、仍有一截沒做完 → 只填 Refs，不要寫 Closes，避免 GitHub 整張 issue 被關掉 -->
- **Refs**：`Refs #123`（本 PR 只完成其中一部分、後續還有 Phase 2 / follow-up）
- **Closes**：`Closes #123`（本 PR 完成該 issue 全部驗收範圍時才可填；**一整張 issue 含多 Phase 時，請等最後一個 PR 再寫 Closes**）
- **N/A**：無對應 issue 時寫原因（例：docs-only governance / dependabot）

> 不確定就一律 **Refs**，merge 後在 issue 手動勾進度。

## Type
- [ ] feat — 新功能
- [ ] fix — Bug 修復
- [ ] refactor — 重構（行為不變）
- [ ] test — 測試
- [ ] docs — 文件
- [ ] ci — CI / workflows
- [ ] chore — 設定 / 維護
- [ ] td — 技術債清償

## PR Size（認知負擔護欄）
<!-- 對齊 OPERATIONS_RUNBOOK：目標 ≤400 行；硬上限約 700；docs-only 可到 ~1300 -->
- **Class**：S（≤100 行 / ≤5 files） / M / L / XL
- **Changed files / +/- lines**：（填大約數字）
- **Why not split?**：（L/XL 必填；否則寫 N/A）

> **汚染 PR 直接關閉重建**：若 diff 含家目錄垃圾（`.vnc` / `.face` / `.xsession-errors`）、不明 `public/assets` 大量 hash 檔、或 GitHub 回報 diff >300 files，**不要 merge**——從 `main` 重建最小 PR。見 `docs/reviews/REPO_GOVERNANCE_REVIEW_2026-07-15.md`。

## Test Plan
<!-- 列出驗收步驟；PHPUnit / 前端測試由 CI 跑，有手動場景再寫 -->
- [ ] 

> **Golden**：無需人工勾選。Presubmit **CHECK 6** 與 CI job **Golden scenarios report** 會依 diff 對應 §0–§4；見 [`docs/QA_GOLDEN_SCENARIOS.md`](../docs/QA_GOLDEN_SCENARIOS.md)。

## Migration Compatibility（有 DB migration 時必填）
<!-- 見 docs/RULE_MIGRATION_COMPAT.md -->
- **Phase**: Expand / Backfill / Contract / Simple Add / N/A
- **Reversibility**: `down()` 可逆 ✅ / 不可逆（原因：___）
- **Big table risk**: 表名 + 預估 row 數（> 10K 需評估）
- **Rollback impact**: code rollback 後舊 schema 是否仍可運行？


## Checklist
- [ ] 已 push feature branch；**merge 前** CI / Presubmit / Security 需全綠（由負責人跟到 completed）
- [ ] **Docs sync 矩陣**（適用者勾；其餘寫 N/A 於 PR 說明）：
  - [ ] `docs/CHANGELOG.md`（使用者可感知的 `backend/app` / `routes` / `frontend/src` 變更）
  - [ ] `docs/AI_REGRESSION_LESSONS.md`（新紅線／高風險行為）
  - [ ] `docs/TECH_DEBT.md`（本次不修的 Minor）
  - [ ] `docs/INDEX.md`（新文件／導航入口）
  - [ ] docs-only / 純 workflow → 可略 CHANGELOG 使用者卡，但仍更新治理文件若改了權威事實
- [ ] 有 DB migration → 併 PR 說明上線後由 `deploy.yml` migrate；不在 production 手動試跑 full test
- [ ] 有前端 deployable diff → merge 後確認 `deploy.yml` 成功，必要時驗 `version.json` / health
- [ ] 未擅自改 `AlertController::tuition` / `SessionDeductionService` 等高風險邏輯（若有改必須有測試 + 審核）
- [ ] **多校區隔離**：新 query / endpoint 都帶 `CampusID` / `branch_id`；跨校 join 經 `require_campus` 或 `resolveCampusIds`（Execution #482）
- [ ] 未 `git push --force`、未直推 `main`

## Threat Note（T2/T3 必填；T0/T1 可略）
<!-- 對齊 .cursor/rules/module-security.mdc / Execution #480。若改 auth / PII / RFID / LINE webhook / 跨校資料邊界、新增公開端點，務必填寫。 -->
- **資產 (Asset)**：<!-- e.g., StudentClass 堂數、家長手機、LINE userId -->
- **威脅 (Threat / STRIDE)**：<!-- S/T/R/I/D/E 哪一面，1–2 句 -->
- **緩解 (Mitigation)**：<!-- middleware / rate limit / authz check / audit log / 多校區 filter -->
- **殘餘風險 (Residual)**：<!-- 可接受或要追新 issue -->

> 若本 PR 只是 docs / CSS / 純 refactor 無安全邊界變更，請在此節寫 `N/A — 無 auth/PII/邊界變更`。

## Screenshots（前端有 UI 改動時填）
<!-- 貼 before / after 截圖 -->

## Design System（前端有 UI 改動時填，#737 制度化）
<!-- 參考 docs/RULE_DESIGN_SYSTEM.md（唯一真相）；不確定就對照禁止清單 §7 -->
<!-- CI 自動偵測新增 raw hex：一旦超過 baseline 即 FAIL（不再 advisory） -->
- [ ] **無新增 raw `#hex`**（CI blocking — 違反即 PR 無法 merge；需清理或更新 baseline）
- [ ] 每個區塊 Primary CTA ≤ 1 顆（`docs/RULE_DESIGN_SYSTEM.md §5`）
- [ ] 金額 / 堂數 / 日期已套 `tabular-nums`（`§6 tabular`）
- [ ] 空狀態含圖示 + 一句說明 + 下一步行動（`docs/GUIDE_UI_COPY.md §2`）
- [ ] Loading 狀態：骨架屏或 spinner，非白屏（`RULE_DESIGN_SYSTEM §4`）
- [ ] 未改業務邏輯 / 繳費規則（只動樣式 / 文案 / 空狀態）
