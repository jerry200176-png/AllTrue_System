# PRD — 老師黏著度：連續使用天數（Issue #314 階段 1）

> 對應 GitHub **#314**；本檔為簡版 PRD，滿足「PRD + 至少一子功能 PR」DoD。業界參考：職場 gamification 強調 **opt-in／不影響考績敘述**（如 Interaction Design Foundation 論 workplace gamification 倫理、Trophy.so「不反噬的生產力遊戲化」）；OSS 參考：**local-first streak**（[jsjoeio/use-streak](https://github.com/jsjoeio/use-streak)、習慣追蹤 Web App 存 localStorage 模式）。

## 0. 根因（BugFix 專屬）

N/A（新功能）。

## 1. 文件資訊

- 日期：2026-05-10
- 範圍：老師端 Web
- 關聯：#314

## 2. 目標／KPI

- 提供 **可選** 的正向回饋（連續使用天數），不預設打擾、不綁考績。
- v1 不上傳伺服器，降低法遵／資安表面積。

## 3. 範圍

**In**：本機日曆連續天數、個人資料開關、教學工作台摘要條（開啟時）。

**Out**：主任儀表板、排行榜、後台報表、凍結 streak／週常任務條／徽章（另開 issue）。

## 4. RACI

- 產品決策：CEO／主任顧問
- DEV：本 PR
- QA：手動 + `npm run test:teacher-streak`

## 4b. Dependencies

- 無後端；依賴老師登入 session（`App.vue` 觸發計數）。

## 5. User Stories + AC

1. **身為老師**，我可在個人資料預設關閉「顯示連續使用天數」，開啟後於教學工作台看到本機連續天數摘要。
2. **身為老師**，我未開啟開關時，畫面上不出現該摘要（計數仍可在本機累積，符合「不干擾」）。

## 5b. UI／UX

- 低調 pill、與既有 Porsche-inspired 色票相容；不阻擋打卡／待辦主流程。

## 6. FR

- `teacherLoginStreak.js`：連續邏輯（同日不重複、隔日 +1、斷日重算）、localStorage 鍵名版本化。
- `ProfileCenterPage`：教學設定區塊新增開關。
- `TeacherHomePage`：條件顯示摘要。
- `App.vue`：老師且未鎖密碼變更時，每日首次載入寫入計數。

## 7. NFR

- 無網路呼叫；失敗（quota）靜默。
- 與 `prefers-reduced-motion`：本條無動畫；後續動效另議。

## 8. 技術方向（禁實作細節以外的架構）

- 純前端；計算函式可單測。
- 與音效設定同層「老師可關閉」哲學。

## 8b. Decision Log

- 選 **local-only** 而非 telemetry：對齊 #314 隱私與快速交付。
- 參考 OSS streak 狀態機，不引入新依賴。

## 9. 資安

- v1 不蒐集、不傳輸 streak；僅瀏覽器本機。

## 10. QA 驗收

- 關閉開關：工作台無摘要。
- 開啟：連續兩個日曆日登入，數字遞增；同日不變。
- `npm run test:teacher-streak` PASS。

## 11. 上線／維運

- 前端 deploy；無 migration。

## 12. 優先級

- P2（黏著度實驗）。

## 13. 風險

- 老師誤解為考績：以文案「僅本機」與預設關 mitigating。

## 14. DoD（可自動／人工驗證）

- [x] 本 PRD 檔
- [x] 子功能 PR + CI 綠
- [x] #314 可關閉（合併後）；其餘 backlog 另開 issue
