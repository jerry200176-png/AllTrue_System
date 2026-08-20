# AllTrue 側欄與工作情境 UX 研究

> 研究日期：2026-08-20
> 
> 目的：為 AllTrue 的側欄重構建立可追溯的資訊架構、互動與響應式行為依據。
> 
> 範圍：主任、老師、分校切換、學生／課程、行事曆、行動版導航。

## 1. 研究規則

本文件只記錄能回到來源的觀察，以及它們對 AllTrue 的取捨。外部產品的品牌顏色、文案與程式碼不直接搬入 AllTrue；只取可驗證的結構與互動模式。

證據分四層：

1. AllTrue 現有程式、設計規範與測試。
2. 官方設計系統與產品文件。
3. 維護中的開源專案原始碼與測試。
4. Backer Web 的公開 shell 與靜態 bundle；登入後流程未宣稱已驗證。

## 2. AllTrue 現況盤點

### 2.1 實際結構

`frontend/src/App.vue` 目前是單一 app shell，沒有 Vue Router；`active` ref 決定要渲染哪個頁面。側欄與手機版下方導航各自從 `sidebarNavGroups` 再整理一次，存在兩份導覽呈現邏輯。

主任目前約有五組：

| 組別 | 內容 | 使用者要完成的工作 |
|---|---|---|
| 總覽 | 主任儀表板、通知、聊天、問題回報 | 看待辦、處理例外 |
| 教學 | 班級行事曆／課表、課程管理、出缺勤、課表異常、學習評量、重複審核 | 讓課程正常發生、檢查教學紀錄 |
| 人員 | 學生管理、老師管理 | 維護人與關係 |
| 財務 | 收費、報表、科目單位、薪資、教師資格 | 對帳與付款 |
| 設定 | 教室、科目、LINE、綁定、健康、主任帳號、夜間對帳 | 低頻管理 |

老師則把課表／評量、行事曆、科目、聊天、問題回報混在單一教學組。角色與分校已由前端組合選單，但後端權限仍是最後一道真相，前端不能視為授權邊界。

### 2.2 目前的 UX 成本

- 「學生管理」是學生與課程的長期主資料入口；「課程管理」同時又像課程主資料入口，使用者難以預測要去哪裡。
- 行事曆負責單堂課日期、出席與調課；課程管理負責合約、堂數、繳費與跨期轉移。兩者的動作邊界沒有在導航層說清楚。
- 桌面收合後只剩圖示，模板目前沒有明確的 `aria-current` 與 `aria-expanded` 契約；`title` 不能完全取代螢幕閱讀器名稱與鍵盤焦點語意。
- 行動版另外維護五個底部 tab 加 More sheet，與桌面側欄重複宣告頁面清單，未來每加一頁就可能只改到其中一邊。
- `active` 沒有 URL 路徑真相，因此不能可靠地書籤、重新整理後回到同一頁，冷啟動深連結也沒有明確契約。
- 分校、帳戶、主題、快捷鍵都擠在側欄底部；高頻工作與低頻系統工具沒有分層。
- 數字 badge 沒有統一說明是待辦、未繳費、通知還是資料筆數；數字看起來重要，但使用者不知道下一步。
- 現行視覺規範要求 ops 頁淺色優先、低裝飾、使用 `--ds-*` token；側欄目前仍是獨立的深色漸層視覺，重構時要保留品牌辨識但不要把行銷頁裝飾帶進工作台。

### 2.3 現有資料與功能邊界

教師換期問題已有後端能力：`POST /api/v1/student-classes/{studentClass}/transfer-sessions` 會在交易內把選定堂次、學習紀錄與簽到紀錄移到同一學生的新課程，並保留堂數、金額與結算欄位。現有前端 `TransferSessionsModal` 卻要求輸入目標課程 ID，且只顯示日期／狀態，這是 UX 問題，不是應先重做資料模型的理由。

因此本計畫把「續課／轉移已完成評量」列為學生與課程工作流的後續切片；不把課程轉移動作塞進行事曆，也不在本計畫第一階段刪除課程管理。

## 3. 外部證據

### 3.1 官方設計系統與大公司產品

| 來源 | 可驗證觀察 | AllTrue 採用 | 不採用／限制 |
|---|---|---|---|
| [GitLab Pajamas Navigation Sidebar](https://design.gitlab.com/patterns/navigation-sidebar/) | 桌面持續顯示；較窄螢幕改 overlay；支援情境式導航；最多兩層；可釘選；使用者偏好可保存。 | 桌面側欄、平板 drawer、最多兩層、保留收合偏好。 | 不照搬 GitLab 紫色品牌或三層樹狀選單。 |
| [GitLab Pajamas Nav Item](https://design.gitlab.com/components/nav-item/) | 選中項使用 `aria-current="page"`；父層使用 `aria-expanded`；圖示按鈕要有可存取名稱。 | 明確的選中、展開、圖示名稱契約。 | 不以瀏覽器 `title` 當唯一無障礙方案。 |
| [GitLab Pajamas Tabs](https://design.gitlab.com/components/tabs/) | Tabs 保持同一情境；側欄是改變情境的導航。 | 課程詳情內的分頁留在頁面內，不再塞成側欄第三層。 | 不把每個細節操作升成全域頁面。 |
| [Atlassian 導航設計](https://www.atlassian.com/blog/how-we-build/designing-atlassians-new-navigation) | 導航重點是可預測、熟悉、使用者控制、漸進揭露；側欄提供縱向密度與鳥瞰。 | 分組、可收合、清楚當前情境、持續偏好。 | 不導入 Atlassian 的產品品牌或複雜多產品切換。 |
| [Atlassian Navigation Reference](https://support.atlassian.com/navigation/docs/navigation-reference-guide/) | 全域側欄與產品／專案情境側欄分開；內容會隨情境變化。 | 把分校／角色當作工作情境，讓導覽由 registry 產生。 | AllTrue 目前不需要多產品 workspace 樹。 |
| [IBM Carbon UI shell left panel](https://carbondesignsystem.com/components/UI-shell-left-panel/usage/) | 左側面板適合超過五個次要目的地或頻繁切換；超過兩層應另用 tree；更多內容可用 page tabs。 | 目前頁面數量足以保留側欄，但限制階層，細節放頁內。 | 不安裝 Carbon runtime 或採 Carbon 藍色皮膚。 |
| [IBM Carbon Menu](https://carbondesignsystem.com/components/menu/usage/) | Menu 適合低頻、進階動作，避免承擔複雜輸入流程。 | 帳戶、主題、說明、登出等工具移至 utility menu。 | 課程轉移不能藏進多層 menu；它需要完整流程頁／modal。 |
| [Material Navigation Drawer](https://m2.material.io/components/navigation-drawer) | 五個以上頂層目的地、兩層以上階層或不相關目的地可用 drawer；小螢幕用 modal drawer 並管理 focus／scrim。 | 手機用 drawer + scrim + Escape／焦點回復。 | 不同時堆 permanent rail 與 bottom navigation。 |
| [Material Navigation Rail](https://m2.material.io/components/navigation-rail) | rail 適合 3–7 個頂層目的地；次要目的地放 modal drawer；不建議與 bottom navigation 同時永久顯示。 | 收合桌面改為有名稱的 icon rail；手機保留一套主導航來源。 | 不把所有桌面頁壓縮成手機底部五格。 |
| [Salesforce Lightning vertical navigation](https://developer.salesforce.com/docs/platform/lightning-component-reference/guide/lightning-vertical-navigation.html) | 一層連結與可收合 overflow section；超過一層應用 tree；鍵盤 Enter／Space 可展開收合。 | 群組標題的 disclosure 行為與鍵盤規則。 | 不把多校區資料權限交給前端顯示控制。 |

### 3.2 開源實作與測試

| 專案 | 版本／檔案觀察 | AllTrue 採用 |
|---|---|---|
| [Frappe Framework sidebar](https://github.com/frappe/frappe/blob/develop/frappe/public/js/frappe/ui/sidebar/sidebar.js) | 目前 `develop` 分支的 sidebar 會由 boot config 取得項目，依 workspace／app context 解析，處理目前 route 與直接進入深連結。相關 Cypress sidebar 測試也在同一專案。 | 以集中式 navigation registry 產生不同殼層；逐步補上深連結，不先大爆炸導入 router。 |
| [Gibbon navigation template](https://github.com/GibbonEdu/core/blob/v31.0.00/resources/templates/navigation.twig.html) | 目前 tag v31 的導航依 module／category／角色情境生成；桌面側欄與手機選單都突出目前項目，並維持單一情境模組選單。 | 角色與模組做為導航可見性的資料來源；手機與桌面共用同一份語意模型。 |
| [Moodle drawer template](https://github.com/moodle/moodle/blob/main/public/lib/templates/drawer.mustache) | 目前 main 的通用 drawer template 明確處理隱藏狀態、`aria-hidden`、region role 與 tabindex；導航相關 Behat 測試驗證課程／參與者節點可展開與可見。 | drawer 的 visibility／focus 契約，以及把導航行為寫成測試而不是只截圖。 |

### 3.3 Backer Web（自家產品）

2026-08-20 透過 Cloudflare Browser Run 讀取公開網址，HTTP 200，公開 shell 顯示 `Backer Web` 與載入狀態；未使用帳號密碼，也未宣稱已驗證登入後頁面。對公開 JavaScript bundle 的靜態觀察如下：

- `DashboardLayout` 有 desktop collapsed state 與 mobile drawer state，並在 route 變更後關閉 mobile sidebar。
- 公開 bundle 的導航字串以「系統首頁、排課系統、點名管理、薪資計算、學生管理、成績管理、師資管理、教室配置、資料中心、操作教學」為主，且附角色可見性。
- desktop sidebar 約 270px，收合約 80px；mobile 是約 280px 的 off-canvas drawer，有 scrim 與 sticky header；分校名稱在 shell 中可見。

可借鑑的是：工作模組命名直接、分校 context 在 shell 內、手機 drawer 是真正的 drawer。不能借鑑的部分是未經登入驗證的實際權限、資料狀態與操作流程；那些需在測試帳號與不含真實資料的環境另行驗證。

## 4. 研究收斂

### 必須做

1. 一份 navigation registry 同時產生桌面、收合、平板 drawer、手機 More；不再維護兩套頁面清單。
2. 導航最多兩層：工作區段 → 頁面；課程詳情、評量明細、付款明細使用頁內 tabs／filters。
3. 每個頁面有穩定的 page id、顯示名稱、角色、分校能力、badge 語意與 active rule。
4. 選中項標示 `aria-current="page"`；可展開父層標示 `aria-expanded`；icon-only control 有 `aria-label`；mobile drawer 有 scrim、Escape、focus return。
5. 優先修正名稱與工作邊界：學生管理是主資料，課程管理是查找／批次課程 lens，行事曆是日期／單堂課工作台。
6. 以 AllTrue 現有 `--ds-*` token 重整外殼，保留品牌 amber／navy，不導入外部 UI runtime。

### 不應該做

- 不因為側欄重構就刪除課程管理。
- 不把合約續課、堂次轉移、結算重開放到行事曆。
- 不在第一刀導入完整 router、全站 UI framework 或大規模重寫 App.vue。
- 不把所有頁面都塞進手機底部五個 tab；底部只放高頻入口，其餘進同一個 More drawer。
- 不用顏色或 badge 數字單獨表達權限、付款狀態或緊急程度。

