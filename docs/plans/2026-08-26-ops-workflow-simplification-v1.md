# 營運流程簡化 v1：主任工作導引

日期：2026-08-26  
範圍：主任首頁、帳務中心、班級行事曆／課表  
狀態：v1／v1.1／v1.2、出缺勤堂次防線與流程 telemetry 已上線

## 目標與現況證據

主任目前要在多個頁面與不同名稱之間記住「去哪裡做什麼」：繳費提醒、帳務中心、課程查找、行事曆、調課與代課入口彼此分散。既有頁面其實已具備正確的資料與安全流程，但缺少一個以任務為中心的起點，導致使用者容易重查、誤進唯讀頁面，或不知道下一步。

本計畫先改善尋路與下一步提示，不重寫既有帳務／排課引擎。v1 讓主任從首頁直接開始三個高頻工作，並在目標頁面保留流程順序提示：

1. 收款與核帳：查看待處理 → 登記繳費回報 → 主任確認入帳與收據。
2. 排課與調課：新增排課 → 點選課卡 → 調課或換代課。
3. 每一步只導向既有操作，不自動寫入資料、不繞過權限或衝堂檢查。

## 參考模式與取捨

### 大公司產品模式

- Atlassian 的新導覽以熟悉、可預期的側邊導覽、全域動作與漸進揭露降低認知負擔；本系統採用其中的「主要任務先露出、細節後揭露」原則，而不是增加更多常駐按鈕。參考：[Designing Atlassian’s new navigation](https://www.atlassian.com/blog/how-we-build/designing-atlassians-new-navigation)。
- GitLab Pajamas 將側邊導覽限制在清楚的層級、保留目前位置並支援行動版收合；本切片以可重用的任務卡做頁內導引，避免在沒有完整 router 的現況下製造另一套導航狀態。參考：[Navigation sidebar](https://design.gitlab.com/patterns/navigation-sidebar/)、[Nav item](https://design.gitlab.com/components/nav-item/)、[Tabs](https://design.gitlab.com/components/tabs/)。
- Material 的 Drawer／Rail 適合跨區域導覽，但本 v1 先採用小型流程卡；等路由與權限模型完成統一後，再評估把常用工作提升到全域工作列。參考：[Navigation drawer](https://m2.material.io/components/navigation-drawer)、[Navigation rail](https://m2.material.io/components/navigation-rail)。

### 開源產品模式

以下是 2026-08-26 讀取的版本／提交，僅取用資訊架構與流程語意，不複製程式碼或視覺資產：

| 產品 | 版本／提交 | 授權 | 本計畫採用的觀察 |
| --- | --- | --- | --- |
| [Frappe](https://github.com/frappe/frappe/tree/8ce7a310169e0a4525e087d8fec94b0c82195321) | `8ce7a31` | MIT | ERP 工作以模組與工作區為入口，適合對照帳務「待辦 → 動作」分層。 |
| [Gibbon](https://github.com/GibbonEdu/core/tree/76b5286f81e17dcf793ab7357e410aa2dcd00ca4) | `76b5286` | GPL-3.0 | 校務情境的角色導覽與模組分組，支持主任依職責找功能。 |
| [Moodle](https://github.com/moodle/moodle/tree/6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb) | `6216fe4` | GPL-3.0 | 課程／教學上下文與抽屜式導覽，支持把課表操作留在教學脈絡內。 |
| [Cal.com](https://github.com/calcom/cal.com/tree/176037d0afbe572f870a3c702985e7cd83fe6c0c) | `176037d` | MIT | 排程以建立、選時段、確認為明確步驟；本系統保留自己的課程、堂次與衝堂規則。 |

補充研究紀錄：[既有 sidebar UX research](../research/2026-08-20-sidebar-ux-research.md)、[sidebar IA/UX restructure](2026-08-20-sidebar-ia-ux-restructure.md)、[平台優化 RFC](../architecture/RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md)。

## v1 實作規格

### 共用流程卡

- 新增 `OperationsQuickStart.vue`，使用既有 design tokens、Material Symbols 與鍵盤可操作的 button。
- 每個流程卡包含編號、圖示、標題、簡短說明與明確動作；目前狀態以 `aria-current="step"` 與視覺邊框同步表達。
- 桌面版三欄、行動版單欄；觸控目標保留足夠高度，並支援 reduced motion。
- 元件只發出流程意圖，不直接呼叫 API；因此入口可測試、可回退，且不會把導覽誤當成寫入成功。

### 主任首頁

- 顯示「收款與核帳」、「新增排課」、「調課／代課」三個高頻入口。
- 帳務入口帶到待對帳／確認入帳視圖；新增排課直接開啟既有快速排課視窗；調課／代課帶到課表並提示先選課卡。

### 帳務中心

- 顯示「查看待處理 → 登記繳費回報 → 確認入帳與收據」三步。
- 仍嚴格區分 `reported` 與 `paid`：繳費回報不等於入帳，正式入帳仍由既有主任確認流程建立 Payment／Invoice／Paid／receipt。參考：[reported-paid split RFC](../architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md)。

### 班級行事曆／課表

- 顯示「新增排課」、「調課」、「換代課」三步。
- 新增排課只開啟既有 `UniversalClassScheduler`；調課／代課只提供操作提示，必須先選取既有課卡。
- 不改動堂次 identity、週曆合併、拖曳調課 resolver、後端原子寫入與衝堂檢查。參考：[session occurrence identity RFC](../architecture/RFC_SCHEDULE_OCCURRENCE_IDENTITY.md)。

## 不在 v1 的範圍

- 不新增資料表、API、付款狀態或權限角色。
- 不把帳務確認改成自動入帳，不自動選擇學生／課程／老師，不自動送出調課。
- 不在這一版重做全站 router、側邊欄或行動底部導航；先用既有 `App.vue` 導覽事件傳遞可驗證的 intent。
- 不宣稱已完成整個產品簡化；v1 是可量測的第一刀，後續仍需依使用數據推進。

## v1.1 調課送出前預覽

### 目的

主任在調課視窗選好新日期／時間後，先看到目前已載入課表的衝堂結果與處理方向，減少按下確認後才收到 409 的往返。預覽是唯讀提示；後端 `reschedule-session` 仍會在原子交易內重新檢查，避免以瀏覽器快取取代權威規則。

### 範圍與安全邊界

- 依老師、目標日期、時間、班型與目前已載入的課程做本地預覽；同一學生與正在移動的課程不重複計算。
- 已知達到一對一、班型容量或老師三人絕對上限時，停用「確認調課」並列出時段／科目摘要。
- 未發現衝堂時明確提示「送出時系統仍會再做最後檢查」；房間容量、跨分校與權限仍由後端最後檢查。
- 不顯示跨分校學生個資，不新增 API、資料表、權限角色或排課 identity 寫入。

### 驗收

- 一對三已有兩位學生可繼續安排；三位學生、一對一或班型已滿時，預覽阻擋並給出改日期／時間建議。
- 未選日期、非重疊時段與同一學生不誤判；調課成功仍走既有原子 API。
- 視窗可及性：預覽使用 `role="status"`，API 錯誤仍使用 `role="alert"`；行動版沿用既有 modal RWD。

### 取捨紀錄（2026-08-26）

- 選擇前端唯讀預覽，而非新增「預檢 API」：本切片不改後端寫入邊界，且不會把預檢結果誤當鎖定；送出時的伺服器檢查仍是唯一準則。
- 保留後端衝堂回應：若其他主任同時修改、課表尚未載入完整，仍以後端結果為準並顯示既有可理解錯誤。

## v1.2 帳務待處理佇列

### 目的

帳務中心開啟時先呈現主任現在需要採取動作的資料，減少先看完已結清／續課提醒再回頭找未繳與待對帳的干擾。所有既有狀態、金額與 API 邊界維持不變。

### 行為與安全邊界

- 預設「待處理」包含 `unpaid`、`partial`、`pending_report`；「全部」仍保留完整提醒清單，逾期、待對帳與續課分類仍可直接切換。
- 待處理清單允許勾選未繳與待對帳，但不同批次語意不能混送；混合勾選只顯示分開處理提示，不會呼叫錯誤 endpoint。
- 批次回報仍只建立 pending report；批次確認仍只確認既有 pending report。`Paid`、Invoice、Payment、收據與催繳列入規則不變。
- 佇列只改前端呈現與選取導引，不新增資料表、API、角色、跨分校範圍或付款判定。

### 驗收

- 首次進入收費頁預選「待處理」，且數字等於未繳／部分付款／待對帳總數。
- 混合選取時提供明確提示；單一狀態選取仍可使用原批次回報／確認流程。
- 無待處理但仍有其他提醒時，可切到「全部」查看；行動版佇列提示可換行且觸控目標不縮小。

## v1.3 流程 telemetry

### 目的與邊界

- 帳務與排課流程記錄開始、完成、返回工作區、錯誤類型與耗時，作為下一輪簡化依據。
- 只使用固定的 workflow／step／result／error_type 枚舉與最多 5 分鐘的耗時；不記錄姓名、學號、課程 ID、金額、備註、電話或錯誤原文。
- 紀錄送出失敗不影響帳務、排課、權限或頁面操作；既有 adoption event API 與後端個資過濾器沿用。

### 驗收

- 帳務批次回報、批次確認與單筆確認可記錄成功、驗證失敗、HTTP 4xx／5xx 與網路錯誤。
- 新增排課與調課可記錄成功／錯誤及返回課表；批次部分成功保留 `partial` 結果分類。
- telemetry helper 只允許 billing／calendar 與四種生命週期事件，耗時固定在 0～300000 毫秒。

## 驗收與上線

- 靜態契約測試確認共用元件的可及性、行動版觸控尺寸，以及三頁的流程意圖。
- 執行前端 targeted Vitest、`lint:no-undef`、production build、`git diff --check` 與 docs integrity。
- PR 必須通過既有 CI；合併後由 deploy workflow 產生正式前端資產，再執行 production health／smoke。
- 回退方式為回退本 PR；因 v1 不含資料庫 migration，回退不需資料修復。

## 後續切片

1. 統一 sidebar／mobile navigation registry，讓頁面入口與權限、badge、deep link 使用同一份定義。
2. 將主任待辦直接帶到指定學生／課程／堂次，並增加「返回工作佇列」脈絡。
3. 帳務建立可排序的 action queue 與批次確認前預覽；排課已加入第一版送出前衝堂預覽，後續再依錯誤類型與完成率補強修正建議。
4. 依 v1.3 telemetry 累積資料，找出實際低完成率或高錯誤率的單一步驟，再提出下一個低風險簡化切片。
