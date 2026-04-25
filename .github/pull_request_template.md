## Summary
<!-- 一句話說明這個 PR 做了什麼，以及為什麼 -->

## Type
- [ ] feat — 新功能
- [ ] fix — Bug 修復
- [ ] chore — 文件 / 設定 / 維護
- [ ] td — 技術債清償

## Test Plan
<!-- 列出驗收步驟，CI 會自動跑 PHPUnit，這裡填手動驗收項目 -->
- [ ] 

## Checklist
- [ ] CI 全綠後才開這個 PR
- [ ] 有 DB migration → `php artisan migrate:status` 確認
- [ ] 有前端改動 → deploy 後確認 `version.json` 時間戳更新
- [ ] 未動 `AlertController::tuition` / `SessionDeductionService` 高風險邏輯（若有動則標記）
- [ ] 多校區隔離：新 query 帶 `CampusID` / `branch_id`

## Screenshots（前端有 UI 改動時填）
<!-- 貼 before / after 截圖 -->
