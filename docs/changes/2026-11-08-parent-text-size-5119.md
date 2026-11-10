## 2026-11-08 — fix(parent): 設定字級入口與首頁付款按鈕字級
<!-- release-notes: staff_update=staff-2026-11-08-text-size-keyboard -->
- Founder approved both digest drafts in the active Cloud handoff chat on 2026-11-08. The shared control changes staff keyboard interaction, so this is a staff update, with a separate parent update for the reachable setting. The approved YAML is prepared for the normal release process; approval of copy does not authorize production activation or establish deployment/acceptance.
- Refs #5119。家長設定重用 `TextSizeToggle` 與 `useTextSize`，沿用此裝置的標準／大偏好；共用 radio 支援單一 Tab 停駐點及上下左右方向鍵循環選取。
- 三顆首頁按鈕的字級改為明確的 Tailwind `length:` 型別，付款文字色使用 `color:`，避免 `cn()` 合併時移除付款字級或 secondary 文字色。不改付款、請假 API、資料、身份或權限。
- 防再犯：真實 Button 的 `cn()` 測試與建置後真實 app 假資料 Playwright，涵蓋設定入口、鍵盤／點擊、返回、重載、390／1440px 與 16／20px 根字級下三顆首頁按鈕的計算字級／顏色。盤點 `frontend/src/**/*.vue` 的 Button 標籤，自訂 `text-tpl-*` 字級僅用於這三顆；其他文字節點未經 `cn()` 不受此合併問題影響。
- 正式登入、實機觸控與輔助科技驗收留待後續；公告文案已核准，但本次 preview push 不包含合併、正式部署或發送通知。
- Review 修正：預設 smoke 排除專用 5189 spec；既有非 required `ui-preview` workflow 的獨立 advisory job 使用專用 built preview config 執行四組假資料測試，與 5177 UI foundation server 分離。不變更正式 smoke、required checks 或 quality gate。
- Scope the existing PR-comment write permission to the preview deployment/comment job; the workflow default and fake-data advisory job remain read-only. This resolves the Zizmor excessive-permissions finding without changing triggers, fork protection, action pins, credentials, or approved announcement copy.
