## 2026-11-08 — fix(parent): 設定字級入口與首頁付款按鈕字級
<!-- release-notes-decision: pending-5119 -->
- 公告決策待決：依 `docs/GUIDE_STAFF_UPDATES.md`，共用 `TextSizeToggle` 亦用於主任首頁與個人設定，鍵盤操作改變是教職員可感知差異；「尚為 draft／未部署」不是永久 silent-ship 理由，不能以 `release-note-none` 代替決策。待核准教職員公告分類／文案後，才新增卡片並改為有效 `staff_update` 標記；家長公告是否另發亦待產品決定。目前不新增公告 YAML、不發布、不通知。此 pending 標記刻意不符合 release-notes coverage：保持 draft、不可視為已滿足合併條件。
- Refs #5119。家長設定重用 `TextSizeToggle` 與 `useTextSize`，沿用此裝置的標準／大偏好；共用 radio 支援單一 Tab 停駐點及上下左右方向鍵循環選取。
- 三顆首頁按鈕的字級改為明確的 Tailwind `length:` 型別，付款文字色使用 `color:`，避免 `cn()` 合併時移除付款字級或 secondary 文字色。不改付款、請假 API、資料、身份或權限。
- 防再犯：真實 Button 的 `cn()` 測試與建置後真實 app 假資料 Playwright，涵蓋設定入口、鍵盤／點擊、返回、重載、390／1440px 與 16／20px 根字級下三顆首頁按鈕的計算字級／顏色。盤點 `frontend/src/**/*.vue` 的 Button 標籤，自訂 `text-tpl-*` 字級僅用於這三顆；其他文字節點未經 `cn()` 不受此合併問題影響。
- 正式登入、實機觸控與輔助科技驗收留待後續；本 PR 不合併、不部署、不通知。
- Review 修正：預設 smoke 排除專用 5189 spec；既有非 required `ui-preview` workflow 的獨立 advisory job 使用專用 built preview config 執行四組假資料測試，與 5177 UI foundation server 分離。不變更正式 smoke、required checks 或 quality gate。
