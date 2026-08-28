# 學習評量表 UX／產品規格

> 版本：2026-08-01 · Bounded context：`LearningRecordsPage.vue` · Issue：[1611](https://github.com/jerry200176-png/AllTrue_System/issues/1611) · 狀態：第一波修正完成，待 PR／production evidence

## 1. 這次不是單純換皮

學習評量表同時承擔老師填寫、主任審核、主任評語、家長留言、匯出與補登。這些是不同角色、不同風險、不同完成條件的工作，若全部平鋪在同一張頁面，使用者會看到很多狀態與按鈕，卻不容易判斷下一步。

正式站唯讀審查（2026-08-01）記錄：

- 主任畫面同時出現審核 tab、未填快捷篩選、只看未填／已填、學生群組數量、科目狀態 chip 與列狀態；同一件事被重複計數。
- 一列最多同時出現編輯評量、主任評語、換老師、核准、需修改、退回、退回待審、刪除；主要審核動作與低頻管理動作沒有分層。
- 桌面列表沒有文件級水平溢出，但 390px 手機的每個 `.lr-table-scroll` 都是內部水平捲動（實測 `scrollWidth 351 > clientWidth 313`），主要操作被藏在表格右側。
- `localStorage` 可以保留桌面列表模式，導致手機即使 CSS 文字寫著「Show cards, hide table」，實際仍可能進入列表模式；這是狀態與 responsive 規則不一致。
- `篩選`（快速列）與 `篩選條件`（完整面板）同時可見，兩者都像主要入口；需改成一個清楚的篩選入口。

## 2. 研究與具體採用規則

### 專案文件與 star repo 原始碼

- `docs/RULE_DESIGN_SYSTEM.md`：白／冷灰底、navy 文字、單一橘色主行動、語意色只表達狀態。
- `docs/AI_REGRESSION_LESSONS.md`：R11、R19、R32、R39、R42、R46、R65、R78、R86、R88；尤其保留歷史評量、effective substitute teacher、請假狀態與真實模組測試。
- `pacifio/ui` `kitchen-sink/app/patterns/dashboard/page.tsx`：關鍵摘要限制在少量資訊，頁首主動作與資料表分離。
- `carbon-design-system/carbon` `data-table-toolbar.stories.ts`：搜尋／溢位操作／主動作在 toolbar；批次操作只在選取模式出現，不能常駐每一列。
- `primer/css` `table-object.scss`：把主要識別欄留給內容，其他欄位不搶寬度，避免窄畫面因次要欄位擠壓主要內容。
- `GibbonEdu/core` `modules/Markbook/markbook_view_myMarks.php`：學校評量先有說明，再用學習領域／學年／學期／類型篩選，細節採漸進揭露，且只把已發布結果當成可見資料。
- `vbenjs/vue-vben-admin`：頁首、篩選、資料區、操作區保持穩定層級，低頻 row action 收進 scoped action surface。

### 企業產品與官方操作文件

- [Microsoft Teams Assignments：Grade, return, and reassign assignments](https://support.microsoft.com/en-us/education/assignments/grade-return-and-reassign-assignments)：Ready to grade／To return／Returned 分成工作佇列；勾選後才出現批次 Return／Return for revision，逐筆 feedback 與狀態同一上下文。
- [Microsoft Teams Assignments educator navigation](https://support.microsoft.com/en-us/education/view-and-navigate-your-assignments-educator)：搜尋、狀態篩選與 grades table 是定位工具；學生工作與動作在點入後處理。
- [Google Classroom：Grade & return an assignment](https://support.google.com/edu/classroom/answer/6020294)：開啟學生、檢視歷程、給分／回饋、Return 是一條可理解的流程，Return 不等於修改資料本身。

這些來源共同支持的規則是：先讓使用者選工作佇列，再在單筆上下文處理；批次動作不常駐；狀態必須有文字與下一步，不靠顏色猜；手機不把主要 CTA 放在水平捲動表格後面。

## 3. 角色與任務邊界

| 角色 | 首要任務 | 預設工作面 | 不應搶走第一眼注意力的功能 |
|---|---|---|---|
| 老師 | 找到今天未填或被退回的堂次並完成評量 | 課表／待填／需修改 | 匯出、批次補登、主任評語 |
| 主任 | 找到待核准案件，或追蹤老師需修改的紀錄，再核准／退回 | 待主任核准／老師需修改 | 換老師、刪除、匯出 |
| 家長 | 讀取已核准的學習更新並留言 | 家長入口 timeline／回饋 | 內部審核狀態與工程術語 |

關鍵資料模型仍分成兩個維度顯示：

- 填寫狀態：未填／已填。它回答「內容是否存在」。
- 審核狀態：待審／需修改／已核准／已退回。它回答「工作流程走到哪裡」。

兩者不得用單一 `待處理` 或單一色塊混在一起。

## 4. 第一波 UX 修正

1. 手機 640px 以下強制採卡片模式，不受桌面 `localStorage` 的列表偏好影響；列表切換在手機隱藏，避免「看得到但做不到」的控制。
2. 卡片與列表都先呈現學生、日期／時間、科目、老師、填寫狀態、審核狀態，再呈現主要 CTA；所有主要 CTA 不依賴水平捲動。
3. 頁首說明依角色改成工作導向文案，直接說明「先處理什麼」與「已核准只能檢視」的限制。
4. 既有 `批次操作` 保持選取後才出現；不變更 approval、reject、request-changes、effective teacher、請假排除或分校權限契約。
5. API error 與 empty 分開呈現；載入失敗提供可重試入口，避免錯誤被誤讀成「沒有資料」。
6. 低頻 action 的進一步收斂列入第二波：以 scoped「更多」取代常駐 row action，但必須先補完整功能測試與權限矩陣，不能為了變少按鈕而遺失主任可用能力。

## 5. 驗收條件

- 390、412px：卡片模式、主要 CTA 可見、卡片內沒有水平捲動；文件 `scrollWidth <= clientWidth`。
- 768、1280、1440px：桌面列表可讀，主身份欄不被操作欄擠壓；列表／卡片切換可用。
- 正常、空資料、loading、API error、長姓名／長留言、資料很多。
- 角色：老師填寫／需修改、主任核准／需修改／退回、家長留言入口與既有導覽事件不變。
- 鍵盤可切換 tab、篩選、卡片 CTA；focus 樣式與 ARIA 不退化。
- 不執行 production mutation；上線後只做唯讀畫面、導覽、loading、error、empty 與 overflow smoke。

## 6. 資料、權限與回滾紅線

- 不改資料庫，不改既有 learning-record API payload 與權限邊界。
- 不以 UI 顯示推導 approval truth；保留後端 status、effective substitute teacher 與 leave family 契約。
- 不觸碰 `bulk-backdoor-approve` 的行為；所有 UI 變更都能由前端 feature branch 回滾。
- 若 production smoke 發現狀態、角色或歷史資料缺失，先回滾本 PR，再開資料契約修正，不在 UI PR 內猜測修 DB。

## 7. 多筆內容預覽方案（新增需求）

老師需要比較多堂課的進度，不應為了讀取內容逐筆開啟編輯 modal。因此新增「預覽內容」控制：

- 老師預設開啟，主任可在列表工具列開啟；它只控制 read-only preview，不改變 API 或表單狀態。
- 每筆資料在列表／卡片直接顯示作業、週考、上課狀況，以及已填寫的授課進度、作業範圍、週考範圍與家長溝通文字。
- `編輯評量` 仍是唯一進入表單的操作；預覽不會讓整列變成可編輯，也不會把核准／需修改／退回混進內容區。
- 桌面列表使用資料列下方的 full-width preview row；手機使用卡片內嵌 preview，避免把內容塞進需要水平捲動的欄位。
- 預覽空資料明確顯示「尚未填寫評量內容」，不把空白誤讀成載入失敗或已核准。

採用這個方案是根據 [Microsoft Teams educator assignments](https://support.microsoft.com/en-us/education/view-and-navigate-your-assignments-educator) 的「清單先呈現狀態、點入才進 grading pane」與 [Google Classroom grading](https://support.google.com/edu/classroom/answer/16643267?hl=en) 的「同一位置檢視作業狀態、回饋與學生工作」做的 AllTrue 特化：我們把可安全掃讀的評量正文提前到列表，但把編輯與高風險狀態變更留在原本的 action surface。
