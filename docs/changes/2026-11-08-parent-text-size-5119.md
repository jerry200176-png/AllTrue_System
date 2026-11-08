## 2026-11-08 — fix(parent): 設定字級入口與首頁付款按鈕字級
<!-- release-notes: silent_ship=silent-2026-11-08-parent-text-size-5119 -->
<!-- silent-reason: 僅交付 draft PR，尚未部署及正式驗證；本次不發布公告或通知。 -->
- Refs #5119。家長設定重用 `TextSizeToggle` 與 `useTextSize`，沿用此裝置的標準／大偏好；共用 radio 支援單一 Tab 停駐點及上下左右方向鍵循環選取。
- 三顆首頁按鈕的字級改為明確的 Tailwind `length:` 型別，付款文字色使用 `color:`，避免 `cn()` 合併時移除付款字級或 secondary 文字色。不改付款、請假 API、資料、身份或權限。
- 防再犯：真實 Button 的 `cn()` 測試與建置後真實 app 假資料 Playwright，涵蓋設定入口、鍵盤／點擊、返回、重載、390／1440px 與 16／20px 根字級下三顆首頁按鈕的計算字級／顏色。盤點 `frontend/src/**/*.vue` 的 Button 標籤，自訂 `text-tpl-*` 字級僅用於這三顆；其他文字節點未經 `cn()` 不受此合併問題影響。
- 正式登入、實機觸控與輔助科技驗收留待後續；本 PR 不合併、不部署、不通知。
