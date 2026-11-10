---
name: mobile attendance UI fix
overview: 修復出缺勤頁面手機操作的兩個問題：底部按鈕遮擋內容、滑動時畫面閃爍。
todos:
  - id: fix-flicker-gpu
    content: "styles.css：.mobile-bottom-nav 加 will-change: transform; transform: translateZ(0)；body 移除 -webkit-overflow-scrolling: touch"
    status: completed
  - id: fix-batch-bar
    content: AttendancePage.vue：.att-sticky-batch 的 bottom 改為 calc(56px + env(safe-area-inset-bottom, 0px))，加 GPU 提示；.att-cards 加動態 padding-bottom（選取時多留空間）
    status: completed
  - id: fix-confirm-teleport
    content: AttendancePage.vue：用 <Teleport to="body"> 包住 .att-confirm-overlay，解決 stacking context 遮擋問題
    status: completed
  - id: uiux-signoff
    content: UI/UX 驗收：確認底欄不遮擋任何卡片按鈕、確認 sheet 可完整顯示、滑動無閃爍
    status: completed
  - id: qa-verify
    content: QA 驗收：依第 10 節所有 FR 情境逐一確認（含 safe-area 邊界裝置）
    status: completed
  - id: deploy
    content: npm run deploy 前端上線，確認 index.html + assets 同輪寫入
    status: completed
  - id: changelog
    content: 更新 docs/CHANGELOG.md
    status: completed
  - id: pm-signoff
    content: PM sign-off：DoD 全部打勾
    status: completed
isProject: false
---

# PRD：手機出缺勤頁 UI 修復

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 手機出缺勤頁：底部遮擋與滑動閃爍修復 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 老師（手機操作出缺勤點名） |

## 2. 目標與業務背景

老師在手機上操作出缺勤點名時，遭遇以下兩個痛點：

- **底部按鈕遮擋**：學生卡片底部的「確認」按鈕、或「確認請假」彈出 sheet 的行動按鈕，被固定在底部的導覽列擋住，無法點擊。
- **滑動閃爍**：上下滑動學生卡片列表時，畫面不定時出現閃爍，影響操作體驗與可信賴感。

解決後，老師可流暢在手機完成整個點名流程，不需縮放或滾動技巧繞過遮擋。

**成功指標**：出缺勤頁面手機操作無遮擋投訴、滑動無肉眼可見閃爍。

## 3. 範圍

**In Scope**

- 修復 `.mobile-bottom-nav` 滑動時觸發 GPU repaint 的問題
- 修復 `.att-sticky-batch`（批次全部到班列）在部分裝置 safe-area 計算錯誤
- 修復批次列顯示時，最後一張卡片被遮擋的問題
- 修復「確認請假／缺席」sheet 的 stacking context 問題，使其正確蓋過底欄

**Out of Scope**

- 桌機版 UI 不調整
- 其他頁面的類似問題（另立任務）
- 出缺勤邏輯、API 不變動

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | | A |
| CTO / 工程 | | R |
| UI/UX Designer | | R |
| QA | | R |
| 資安 | | I |
| IT / Ops | | I |

**UI/UX Designer 職責**：確認修復後的視覺效果（safe-area padding、sheet 底部留白、動畫流暢度）符合手機操作標準。

## 5. User Stories

> **As a** 老師, **I want** 滑動學生卡片時畫面保持穩定不閃爍, **so that** 可以快速瀏覽並完成點名。
>
> Acceptance Criteria：
> - [ ] 上下滑動卡片列表，底部導覽列不出現閃爍或重繪抖動
> - [ ] 在 iPhone（Face ID）與 Android 裝置各測試一輪無閃爍

> **As a** 老師, **I want** 卡片列表最後一筆可完整顯示並可點擊「確認」按鈕, **so that** 不需縮放或技巧性滾動。
>
> Acceptance Criteria：
> - [ ] 卡片列表末尾有足夠空白，「確認」按鈕完整可見可點
> - [ ] 當批次列（已選 N 堂）顯示時，最後一張卡片仍可捲出列上方

> **As a** 老師, **I want** 「確認請假／缺席」彈出 sheet 的「確認送出」按鈕完整顯示, **so that** 可以一次按下完成操作。
>
> Acceptance Criteria：
> - [ ] Sheet 的行動按鈕不被底部導覽列遮擋
> - [ ] 點擊 sheet 外側（半透明遮罩）可正確關閉

## 5b. UI/UX 精緻化需求

| 面向 | 要求描述 |
|---|---|
| **底部留白** | 卡片列表底部的 padding-bottom 在批次列顯示時動態增加，確保最後一張卡片底部與批次列之間至少有 12px 間距 |
| **Sheet 按鈕位置** | 「確認請假」sheet 行動按鈕距裝置實際可點擊區域底部至少 24px；Teleport 後覆蓋底欄，padding-bottom 維持 `calc(24px + env(safe-area-inset-bottom, 0px))` |
| **互動回饋** | 批次列的定位需對齊正確 safe-area，不因裝置不同而浮動錯位 |
| **滑動流暢** | 底欄升至 GPU 合成層（`will-change: transform; transform: translateZ(0)`），滑動期間不觸發主執行緒 repaint |
| **空狀態 / 載入** | 本次不涉及，不適用 |
| **響應式** | 修改僅在 `≤640px` media query 內生效，桌機版無影響 |

## 6. 功能需求（FR）

- **FR-001**：`.mobile-bottom-nav` 在 `≤640px` 媒體查詢下，須套用 `will-change: transform; transform: translateZ(0)`，使其運行在 GPU 合成層。
- **FR-002**：`html, body` 的 `-webkit-overflow-scrolling: touch` 僅保留在 `html`，移除 `body` 上的宣告，減少全域 repaint 觸發。
- **FR-003**：`.att-sticky-batch` 的 `bottom` 值由硬編碼 `68px` 改為 `calc(56px + env(safe-area-inset-bottom, 0px))`，並加 `will-change: transform; transform: translateZ(0)`。
- **FR-004**：`.att-cards` 容器在 `selectedIds.length > 0` 時，動態加上 `padding-bottom` 以確保最後一張卡片可捲出批次列上方（批次列高度約 56px，加上 bottom 偏移）。
- **FR-005**：`.att-confirm-overlay` 以 Vue `<Teleport to="body">` 渲染，提升至 root stacking context，使 `z-index: 10100` 可正確蓋過底欄的 `z-index: 10000`。

## 7. 非功能需求（NFR）

- **效能**：加入 GPU 合成層後，底欄滑動不應新增明顯記憶體使用（合成層有額外 VRAM 成本，但底欄面積小可接受）。
- **向下相容**：`env(safe-area-inset-bottom, 0px)` 的 fallback 為 `0px`，舊裝置（無 safe-area）不受影響。
- **錯誤處理**：`Teleport` 目標 `body` 永遠存在，無需額外錯誤處理。

## 8. 技術方向（給 CTO）

**受影響檔案**：
- [`frontend/src/styles.css`](../../../frontend/src/styles.css)（全域樣式，`.mobile-bottom-nav` GPU 提示、`body` `-webkit-overflow-scrolling` 調整）
- [`frontend/src/pages/AttendancePage.vue`](../../../frontend/src/pages/AttendancePage.vue)（`.att-sticky-batch` bottom/GPU、`.att-cards` 動態 padding、`<Teleport>` confirm overlay）

**架構選擇說明**：
- 使用 `<Teleport to="body">` 而非修改 z-index 數值，根本解決 stacking context 問題，避免未來再以數值競爭方式處理層級衝突。
- `.main-content` 的 `overflow-x: hidden` 是既有佈局需求，不予修改。
- 無需 migration 或後端調整。

**子任務 Agent 派發**：
- `[FEATURE]` → 前端 CSS 與 Vue 修改
- `[TEST]` → 手動 QA 清單（無 Pest 測試，純前端視覺/互動修復）
- `[DOCS]` → `docs/CHANGELOG.md` 更新

**stacking context 根因圖解**：

```
App.vue (root stacking context)
  ├── .mobile-bottom-nav  position:fixed; z-index:10000   ← root context
  └── .main-content       overflow-x:hidden               ← 形成新 stacking context
       └── AttendancePage.vue
            └── .att-confirm-overlay  z-index:10100      ← 僅在 main-content context 有效
                                                            → 無法蓋過 root 的底欄

修復後（Teleport）：
App.vue (root stacking context)
  ├── .mobile-bottom-nav  z-index:10000
  └── .att-confirm-overlay（Teleport）  z-index:10100    ← 同 root context，正確蓋過底欄
```

## 9. 資安與存取控制

- 本修復純為前端 CSS / DOM 結構調整，不涉及 API 或資料存取。
- `<Teleport to="body">` 不引入新的事件監聽或資料流。
- 無 PII 或稽核 log 需求。
- STRIDE 快評：無新增風險面。

## 10. QA 驗收標準與測試計畫

對應 FR 的手動測試情境（無 Pest 自動化，純手機 E2E）：

| FR | 正常路徑 | 邊界條件 | 錯誤處理 |
|---|---|---|---|
| FR-001 / FR-002 | iPhone + Android 上下滑動卡片列表，底欄無閃爍 | 連續快速滑動 10 次 | N/A |
| FR-003 | 選取 1 筆後批次列出現，位置正確不超出底欄 | iPhone 15（safe-area 34px）/ SE（safe-area 0px） | N/A |
| FR-004 | 選取 1 筆後，將列表滾至最後，最後一張卡片「確認」按鈕完整可見 | 僅 1 筆學生（單張卡片）時是否有多餘空白 | N/A |
| FR-005 | 點擊學生卡片「請假」→「確認」後，sheet 彈出，「確認送出」按鈕可見可點；點擊遮罩關閉 | sheet 內容較長時（含錯誤訊息）不被截斷 | Teleport target 不存在時（理論不發生） |

**UI/UX 驗收清單**：
- [ ] 卡片列表末尾 padding-bottom 足夠，「確認」按鈕不被底欄遮擋
- [ ] 批次列顯示時，最後一張卡片可捲出批次列上方至少 12px
- [ ] 「確認請假」sheet 行動按鈕完整可見，不被底欄遮擋
- [ ] 點擊遮罩可關閉 sheet（Teleport 後事件冒泡正常）
- [ ] 上下滑動卡片列表無閃爍（iPhone + Android 各測）
- [ ] 批次列位置在 iPhone 15 與 iPhone SE 兩種 safe-area 裝置上均正確

## 11. 上線與維運

部署步驟：
1. 修改 `frontend/src/styles.css`、`frontend/src/pages/AttendancePage.vue`
2. 執行 `cd frontend && npm run deploy`（vite build + copy 到 backend/public，`index.html` 與 `assets/` 必須同輪寫入）
3. 確認 nginx 提供新版 `index.html`（清瀏覽器快取後驗證）

回滾方案：git revert 本次 commit，重新執行 `npm run deploy`。

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0（Must Have） | FR-001～FR-005 全部修復 | 1hr | `[FEATURE]` |
| P1（Should Have） | UI/UX 驗收（含兩種裝置測試） | 30min | QA |
| P2（Nice to Have） | 其他頁面類似 stacking context 問題一併排查 | 另立任務 | — |

## 13. 風險、假設、開放問題

**風險**：
- 低：`<Teleport>` 後 scoped CSS 可能失效（`<style scoped>` 的 data-v attribute 不會套用到 teleport 目標外的 DOM），需確認 `.att-confirm-overlay` 與 `.att-confirm-sheet` 的樣式為非 scoped 或改為全域宣告。

**假設**：
- `body` 元素在 Vue app 掛載後始終可作為 Teleport target。
- 所有目標手機瀏覽器（iOS Safari / Android Chrome）支援 `env(safe-area-inset-bottom)`（iOS 11+、Chrome 69+ 支援，可接受）。

**開放問題**：
- 無。

## 14. Definition of Done

- [ ] 所有 FR（FR-001 ～ FR-005）通過 QA 驗收
- [ ] UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off
- [ ] 資安審查無阻擋項（本次無新風險）
- [ ] `npm run deploy` 且 `index.html` + `assets/` 同輪寫入
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
