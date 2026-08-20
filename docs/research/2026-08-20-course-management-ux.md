# 課程管理 UX 研究與適配紀錄

日期：2026-08-20
範圍：Issue #1922 的 Phase A1、Phase B 首刀，以及課程管理頁的低風險 UX polish。

## 決策摘要

把課程管理定位成「唯讀營運鏡頭」：使用者在此搜尋、篩選、辨識需要注意的課程，再回到學生管理的學生主檔完成建立、編輯、續報與加購。這次只改善訊息架構與導覽，不移除尚未完成對等入口盤點的既有寫入 API，也不做 Phase A2/A3 的堂次或合約資料變更。

## 目前產品行為

- `frontend/src/pages/CourseManagement.vue` 同時承載查找、統計與多個歷史寫入流程；Issue #1922 已先移除頁首「新增課程」入口及矛盾的空狀態。
- 本次新增唯讀視圖徽章、角色說明、導向學生管理的單一主動作、目前結果摘要、可清除篩選，以及表單 label/id 與 focus-visible 樣式。
- 摘要數字只使用目前載入與篩選後的 `courses`，不宣稱全校總量；「續報提醒」以目前頁面可見且剩餘 2 堂以下的課程為範圍。

## 四層證據

### 1. 官方產品與設計系統

- Carbon Data Table 將資料表定位為組織與導向特定紀錄的元件，toolbar 可承載主要動作、搜尋、篩選與檢視設定；這支持「先篩選，再進入主檔」的操作順序。來源：[Carbon Data Table usage](https://carbondesignsystem.com/components/data-table/usage/)。
- Carbon Tag 明確區分 read-only、selectable 與 operational 狀態，並要求鍵盤狀態與非色彩識別；AllTrue 的狀態 chip 應延續此原則。來源：[Carbon Tag usage](https://carbondesignsystem.com/components/tag/usage/)。
- Salesforce Lightning record page 將標題、動作列、重點摘要、分頁與 related lists 放在同一筆紀錄的頁面上下文中，related list 以 View All 導向完整清單；這支持把編輯責任集中在學生主檔。來源：[Salesforce related lists](https://help.salesforce.com/s/articleView?id=basics_understanding_related_lists_lex.htm&language=en_US&type=5) 與 [record page accessibility](https://help.salesforce.com/s/articleView?id=xcloud.accessibility_screen_reader.htm&language=en_US&type=5)。
- GitLab Pajamas 的 filter pattern 要求結構化篩選、清除與搜尋動作的可理解性；本頁加入明確 label 與「清除篩選」，並沿用既有設計 token。來源：[Pajamas Filter](https://design.gitlab.com/components/filter/)。

### 2. 即時產品讀取

以 Cloudflare Browser Run 對 `https://backerwebapp.netlify.app/` 做一次有界公開讀取：HTTP 200，頁面標題為「Backer Web」，渲染內容只到 `載入中…` 的 JavaScript app shell。後續 links 讀取遇到 HTTP 429，因此本紀錄不把登入後流程或畫面當成已驗證證據，也沒有把帳密寫入命令列、檔案或輸出。若要研究登入後 UX，下一步需要可持續的互動瀏覽器 session，再做最小範圍的人工流程觀察。

### 3. 維護中的開源實作

- GibbonEdu/core `v31.0.00` 的 `modules/Students/student_view_details.php` 在學生主檔上下文中組合 timetable、attendance history 與其他相關資料；它是同領域的強證據，但 GPL PHP/HTML 只用來參考資訊架構，不複製程式碼。來源：[GibbonEdu/core student details at v31.0.00](https://github.com/GibbonEdu/core/blob/v31.0.00/modules/Students/student_view_details.php)。
- Frappe 的 List View 負責篩選、排序、分頁與清單瀏覽，Form View 負責單筆紀錄工具列、權限與 timeline；這符合「list 是找資料，form/主檔是改資料」的分工。研究時固定觀察到的版本提交為 `c82403c598b75a8c6eee06a3d63d6c83b5060747`。來源：[Frappe List View source](https://github.com/frappe/frappe/blob/c82403c598b75a8c6eee06a3d63d6c83b5060747/frappe/public/js/frappe/list/list_view.js) 與 [Form source](https://github.com/frappe/frappe/blob/c82403c598b75a8c6eee06a3d63d6c83b5060747/frappe/public/js/frappe/form/form.js)。

### 4. AllTrue 的規則與程式碼

AllTrue UI foundation 要求 light-first、既有 `--ds-*` token、每個區域一個主要 CTA、高資訊密度、鍵盤/focus/ARIA 與 390/768/1440 breakpoint；本次沒有引入新 UI framework、router、圖片資產或 raw hex。`CourseManagement.vue` 仍是既有頁面，改動集中於頁首與篩選區，符合小步 rollout。

## 採用的模式與刻意不做的事

採用：

1. 頁首用徽章說明「唯讀營運視圖」，降低使用者尋找新增/編輯按鈕時的困惑。
2. 只有一個高對比主動作「前往學生管理」，使用既有 `emit('navigate', 'students')`，避免新 router 或第二個 CRUD surface。
3. 以四張小摘要卡回答「目前看到什麼」：學生數、進行中、低堂數提醒、暫停中；它們是 triage 線索，不是可點擊的假按鈕。
4. 篩選欄位補上 label/id，篩選後提供清除動作，保留既有 debounce/load 行為。
5. 在 980px 與 640px 下將主動作與摘要卡改為可讀的堆疊/雙欄，避免桌面版工具列在手機上擠壓。

刻意不做：

- 不把 image model 產生的 mockup 當正式資產；它只作為本輪資訊層次的視覺草圖，正式 UI 仍用 repo-native Vue/CSS 與設計 token。
- 不移除 CourseManagement 其餘約 20 個寫入端點；每一個都要先確認學生管理有對等流程，再拆成獨立 PR。
- 不實作 A2/A3：不改後端 service、合約群組、堂次歸屬、LearningRecord 或權限。
- 不把未能登入驗證的自家 Backer 產品行為寫成事實，也不複製 Carbon、Pajamas、Gibbon 或 Frappe 的程式碼。

## 驗證與後續

本輪最小驗證包含：UX source guardrail、既有 Phase B empty-state guardrail、Calendar regression、全前端 Vitest、no-undef、design raw-hex gate 與 `git diff --check`。合併前仍應補做 390/412/768/1280/1440 的 loading、empty、error、long-text 與無水平溢出 smoke，並在可用的互動瀏覽器 session 中驗證 Backer 登入後流程。

若使用者測試仍問「我要在哪裡改課程」，下一個最小實驗是將學生主檔的課程列 CTA 命名為與此頁完全一致的「編輯課程／續報加購」，並量測從課程管理導向學生管理後是否成功定位到同一學生；不先做整頁合併。
