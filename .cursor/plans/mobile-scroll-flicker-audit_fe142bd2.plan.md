---
name: mobile-scroll-flicker-audit
overview: 針對手機/平板滑動閃爍進行 PM 與架構層級檢查，先建立可重現證據，再分階段降低 fixed/sticky/vh/backdrop-filter 導致的重繪與捲動衝突風險。
todos:
  - id: collect-repro-evidence
    content: 建立閃爍問題的裝置與操作重現清單，鎖定 P0/P1 頁面
    status: completed
  - id: unify-scroll-lock
    content: 設計並導入統一的 modal/fullscreen body scroll lock 策略
    status: completed
  - id: optimize-mobile-effects
    content: 為手機降級 backdrop-filter 並優化 fixed 元件動畫屬性
    status: completed
  - id: replace-vh-strategy
    content: 將高風險頁面的 100vh 改為行動端安全 viewport 策略
    status: completed
  - id: regression-qa
    content: 執行 iOS/Android 回歸腳本並比對修前修後結果
    status: completed
isProject: false
---

# 手機滑動閃爍排查與修正計畫

## 目標
- 建立可重現案例與量測基準，確認閃爍是由哪一類前端模式觸發（`100vh`、多層 `fixed/sticky`、`backdrop-filter`、scroll lock 不一致）。
- 以最小風險方式分批修正，優先處理高流量頁面與高風險組合，避免影響既有業務流程。

## 目前高風險區域（架構觀察）
- 全域版型與彈窗：[`/home/admin/frontend/src/styles.css`](/home/admin/frontend/src/styles.css)、[`/home/admin/frontend/src/App.vue`](/home/admin/frontend/src/App.vue)
  - 風險：大量 `position: fixed` + `backdrop-filter` + `vh` 高度 + 多個 overlay 實作。
- 課務全螢幕模式：[`/home/admin/frontend/src/pages/CourseManagement.vue`](/home/admin/frontend/src/pages/CourseManagement.vue)
  - 風險：`focus-fullscreen-mode`（fixed + overflow）與 `sticky + blur` 組合。
- 課表與聊天：[`/home/admin/frontend/src/pages/SmartCalendar.vue`](/home/admin/frontend/src/pages/SmartCalendar.vue)、[`/home/admin/frontend/src/pages/ChatPage.vue`](/home/admin/frontend/src/pages/ChatPage.vue)
  - 風險：多層滾動容器、`sticky` 疊加、`100vh` 在行動瀏覽器地址列伸縮下重排。
- 行動裝置浮動元件：[`/home/admin/frontend/src/components/BugReportLauncher.vue`](/home/admin/frontend/src/components/BugReportLauncher.vue)、[`/home/admin/frontend/src/lib/usePageGuideTour.js`](/home/admin/frontend/src/lib/usePageGuideTour.js)
  - 風險：`touch-action:none` + fixed 元件 + body scroll lock 策略不一致。

## PM 執行方案（先驗證再改）
- 建立回報模板：裝置型號、OS、瀏覽器版本、頁面、觸發手勢、錄影 10-20 秒。
- 建立優先級矩陣：
  - P0：主入口頁（首頁、課表、課務）可穩定重現。
  - P1：特定 modal/抽屜流程可重現。
  - P2：低機率或特定機型才發生。
- 測試樣本最小集：iPhone Safari、iPad Safari、Android Chrome（各 1 新 1 舊）。
- 驗收指標：滑動連續性、是否白屏閃一下、是否跳位、是否誤觸背景捲動。

## 架構修正策略（分三階段）
- Phase 1（低風險快速止血）
  - 統一 scroll lock：modal/fullscreen 開啟時採單一機制鎖背景滾動，避免「有的鎖、有的不鎖」。
  - 降級高成本效果：手機上減少 `backdrop-filter`（改半透明背景）。
  - 將高頻動畫從 `left/top` 轉為 `transform` 思路，降低 layout/repaint。
- Phase 2（版型穩定化）
  - 將 `100vh` 相關容器改為行動端安全 viewport 策略（`100dvh` 或等效 fallback）。
  - 減少多層 `overflow` + `sticky` 疊加，盡量單一主捲動容器。
- Phase 3（規範化）
  - 建立前端 UI 規範：何時可用 `fixed/sticky/backdrop-filter`、行動端禁用條件、modal 結構標準。
  - 抽共用 composable/utility（scroll lock、viewport height、overlay stack 管理）。

## 建議實作順序
1. 先修 [`/home/admin/frontend/src/pages/CourseManagement.vue`](/home/admin/frontend/src/pages/CourseManagement.vue) 的 fullscreen + sticky/blur 組合。
2. 再修 [`/home/admin/frontend/src/styles.css`](/home/admin/frontend/src/styles.css) 與 [`/home/admin/frontend/src/App.vue`](/home/admin/frontend/src/App.vue) 的全域 overlay/fixed 行為。
3. 之後修 [`/home/admin/frontend/src/pages/SmartCalendar.vue`](/home/admin/frontend/src/pages/SmartCalendar.vue) 與 [`/home/admin/frontend/src/pages/ChatPage.vue`](/home/admin/frontend/src/pages/ChatPage.vue) 的滾動容器/viewport 高度。
4. 最後收斂浮動元件行為（`BugReportLauncher` / PageGuide）。

## 驗證與回歸
- 每階段完成後，對同一批 3 類裝置重跑相同手勢腳本（快速上下滑、慢速滑、開 modal 後滑、鍵盤彈出後滑）。
- 確認無副作用：底部導航、課表 sticky 標頭、modal 可關閉、表單可輸入、焦點不跳動。
- 若有性能工具可用，補一次 CPU timeline 對照（修前/修後）。

## 風險
- 調整 scroll/viewport 可能影響既有 modal 尺寸與頁面高度計算。
- 調整 sticky/overflow 可能影響課表與表格可用性，需要頁面級回歸。
- 若一次改太多，難以歸因；需按 phase 小步發布。