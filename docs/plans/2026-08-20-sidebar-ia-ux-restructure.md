# AllTrue 側欄資訊架構與 UX 重構計畫

> 狀態：Phase 1 實作完成，Phase 2–5 依驗收數據分階段推進
> 
> 日期：2026-08-20
> 
> 配套研究：[`docs/research/2026-08-20-sidebar-ux-research.md`](../research/2026-08-20-sidebar-ux-research.md)

## 1. 一句話版本（ELI5）

現在的側欄像把「今天要做的事、資料櫃、系統設定、帳務工具」全部放進同一個抽屜。這次重構要把抽屜改成有標籤的櫃子：使用者先選工作區，再選頁面；頁面裡的細節留在頁面內。這樣老師找課表、主任找學生、會計找帳務，不會互相猜入口。

## 2. 產品決策

### 2.1 暫定主導航

主任：

| 導航區段 | 頁面 | 為什麼放這裡 |
|---|---|---|
| 今日工作 | 主任工作台、通知、待處理問題 | 回答「現在最該處理什麼」 |
| 教學現場 | 班級行事曆、出缺勤、學習評量、課表異常 | 日期、單堂課與教學品質 |
| 學生與課程 | 學生管理、課程查找／課程總覽 | 學生是主資料；課程是其下的操作 lens |
| 財務與人事 | 收費、收費報表、薪資、教師資格 | 金額、付款與教職管理 |
| 設定與資源 | 老師、教室、科目、分校／系統設定 | 低頻管理，不污染每日工作 |

聊天、問題回報、說明、版本更新、主題、帳戶、分校切換屬於 utility，不與每日工作頁面混在一起。老師則只顯示「今日工作、教學現場、我的學生／課程、訊息與回報」中後端授權可見的部分。

### 2.2 三個容易混淆的入口

| 使用者問題 | 正確入口 | 導航上的語意 |
|---|---|---|
| 我想找某個學生、看他的課程與歷史 | 學生管理 | 學生與課程 → 學生管理 |
| 我想找很多課、看繳費／堂次、做跨期轉移 | 課程管理 | 學生與課程 → 課程查找／課程總覽 |
| 我想看哪天上課、請假、調課、代課 | 行事曆 | 教學現場 → 班級行事曆／我的課表 |
| 我想把已完成評量帶到新一期 | 學生管理的續課流程，或課程查找中的批次動作 | 不從行事曆發起；系統先搜尋目標課程，不要求手打 ID |

「課程管理」不是現在就廢掉，而是先降級為清楚命名的課程查找／批次操作 lens。等學生主資料入口已能覆蓋實際工作、使用數據證明沒有人需要獨立課程 lens，再另開決策把它合併或移除。

## 3. 目標介面模型

```text
AllTrue / 目前分校
├─ 今日工作
│  ├─ 工作台
│  └─ 通知／待處理
├─ 教學現場
│  ├─ 班級行事曆／我的課表
│  ├─ 出缺勤
│  ├─ 學習評量
│  └─ 課表異常
├─ 學生與課程
│  ├─ 學生管理（主資料）
│  └─ 課程查找（批次／轉移／帳務 lens）
├─ 財務與人事
└─ 設定與資源

頁面內：tabs、filter、drawer、modal
```

限制：側欄只允許「區段 → 頁面」兩層；不能出現「學生 → 課程 → 評量 → 某堂」的第三層。第三層內容由頁面內 context 顯示，並能用 URL／query 保留目前狀態。

## 4. 工程設計

### 4.1 Navigation registry

新增 AllTrue 自有的集中式 registry（名稱可在實作 PR 決定），每個項目至少有：

```js
{
  id: 'students',
  label: '學生管理',
  icon: 'groups',
  group: 'students-courses',
  roles: ['director'],
  route: 'students',
  priority: 20,
  badge: { type: 'none' }
}
```

registry 只負責顯示與導覽；後端 API 的 permission、branch scope、資料查詢仍是安全邊界。每個 renderer 只消費同一份 registry：

- desktop expanded sidebar；
- desktop collapsed icon rail；
- tablet／mobile drawer；
- mobile bottom high-frequency tabs；
- More sheet。

保留目前 `page` key 作為相容層，先讓 registry route 指向現有 `setActivePage`；不在第一階段重寫所有頁面或一次導入 Vue Router。

### 4.2 Active state 與深連結

第一階段先建立 `page id → active` 的單一 resolver，補上 `aria-current`、群組 `aria-expanded`、focus-visible 與測試標記。第二階段把目前 page id 寫入可向後相容的 URL hash 或 query，讓重新整理、瀏覽器返回與複製連結可用；再依實際頁面拆分評估是否引入輕量 router。

成功標準不是「有 router」，而是使用者可以：

1. 重新整理後回到同一工作頁。
2. 從通知或課程動作直接抵達正確頁面／記錄。
3. 返回上一頁不會被送回儀表板。

### 4.3 Responsive shell

| 寬度 | 行為 |
|---|---|
| ≥1200 | 約 264–280px 固定側欄；可收合為有名稱的 icon rail。 |
| 768–1199 | 預設收起；按鈕開啟 modal drawer，帶 scrim、Escape、focus return。 |
| ≤767 | 只保留 3–5 個高頻底部入口；其他入口進同一個 More drawer。底部導航與 drawer 不能各自維護一份頁面清單。 |
| 390／412 | 所有主要操作不被截斷、不產生水平捲軸；critical control 觸控區至少 44px。 |

桌面收合只改視覺寬度，不改使用者的工作情境；icon-only 控制必須有 `aria-label`、tooltip 與明確 focus ring。手機 drawer 開啟時鎖定背景滾動，關閉後把焦點送回觸發按鈕。

## 5. 分階段執行

### Phase 0：決策與量測（docs／無產品行為變更）

交付：角色 × 頁面矩陣、導航命名表、目前頁面互相連結圖、390／412／768／1280／1440 截圖基準、badge 語意表、可回滾方案。

驗收：主任與老師各走完「登入 → 今日工作 → 學生／課程 → 教學」最短路徑；列出每一個仍有歧義的入口。若 Backer Web 要深入比較，使用測試帳號重新在可控環境驗證，不把公開 bundle 當登入後證據。

風險：低。回滾：刪除文件，不改 production。

### Phase 1：單一 registry 與無障礙底座（T1）— 已完成（2026-08-26）

交付：集中式 registry、共用 renderer、桌面／手機／More 使用同一語意資料、`aria-current`、`aria-expanded`、icon accessible name、鍵盤與 focus 測試。

保留：原有 page keys、role 可見性、branch switch、底部 nav 的使用習慣。

驗收：頁面清單只存在一份；每個角色只能看到授權項目；active 項在桌面、收合、手機一致；沒有水平捲軸；現有 UI Smoke 與課程／行事曆 smoke 全綠。

回滾：回退本次前端 PR；registry 不改後端資料。已驗證桌面側欄、手機底部與 More renderer 共用角色導覽資料，保留舊 page key 與 badge 語意。

### Phase 2：視覺與外殼（T1）

交付：用 AllTrue `--ds-*` token 重整側欄 surface、區段標題、目前分校／角色 context、utility footer、drawer scrim；保留品牌 amber／navy，避免 ops 頁 gradient mesh 與裝飾性圖形。

驗收：五個規範寬度目視檢查；高頻入口一眼可見；低頻設定不搶每日工作；深色／淺色模式仍可讀；focus ring、reduced motion、對比與觸控區合格。

回滾：只回退 shell CSS／元件，不動頁面資料流程。

### Phase 3：資訊架構與深連結（T2）

交付：採用「今日工作、教學現場、學生與課程、財務與人事、設定與資源」命名；把學生／課程／行事曆的責任寫進頁面 header 與空狀態；加入 URL-compatible page state、通知 deep link、頁內 tabs。

驗收：十位內部使用者做任務測試；記錄第一次點擊成功率、回頭率、找不到入口的原因；角色／分校隔離不變。未通過時只調整 label／group，不改資料模型。

回滾：registry 以舊 label/group map；URL 解析保留舊 page key。

### Phase 4：教師續課與堂次轉移 UX（T2）

交付：在學生／課程流程中：

1. 建立新一期課程或選擇既有目標課程。
2. 以學生、老師、分校、期間搜尋可用目標課程，不要求輸入 raw ID。
3. 顯示來源課程與目標課程的結算／分校／學生一致性檢查。
4. 已完成的 8/02、8/09、8/16 等堂次預選，逐堂顯示評量、簽到、堂次狀態。
5. 明確說明「移動紀錄，不改原課程結算金額與已扣堂數」；確認前提供摘要，完成後提供可追蹤結果。

後端沿用現有 transfer API 與交易／權限檢查；此階段只改善選擇、預覽、錯誤訊息與成功回饋，除非測試證明資料契約不足。

驗收：不重填已完成評量；來源／目標學生不同時不能提交；已結算來源課程依後端規則阻擋；partial failure 不可顯示假成功；主任能從學生或課程 lens 完成，不必去行事曆猜入口。

回滾：保留現有 transfer modal 作為 fallback；不刪除 API。

### Phase 5：觀察後的合併決策（T2）

至少觀察一個完整結算週期後，才決定「課程管理」是否能移除。判斷資料包括：課程查找使用量、跨期轉移成功率、從學生頁進入課程的比例、主任任務測試結果、客服／回報中的找不到入口比例。

若學生管理已能承接全部高頻工作，才提出獨立的移除／合併 PR；在那之前，課程管理保留為 read-only／批次 triage lens。

## 6. 驗收與品質紅線

### 功能

- `director`、`teacher`、`super_admin` 的可見項目與後端 permission matrix 對得上。
- 分校切換後，側欄 badge、頁面資料與 active context 不混校。
- 桌面、收合、平板 drawer、手機底部與 More 的 active state 只有一個真相。
- 現有行事曆的 occurrence resolver、出缺勤、評量與課程轉移 API 不被側欄重構繞過。
- 新增／刪除／重命名導航項目都有 migration map 與舊 page key 相容策略。

### UX／無障礙

- 鍵盤可從品牌區走到每個主要項目；Enter／Space 可開合群組；Escape 可關閉 mobile drawer。
- 選中項有 `aria-current="page"`；開合群組有 `aria-expanded`；icon-only control 有可存取名稱。
- 不以色彩單獨表達 active、付款、通知或錯誤；badge 有文字／tooltip 定義。
- 390、412、768、1280、1440 寬度沒有非預期水平捲軸。
- 尊重 `prefers-reduced-motion`；對比、focus ring、觸控區符合現有 AllTrue design QA。

### 可觀察性

只記錄不含 PII 的事件：`nav_open`、`nav_select`、`nav_backtrack`、`nav_search`、`course_transfer_start`、`course_transfer_success`、`course_transfer_error`，包含 role、branch scope 的匿名化類別、page id 與 error code，不記學生姓名、電話、評量內容或帳務明細。

初期關注：第一次點擊成功率、從錯誤入口回頭比例、任務完成時間、mobile drawer 開啟後選擇率、堂次轉移失敗原因。這些是判斷「好不好用」的證據，不用漂亮截圖代替。

## 7. 風險與防護

| 風險 | 防護 | 回滾 |
|---|---|---|
| registry 漏掉頁面或破壞 page key | 先做 registry snapshot 與 mapping test | feature flag 回舊 renderer |
| 前端顯示了未授權項目 | 後端 permission 仍為唯一安全邊界；前端做 role matrix test | 隱藏新項目，保留 API policy |
| 手機使用者找不到低頻功能 | 讓 More drawer 與搜尋入口可見；任務測試驗證 | 還原 More 排序，不改資料 |
| 課程、學生、行事曆責任再次混淆 | 導航 label、page header、empty state 使用同一詞彙表 | 只回退文案／分組 |
| 側欄視覺改壞整站品牌 | 只用既有 tokens；先做 shell pilot；截圖基準與 CI | 回退 shell CSS |
| deep link 讓舊書籤失效 | 保留舊 page key／hash map；加 direct-entry smoke | 解析舊格式 |
| badge 或分校 context 顯示過時 | 明確 loading／error／empty 狀態，事件只做觀察不作權限 | 關閉 badge，回到文字導航 |

## 8. 目前不需要 Founder 先決定的事

- 是否安裝 Vue Router：先完成單一 registry 與 page-id resolver，再以 deep-link 需求決定。
- 是否完全移除課程管理：等 Phase 5 的實際數據與任務測試。
- 是否加入 command palette：列為 Phase 3 的可選增量，不阻擋側欄底座。
- 是否採用 Backer Web 登入後的更多細節：目前只有公開 shell／bundle 證據，需正式測試再決定。

## 9. 需要產品確認的問題

1. 主任一天最常做的前三件事，是否真的是「看待辦、找學生／課程、處理行事曆例外」？若不是，Phase 0 先調整排序。
2. 老師是否需要看到「學生管理」的個人化版本，還是所有老師入口都從「我的課表」開始？
3. 「課程查找」這個名稱是否比「課程管理」更符合主任語言；若不符合，候選名稱是「課程總覽」或「課程帳務」。
4. 分校切換是每日高頻操作還是登入後固定一次？這決定它放在品牌區還是 utility footer。
