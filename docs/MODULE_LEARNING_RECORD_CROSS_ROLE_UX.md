---
owner: jerry (CEO)
status: proposed
issue: 1621
last_reviewed: 2026-08-02
---

# 學習評量跨角色 UX 規格

## 要回答的問題

老師：今天還有哪些評量需要我完成或修改？

主任：我能否在不逐筆開啟表單的情況下，安心判斷哪一筆要核准、要求修改或退回？

家長：孩子已核准的學習更新與我的回饋，目前進展到哪裡？

## 研究依據

- Google Classroom 的 grading tool 先按工作狀態集中提交，再可在學生間前後檢視，並保留個別回饋與歷程：<https://support.google.com/edu/classroom/answer/16643267>
- Microsoft Teams 將 Turn in、Turn in again、Turn in late 與 Not turned in 對應成當下唯一可做的下一步：<https://support.microsoft.com/en-us/education/turn-in-an-assignment-in-microsoft-teams>
- Filament 將高頻 row action 放在列尾，批次動作置於 toolbar，低頻操作收進 action group：<https://filamentphp.com/docs/4.x/tables/actions>
- 專案既有 star repos：Gibbon（學校情境）、Filament（權限與密集資料）、vben-admin（admin 資訊架構）、Primer／Carbon（可及性元件語言）。
- AllTrue：`RULE_DESIGN_SYSTEM.md`、`AI_REGRESSION_LESSONS.md` R65 與評量／請假回歸家族。

## 問題根因

目前 `LearningRecordsPage.vue` 以一個共用清單同時承載老師填寫、主任審核、家長回饋、歷史搜尋與匯出。狀態 tab、未填、優先、家長回饋等篩選分散在多個列；主任要讀正文仍需開啟 modal；桌面 table 與手機 card 的資訊與操作不一致。因此使用者要先理解介面規則，才知道下一步。

## 目標資訊架構

### 老師：完成工作

1. 預設只顯示「需修改、逾期、今日待填」的工作隊列，按需處理優先序排序。
2. 每筆顯示學生、科目、堂次、日期／時間、狀態原因與一個 CTA（填寫、修改或查看）。
3. 課表是進入特定堂次的上下文；歷史與匯出為次要檢視，不能搶走主 CTA。

### 主任：審核工作

1. 預設是待審 preview queue，而非先要求理解分組 table。
2. 預覽卡／列固定有學生、科目、堂次、老師、授課進度摘要、作業／表現、家長回饋、提交狀態。
3. `核准` 是唯一橘色主 CTA；`需修改`、`退回` 需有原因輸入與影響說明；低頻的換老師、回退核准、刪除收進更多操作。
4. 點「查看完整內容」才開既有完整表單；審核完後焦點移到下一筆且列表即時同步。

### 家長：閱讀與回饋

1. 僅顯示已核准評量；明確標示課程日期、老師、學習重點與下一步。
2. 回饋以逐堂對話呈現，未讀狀態只對校方角色可見；家長不看見內部審核、作廢或工程術語。

## 狀態與資料契約（不可改動）

- `approved` 代表主任核准，並維持既有的點名同步與堂數扣除；rollback 必須對稱。
- `pending`、`changes_requested` 可進主任待審；`rejected`、`approved` 是歷史／追蹤檢視。
- `leave`、`leave_requested`、`leave_adjusted`、`excused`、`cancelled` 不得列入老師待填或主任待審。
- 維持 active／VoidedAt、停用課程、effective substitute teacher、branch isolation、同日多堂與 keyset pagination 的既有語意。
- 預設載入只為工作隊列取資料；歷史、完整匯出、非必要選項資料延遲載入。

## 視覺與互動規則

- 使用 `--ds-*` token、白／冷灰底、navy 文字與每區一個橘色主要行動；狀態色不當裝飾。
- 桌面主佇列＋預覽；手機 390／412px 一欄，CTA 不隱藏、不依賴水平滑動。
- table 只在寬螢幕提供密度；mobile 自動為 preview card。任一頁 `scrollWidth <= clientWidth`。
- loading 使用 skeleton；empty 說明目前篩選與下一步；錯誤可重新整理；長中文、省略內容要有可開啟全文的可及路徑。
- 所有操作有鍵盤焦點、可讀 label、status announcement；破壞性操作需明確確認。

## 驗收與回歸

- 角色：teacher、director、parent；正常、empty、loading、API error、長文字、大量資料。
- 視窗：390、412、768、1280、1440px；無意外水平溢出。
- 主任：preview 不開表單可辨識正文與回饋；核准、需修改、退回、批次核准與下一筆焦點正確。
- 老師：今日／逾期／需修改 CTA 正確；請假／取消不出現待填。
- 家長：只見 approved；回饋已讀與回覆角色邊界正確。
- 必跑現有 leave exclusion、approval session sync、代課、跨分校、keyset／時間窗口、parent feedback 測試家族。

## 回滾

前端可回滾至上一個 production build；不改資料庫。若新增 API payload，必須向後相容，feature flag 或缺欄 fallback 到既有完整表單，不允許讓審核／扣堂流程停擺。
