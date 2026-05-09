## Summary
<!-- 一句話說明這個 PR 做了什麼，以及為什麼 -->

## 關聯 Issue（Refs / Closes 規則）
<!-- 多階段、Epic、仍有一截沒做完 → 只填 Refs，不要寫 Closes，避免 GitHub 整張 issue 被關掉 -->
- **Refs**：`Refs #123`（本 PR 只完成其中一部分、後續還有 Phase 2 / follow-up）
- **Closes**：`Closes #123`（本 PR 完成該 issue 全部驗收範圍時才可填；**一整張 issue 含多 Phase 時，請等最後一個 PR 再寫 Closes**）

> 不確定就一律 **Refs**，merge 後在 issue 手動勾進度。

## Type
- [ ] feat — 新功能
- [ ] fix — Bug 修復
- [ ] chore — 文件 / 設定 / 維護
- [ ] td — 技術債清償

## Test Plan
<!-- 列出驗收步驟；PHPUnit / 前端測試由 CI 跑，有手動場景再寫 -->
- [ ] 

> **Golden**：無需人工勾選。Presubmit **CHECK 6** 與 CI job **Golden scenarios report** 會依 diff 對應 §0–§4；見 [`docs/QA_GOLDEN_SCENARIOS.md`](../docs/QA_GOLDEN_SCENARIOS.md)。

## Checklist
- [ ] 已 push feature branch；**merge 前** CI / Presubmit / Security 需全綠（由負責人跟到 completed）
- [ ] 有改 `backend/app/`、`backend/routes/`、`frontend/src/` → 已更新 `docs/CHANGELOG.md`（docs-only / 純 workflow 可略，見團隊慣例）
- [ ] 有 DB migration → 併 PR 說明上線後由 `deploy.yml` migrate；不在 production 手動試跑 full test
- [ ] 有前端 deployable diff → merge 後確認 `deploy.yml` 成功，必要時驗 `version.json` / health
- [ ] 未擅自改 `AlertController::tuition` / `SessionDeductionService` 等高風險邏輯（若有改必須有測試 + 審核）
- [ ] 新 query 有多校區隔離：`CampusID` / `branch_id`
- [ ] 未 `git push --force`、未直推 `main`

## Screenshots（前端有 UI 改動時填）
<!-- 貼 before / after 截圖 -->
