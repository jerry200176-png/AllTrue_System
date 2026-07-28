# PRD：家長留言任務收件匣（awaiting_staff_reply）

> **狀態**：Draft v1.1 — PLAN revision（blocker 封口）；**暫不批准進 DEV** 直至 Founder／CEO 明確批准  
> **Risk Tier**：T2（產品工作流／前後端契約；含多校區 scope 與家長可見回覆邊界）  
> **心智模型**：任務收件匣＋課堂脈絡（不是完整聊天系統）  
> **關聯 PR**：#1471（PLAN-only；無 production code）

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 家長留言任務收件匣（awaiting staff reply） |
| 版本 | v1.1（相對 v1.0：RACI／stage gates、P0 角色、FR-002 家長事件語意封口） |
| 日期 | 2026-07-28 |
| 狀態 | Draft — 等 Founder／CEO 批准 PRD 後才可進 ARCH／DEV |
| 目標角色（P0） | **僅** `teacher`（primary）、`director`（secondary） |
| 明確排除（P0） | `super_admin` 不作為本功能 inbox／count 的既定使用者；不擴張既有 feedback route 權限 |
| 關聯 | 既有 System A `learning_record_feedbacks` 雙向對話；TD-057 KPI 失真（本 PRD **不改**舊 analytics contract）；歷史找得到性修復 #138/#139／CHANGELOG 2026-05-31 雙向對話 |

---

## 1b. Stage Gates（批准邊界 — 強制）

| Gate | 授權內容 | Accountable（A） | Agent 可否自行通過 |
|---|---|---|---|
| **G0 — PRD approval** | 僅授權進 **ARCH／DEV**：寫 code、跑測試、開 **implementation PR** | Founder／CEO | 否 |
| **G1 — Implementation PR** | 開 PR、推 branch、等 CI；**不**含 merge | Founder／CEO（產品／範圍）+ Agent（執行） | Agent 可開 PR；**不可 merge** |
| **G2 — Merge to main** | 合併 implementation PR。若 merge 會觸發 `deploy.yml`／production deploy，**merge 本身即視為 production activation** | Founder／CEO | **否** — 必須另一次人類批准 |
| **G3 — Schema／資料變更** | 任何 migration、backfill、production data repair | Founder／CEO | **否** — 先提方案，停下等批准後才能做 |
| **G4 — Production activation** | 實際上線生效（含自動 deploy 成功後的「已上線」宣告） | Founder／CEO | **否** — Agent 只可蒐證（CI／deploy log／health），不可自行批准 |

**本次若批准本 PRD（G0）**：只授權 ARCH／DEV、寫 code、測試、開 implementation PR。  
**不授權**：merge、deploy、migration、backfill、production data repair、宣告已上線。

Agent 負責執行與蒐證；**不得**自行批准 G0–G4。

---

## 2. 目標與業務背景

### 痛點（非技術語言）
老師想「回覆家長」，但系統把入口放在「學習評量」資料結構裡：入口跟「未讀」綁死會消失、回覆藏在評量表單深處、跨分校時以為沒留言。結果是家長留言沒人回，老師卻覺得「系統沒有這個功能」。

### 業務價值
- 把「家長有留言要回」變成可找到、可清完的工作清單。
- 未讀與「尚未回覆」分開，避免「看過＝處理完」的假完成。
- 維持課堂脈絡（學生／日期／科目／分校），回覆仍附著於該堂評量，不承諾即時客服。

### 可量化 KPI

| KPI | 定義 | 目標（上線後 14 天觀察；上線本身需 G2／G4） |
|---|---|---|
| 入口到達率 | 老師點首頁「家長留言」進入 inbox 的次數／有 awaiting 老師日活 | 基準建立後追蹤（P1 telemetry） |
| 待回覆清償 | `awaiting_reply_count` 日終相對日初下降、無異常歸零 | 不因「只讀不回」或相同內容重送而歸零／假重開 |
| Count／List 一致 | 首頁 count 與點入預設列表筆數一致（同 scope） | 100%（CI + 手動 AC） |
| 誤用舊 KPI | 新 UI／新 API **不得**讀寫 `analytics.unreplied_records` 當待回覆 | 靜態審查通過 |
| Idempotent upsert | 正規化後相同內容的 parent upsert 不重開 awaiting、不重設 unread、不重送通知 | 100%（Feature Test） |

---

## 3. 範圍

### In Scope（P0）
- **語意**：`awaiting_staff_reply`（正式定義見 FR-001／FR-002），與 unread 分離。
- **後端**：authoritative count + filter／窄版 inbox query；**teacher／director** 權限與分校 scope；家長追問／實際修改原留言後重進 awaiting；員工公開回覆後退出；相同內容 upsert idempotent；內部評語與 mark-read 不影響 awaiting。
- **前端**：TeacherHome 固定「家長留言」卡片（新留言／尚未回覆分開）；CTA 落地同一 inbox mode；LearningRecordsPage 一級「家長留言」Tab；預設「尚未回覆」；現有 modal「回覆模式」；內外訊息分區；空狀態與 count/list mismatch。
- **文案**：對外改用「家長留言／回覆家長／新留言／尚未回覆」；禁止 Feedback／未讀回饋當主文案。
- **禁止沿用** `analytics.unreplied_records` 作為本功能狀態來源（該欄位保留原行為，TD-057 另案）。

### Out of Scope
- **`super_admin` 作為本功能 P0 使用者／count scope／inbox 承諾**（future decision；需獨立產品＋資安批准；本功能**不得**順便放寬既有 `role:teacher,director` feedback routes）。
- 完整即時聊天（WebSocket、已送達／已讀回條給家長、附件、表情、跨堂自由開對話、訊息搜尋平台）。
- 新建第二套 messages 真相來源／雙寫。
- 側欄獨立「家長留言」（P1）、通知鈴 deep link（P1）、dedicated drawer／inline quick reply／funnel telemetry dashboard（P1/P2）。
- 修改 `analytics.unreplied_records` / Adoption KPI 語意（TD-057）。
- 繳費、扣堂、排課、請假補課、家長可見性 domain 語意變更。
- Bug 回報／請假／繳費等其他待辦混入同一 inbox。
- System B `parent-feedback/*` 意見箱整併。
- **未另經 G2／G3／G4 批准的** merge、deploy、migration、backfill、production data repair。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I | 說明 |
|---|---|---|---|
| **Founder／CEO** | 人類決策者 | **A** | 產品定義、**PRD（G0）批准**、**merge（G2）**、**migration／backfill／data repair（G3）**、**production activation（G4）** 的唯一 Accountable |
| AI Agent（實作） | `[FEATURE]` | R | 寫 code、開 implementation PR；不可 merge／deploy |
| AI Agent（測試） | `[TEST]` | R | 測試與蒐證 |
| AI Agent（資安／Code Review） | `[REVIEW]` | R | 審查建議；不可代替 CEO 批准 gate |
| AI Agent（文件） | `[DOCS]` | R | CHANGELOG／回歸筆記 |
| AI Agent（部署蒐證） | `[OPS]` | R | **僅**在人類批准 G2／G4 之後監控 deploy／health 並回報證據；不可自行批准上線 |
| 老師代表／使用者回饋 | 人類 | I／C | 可提供 UX 回饋；不取代 CEO 的 A |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR／功能 | System A 雙向回覆（`learning_record_feedback_replies`、staff/parent reply API）已上線 | 已完成 |
| 既有路由權限 | feedback 相關員工路由位於 `role:teacher,director`（及既有 middleware）；**P0 不擴張至 super_admin** | 必須維持 |
| 既有未讀 | `me/unread-feedback-count`、`feedback=has\|unread`、主任 feedback focus | 已完成；本功能並存不取代 |
| 家長 upsert 現況 | 家長可編輯草稿並再次 upsert；後端 `updateOrCreate` 目前可能對相同內容仍重設未讀／通知（假事件）— DEV 必須改為 idempotent | 已知缺口 |
| 外部服務 | 無（不依賴 LINE 推播開關） | 無外部依賴 |
| 環境／資料 | `learning_record_feedbacks` + `learning_record_feedback_replies` + 既有 teacher ownership／campus middleware | 已存在 |
| 已知語意陷阱 | 現有 `analytics.unreplied_records` =「已核准評量中家長尚未留言」≠ 本 PRD awaiting | 必須隔離 |

---

## 5. User Stories + AC

### US-01：老師從首頁找到並回覆
**As a** 老師，**I want** 登入後首屏就看到固定的「家長留言」入口，**so that** 我不需要猜要去學習評量哪個篩選。

- AC-01a：首屏不捲動可見「家長留言」卡片（即使 awaiting=0 且 unread=0 仍存在）。
- AC-01b：有 awaiting 時顯示「N 則尚未回覆」；有 unread 時另顯示「其中 M 則是新留言」或等價分開數字；主 CTA 文案為「查看並回覆」或「繼續回覆」，**不得**為「前往學習評量」。
- AC-01c：點 CTA 後進入 inbox 且預設「尚未回覆」；最多再點一次即可 focus「回覆家長」輸入框。

### US-02：讀過但還沒回仍在清單
**As a** 老師，**I want** 看過留言但還沒回覆時工作仍留在清單，**so that** 「已讀」不會假裝「已處理」。

- AC-02a：老師 mark-read 後 unread 可為 0，但該筆若仍 awaiting 則首頁 awaiting count ≥ 1 且 inbox「尚未回覆」仍列出。
- AC-02b：員工送出家長可見回覆後，該筆退出 awaiting；仍可在「全部」看到。

### US-03：家長追問／修改原留言重新待回；相同內容重送不重開
**As a** 老師，**I want** 家長真的有新話要說時案件再進待回覆，但誤觸重送相同內容時不要假警報，**so that** 清單可信。

- AC-03a：家長追加追問 reply 後 → 重新 `awaiting_staff_reply`，且可觸發新留言（unread）語意。
- AC-03b：家長**實際修改**原留言內容（正規化後與儲存內容不同）→ 視為新的 parent public event → 重新 awaiting，且可重設 unread／允許通知（依既有推播政策）。
- AC-03c：家長 upsert **正規化後內容完全相同** → **idempotent no-op**：不更新事件時間、不重設 unread、不重開 awaiting、不重送通知；HTTP 成功但不產生新事件副作用。
- AC-03d：主任或老師公開回覆 → 解除對「當前最新 parent public event」的 awaiting（退出 awaiting，直到下一次新的 parent public event）。
- AC-03e：僅 mark-read 或僅主任內部評語 → awaiting 不變。

### US-04：跨分校不誤導
**As a** 跨分校授課老師，**I want** 預設看到我所有分校的待回覆，**so that** 不會因 UI 目前分校選錯以為沒留言。

- AC-04a：老師首頁 count scope = 老師所有有權限且授課相關分校（與點入預設「我的所有分校」一致）。
- AC-04b：inbox 頁首明示「查看範圍：我的所有分校」；可再篩單一分校。

### US-05：主任以目前分校為責任邊界
**As a** 主任，**I want** 預設只看目前分校的家長留言，**so that** inbox 不會跨校爆炸。

- AC-05a：主任首頁 count = 目前分校；點入預設同範圍。
- AC-05b：頁面提供一級可見的「查看我有權限的所有分校」切換（不藏進階篩選）。

### US-06：回覆時不誤改評量／不誤送內部評語
**As a** 老師，**I want** 回覆畫面清楚是「回覆家長」，**so that** 我不會以為在編輯評量，也不會把內部評語當成家長回覆。

- AC-06a：回覆模式主標題／textarea 標籤／送出按鈕皆含「家長」或「回覆家長」。
- AC-06b：「主任內部評語」區明示「僅教職員看得到，家長不會看到」，且不與「送出回覆」共用同一按鈕。
- AC-06c：完整評量僅次要連結「查看完整學習評量」；主 CTA 不得為「儲存評量」。

### US-07：Count／List 一致與錯誤可見
**As a** 老師，**I want** 首頁有數字時進去看得到對應案件，**so that** 我信任系統。

- AC-07a：同角色、同 scope 下，首頁 awaiting count 與 inbox「尚未回覆」列表總數一致（允許分頁，但 total 必須一致）。
- AC-07b：若 count>0 且列表為空，顯示 mismatch 說明＋目前範圍＋「重新載入／清除全部篩選」；不得靜默把首頁 count 清零。

---

## 5b. UI/UX 精緻化

沿用 `docs/RULE_DESIGN_SYSTEM.md`（淺色 ops、品牌橘黃主 CTA、tabular 數字）。禁止另造紫色 SaaS／深色炫光風。

### TeacherHome「家長留言」卡片

| 面向 | 規格 |
|---|---|
| 版面層次 | 標題「家長留言」為卡片主標；數字列次之；單一主 CTA。固定存在於今日待辦區首屏。 |
| 色彩 | 新留言數用 `--ds-danger` 或既有紅點語意；尚未回覆用 `--ds-warning`／品牌暖色強調；無待辦時中性 ink。 |
| 互動 | CTA hover／active 符合既有 action 卡；loading 時數字 skeleton，避免整卡消失。 |
| 空狀態 | awaiting=0 且歷史可能存在：「目前沒有尚未回覆的留言」＋「查看歷史留言」；從未有留言：「目前還沒有家長留言」。 |
| 防呆 | 禁止 `v-if="unread > 0"` 整卡消失。 |
| 響應式 | 觸控目標 ≥ 44px；手機首屏仍可見。 |
| 無障礙 | CTA 有明確可讀名稱；數字 `aria-label` 區分新留言／尚未回覆。 |

### LearningRecordsPage「家長留言」Inbox mode

| 面向 | 規格 |
|---|---|
| 版面 | 一級 Tab：`學習評量 \| 家長留言`。Inbox 內 chip：`尚未回覆 \| 新留言 \| 全部`。頁首範圍列永遠可見。 |
| 列表卡 | 學生／家長、日期、科目、老師、分校、最新留言摘要、狀態 chip、唯一主按鈕「回覆家長」。 |
| 空狀態 | F1 從未留言／F2 篩選過窄／F3 count mismatch — 皆有圖示＋說明＋CTA；禁止空白表或「查無資料」。 |
| Loading | 列表 skeleton；切換 chip 時不閃空。 |
| 響應式 | 手機全寬列表；回覆用全螢幕或既有 modal 回覆模式。 |

### Modal 回覆模式

| 面向 | 規格 |
|---|---|
| 版面 | 上方「回覆〔學生〕家長」＋課堂摘要；留言對話；「家長看得到」回覆區。評量編輯欄預設收合或唯讀。 |
| 分區 | 家長可見區與「主任內部評語」不同底色／邊框／標題；禁止同色同 icon 同按鈕。 |
| 互動 | 開啟後自動 scroll＋focus textarea；送出中 disable＋spinner；成功後 inline／toast「已回覆家長」。 |
| 防呆 | 主 CTA 僅「送出回覆」；內部評語另鍵。 |

---

## 6. 功能需求 FR

### FR-001 — awaiting_staff_reply 語意（權威）
系統必須以後端計算一筆課堂家長留言對話是否為 `awaiting_staff_reply`，定義為：

> 該堂課存在至少一則家長對外留言（parent public event），且**最新一則 parent public event** 之後，沒有任何 `teacher` 或 `director` 送出的**家長可見**公開回覆。

強制推論：
- 家長首則留言且無員工公開回覆 → awaiting  
- 老師或主任公開回覆後 → 非 awaiting（直到下一次新的 parent public event）  
- 家長再追問或**實際修改**原留言 → 重新 awaiting  
- 相同內容 idempotent upsert → **不**改變 awaiting  
- 主任內部評語 → **永不**改變 awaiting  
- 僅 mark-read → 可不 unread，但仍可 awaiting  
- 從未有家長留言的評量 → **不是** awaiting，**不進** inbox  

命名：新 API／欄位使用 `awaiting_staff_reply`／`awaiting_reply_count`／篩選值如 `feedback=awaiting_reply`（或等價 inbox query 參數）。**禁止**把本語意塞進或重新解釋 `analytics.unreplied_records`。

### FR-002 — 家長對外事件（parent public events）權威語意

以下為 **authoritative definition**（非「若產品視為」模糊句）：

| # | 行為 | 是否為新的 parent public event | 對 awaiting／unread／通知 |
|---|---|---|---|
| E1 | **初次建立**家長主留言 | 是 | 進入 awaiting；可產生未讀／通知（既有政策） |
| E2 | 家長 **實際修改**原主留言內容（正規化後 ≠ 既有儲存內容） | 是 | **重新進入** awaiting；可重設員工未讀；可依政策重送通知 |
| E3 | 家長 **追加追問**（append-only reply，`author_role=parent`） | 是 | **重新進入** awaiting；可重設員工未讀；可依政策通知 |
| E4 | 家長 upsert **正規化後內容完全相同** | **否**（idempotent no-op） | **不得**更新事件時間、**不得**重設 unread、**不得**重開 awaiting、**不得**重送通知 |
| E5 | `teacher`／`director` **公開回覆**（家長可見 reply） | 否（屬 staff public reply） | 僅解除對「當前最新 parent public event」的 awaiting |
| E6 | mark-read | 否 | 可清 unread；**不**影響 awaiting |
| E7 | 主任內部評語 | 否 | **不**影響 awaiting；家長不可見 |

**正規化**：至少含 trim；其餘規則（空白折疊等）由 DEV 在實作 PR 明寫並以測試鎖住，須對「肉眼相同的重送」穩定判定為 E4。

**員工公開回覆集合**：僅對話串中 `author_role ∈ {teacher, director}`。內部「主任給老師評語」不屬對外 events。

**穩定事件順序（強制）**：
- DEV **不得**只靠跨表、秒級 `timestamp` 猜測 E1–E5 先後。  
- 必須證明穩定排序（例如單一序列／單調 event id、或同源維護的 materialized awaiting state）。  
- 若現有資料無法穩定排序、或需要 materialized state／**migration／backfill**：DEV **停下**，提出方案，取得 **G3 人類批准** 後才能做 schema／資料修復。  
- 未獲 G3 批准前，不得在 production 路徑寫入新表／回填。

### FR-003 — Count API
提供與 inbox 預設 scope 一致的 `awaiting_reply_count`（可與 unread count 同回應或獨立端點）。  
**老師**：所有有權限且業務上應看到的分校加總。  
**主任**：預設目前分校；若呼叫方指定「全部授權分校」則與 UI 切換一致。  
**不包含**以 `super_admin` 為 P0 呼叫者的新 scope 承諾。

### FR-004 — Filter／Inbox query
提供列出 awaiting／新留言／全部家長留言對話的查詢能力。  
工程可選：(A) 擴充既有 learning-records 的 `feedback` 篩選；或 (B) 窄版 read-only inbox endpoint。  
選擇準則：不扭曲一般評量列表 contract、不把 inbox 特例塞爆評量 API；查詢過重則走 (B)。  
`awaiting`／`新留言` 查詢**不得**被一般評量短日期窗偷偷截斷。

### FR-005 — 權限（P0 不擴張）
- 可見／可回覆角色：**僅**既有 `teacher`、`director` 路徑與 ownership／campus 規則。  
- **禁止**為本功能放寬 route middleware 以納入 `super_admin`（即使 Controller 內已有分支，亦不得因此「順便」改 route 群組）。  
- `super_admin` 支援 = Out of Scope／future decision。  
- 回覆 API 沿用既有 staff reply；本 PRD 不放寬角色。

### FR-006 — TeacherHome 固定入口
永久「家長留言」卡；分開顯示新留言與尚未回覆；CTA 導向同一 inbox mode＋預設尚未回覆。

### FR-007 — 一級 Tab Inbox mode
LearningRecordsPage（或等價頁）一級「家長留言」；chip：尚未回覆／新留言／全部；範圍列可見。

### FR-008 — 回覆模式
點「回覆家長」開啟聚焦回覆（P0 可用現有 modal）；自動定位 focus；主 CTA「送出回覆」；內外分區。

### FR-009 — 空狀態與 mismatch
實作 F1／F2／F3 文案與 CTA（見設計定稿）；mismatch 保留 count 與 request context，不靜默歸零。

### FR-010 — 文案
UI 主文案用語表（家長留言／回覆家長／新留言／尚未回覆／全部留言／家長看得到／主任內部評語）。禁止 Feedback、以「已讀」表示已處理、共用「回覆」按鈕。

### FR-011 — Idempotent parent upsert
家長主留言 upsert 路徑必須實作 FR-002 E4；回歸測試鎖定「相同內容重送無副作用」。

---

## 7. 非功能需求 NFR

| ID | 需求 | 降級 |
|---|---|---|
| NFR-01 | 老師 awaiting count p95 < 800ms（單老師、合理資料量） | 超時回錯誤狀態＋重試，不回假 0 |
| NFR-02 | Inbox 首屏（預設尚未回覆）p95 < 1.5s | Skeleton＋可重試；不空白閃爍 |
| NFR-03 | Count 與 List 使用同一後端語意模組／服務，禁止前端推導 awaiting | Code review 擋前端推導 |
| NFR-04 | 不新增第二套 message 寫入路徑；回覆仍走既有 staff reply | — |
| NFR-05 | 若需 read model／快取欄位，必須由同一計算來源維護，並有回歸測試鎖語意；**涉及 migration／backfill 須 G3** | 無快取時直接計算（仍須穩定排序證明） |
| NFR-06 | **穩定事件順序**：不得只依跨表秒級 timestamp；測試必須含同秒／跨表夾具 | 無法證明則停工等 G3 方案批准 |
| NFR-07 | **Idempotency**：E4 相同內容 upsert 副作用為零（事件時間／unread／awaiting／通知） | 失敗＝blocker，不可宣稱完成 |

---

## 8. 技術方向（禁止 code）

### 資料與真相
- 真相來源：既有 `learning_record_feedbacks` + `learning_record_feedback_replies`（System A）。  
- Inbox = presentation／read model，不是新 domain。  
- 舊 `learning-record-feedbacks/analytics` 的 `unreplied_records` **凍結語意**，本功能新欄位／新計數並行。  
- 家長 upsert idempotency 與 awaiting 判定屬同一語意邊界。

### API 形狀（合約級，非實作）
- Count：回傳 `awaiting_reply_count`，並可並排既有 unread（**teacher／director**）。  
- List：`feedback=awaiting_reply`（擴充）**或** `GET .../parent-message-inbox`（窄版）；參數含 scope（單校／老師全校／主任全授權校）、分頁、狀態 chip。  
- 詳情／回覆：重用既有 feedback replies + staff reply。  
- 路由角色：**維持**既有 teacher／director；不擴張。

### 前端表面
- `TeacherHomePage`：固定卡片＋導航 payload（inbox focus，非 listOnly 空導航）。  
- `LearningRecordsPage`：一級 Tab＋inbox mode＋回覆模式。  
- `App` 導航：老師 CTA 對齊主任 feedback focus 等級的明確 focus token（語意改為 awaiting inbox）。  
- `DirectorDashboard`：count 改吃 awaiting（目前分校）並保留可發現入口（若主任亦需固定卡，與老師同文案層級）。

### 架構取捨
1. **先證明穩定排序，再決定是否物化**：優先可證明正確的計算；需 migration／backfill 則停等 G3。  
2. **Filter 擴充 vs 專用 inbox**：能不扭曲評量 contract 就擴充；否則窄版 inbox。  
3. **P0 回覆 UI**：modal 回覆模式，不做 drawer。  
4. **P0 角色**：只 teacher／director。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-07-28 | 心智模型＝任務收件匣＋課堂脈絡 | 純評量附註；完整訊息中心 | 符合「我要回覆家長」任務，又不承諾聊天產品 |
| 2026-07-28 | awaiting 定義採「最新 parent public event 之後無員工公開回覆」 | 整串從未有員工回覆；等同未讀 | 避免已讀清案；符合真實待辦 |
| 2026-07-28 | 新命名 `awaiting_*`，不重用 `unreplied_records` | 就地改 analytics 語意 | 防止 KPI／API 契約 silently break（TD-057） |
| 2026-07-28 | 老師預設全授權授課分校；主任預設目前分校 | 兩者都全校或都單校 | 老師責任跟課走；主任責任跟校區走 |
| 2026-07-28 | 首頁 count 與點入預設 scope／無日期截斷必須一致 | 首頁全量、列表 30 天窗 | 歷史 CTA 空白事故（#138 類） |
| 2026-07-28 | P0 含後端 authoritative count/filter | 只做導流＋unread | 僅導流＝containment，不滿足停止條件 |
| 2026-07-28 | P0 回覆＝既有 modal 回覆模式 | 立刻做 drawer／inline | 最小可接受聚焦，降低範圍 |
| 2026-07-28 | 側欄／通知 deep link 放 P1 | P0 全做 | 固定入口已解決「找不到」主因 |
| 2026-07-28 | API 形狀：能擴充 filter 則擴充，否則窄版 inbox | 強行塞滿 learning-records | 避免評量列表契約扭曲 |
| 2026-07-28 | **Founder／CEO = A**（產品／merge／activation）；PRD 批准≠ merge | Agent 全 R+A；DoD 含自行 merge/deploy | 符合 AllTrue 決策邊界；防未授權上線 |
| 2026-07-28 | **P0 角色僅 teacher＋director** | 把 super_admin 當 secondary／順便開 route | 現有 route 群組未明確含 super_admin；禁止權限擴張 |
| 2026-07-28 | **FR-002**：初建／真修改／追問＝新事件；相同內容 upsert＝idempotent no-op | 「若產品視為」模糊句；每次 upsert 都當新事件 | 封住假未讀／假 awaiting／假通知 |
| 2026-07-28 | 穩定排序必證明；需 migration／backfill 則 **G3 停等批准** | 只靠跨表秒級 timestamp | 防止狀態誤判 |

---

## 9. 資安與存取控制

**觸發**：家長留言內容屬溝通／可能含學生學習與家庭 PII；跨分校 scope；角色邊界。

| STRIDE | 評估 |
|---|---|
| S | 既有 Bearer＋role；回覆不可冒充其他角色 author_role |
| T | 回覆／upsert 內容驗證；E4 idempotency；禁止前端自訂 awaiting 狀態寫回 |
| R | staff reply／count mismatch／idempotent no-op 可追蹤（log 不含多餘 PII） |
| I | 老師不可看到無權課堂留言；主任不可越校；家長不可見內部評語（既有不變） |
| D | count／inbox 需合理分頁與節流；禁止無界全表掃描當預設 |
| E | **不新增公開端點；不放寬 teacher／director → super_admin；不放寬 teacher→director** |

結論：YELLOW→實作時 `[REVIEW]` 必做；HIGH 清空才得請求人類 G2 merge。權限擴張 = Stop Condition。

---

## 10. QA 驗收

### Happy Path
1. 家長初次留言 → 老師首頁 awaiting≥1 → 進入尚未回覆 → 回覆 → awaiting 減少、全部仍可見。  
2. 家長追問 → 再進 awaiting＋可有新留言。  
3. 家長**修改**原留言內容 → 再進 awaiting。  
4. 主任代回 → 該筆退出 awaiting。

### Edge
1. unread=0、awaiting=2 → 卡片仍顯示 2 則尚未回覆。  
2. 老師跨兩校各一筆 awaiting → 預設全看得到。  
3. 主任切換「全部授權分校」後 count／list 同步。  
4. **穩定排序**：同秒／跨表夾具下判定仍正確（不得只靠秒級 timestamp）。  
5. 僅內部評語、無公開回覆 → 仍 awaiting。  
6. 無家長留言的評量 → 不出現在 inbox。  
7. **相同內容重送（E4）**：awaiting／unread／通知／事件時間皆不變。  
8. **修改內容（E2）**：awaiting 重開（在先前已被回覆的前提下）。  
9. `super_admin`：**不**新增本功能專屬成功路徑承諾；既有 route 行為不因本 PR 放寬。

### Error
1. Count>0、列表空 → F3 mismatch UI，count 不靜默歸零。  
2. API 失敗 → 錯誤＋重試，不顯示假 0 完成態。

### UI/UX 清單
- [ ] 空狀態有圖示＋說明＋CTA  
- [ ] 非同步有 loading／skeleton  
- [ ] 成功／失敗有明確回饋  
- [ ] 內外評語分區防呆  
- [ ] Design token 一致  
- [ ] 觸控 ≥ 44px；無水平 overflow  
- [ ] 對比與鍵盤／aria 基本合格  

### 自動化
- PHPUnit：awaiting 語意矩陣（E1–E7：初建／修改／相同重送／追問／公開回覆／內部評語／已讀／跨校／主任 scope）。  
- PHPUnit：count total === list total（同參數）。  
- PHPUnit：E4 idempotent upsert（unread／awaiting／通知副作用為零）。  
- 前端單元：導航 focus／文案／卡片在 unread=0 仍渲染（若有既有 test runner）。  
- **禁止**測試主張 `analytics.unreplied_records` 等於 awaiting。  
- **禁止**測試把「秒級 timestamp 相等且無穩定序」當成可接受的生產排序策略。

---

## 11. 上線與維運

**路徑（人類批准驅動）**：
1. G0：Founder／CEO 批准本 PRD → 允許 ARCH／DEV。  
2. Agent 開 **implementation PR**（不同於本 PLAN PR #1471），跑 CI、蒐證。  
3. G2：人類另批 merge；若會觸發 `deploy.yml`，merge＝production activation。  
4. G3：若需 migration／backfill，人類另批後才可合入含 schema 的變更。  
5. G4：人類認可後，Agent 才可回報「已上線」並附 health／version 證據。

**Migration**：P0 預設爭取無新表；若 ARCH／DEV 證明必須物化 → **停等 G3**，不得自行合入。  
**Feature Flag**：無獨立 flag（修復可發現性）。緊急關閉 inbox UI 屬人類指示下的 hotfix 流程。  

**Observability**（僅在人類批准上線後由 Agent 監控）

| 監控 | 指標／log | 閾值 | Agent |
|---|---|---|---|
| Count／List mismatch | 前端／後端標記的 mismatch 事件 | 連續出現即查 | `[OPS]`/`[FEATURE]` |
| Count 延遲 | API p95 | >800ms 調查 | `[OPS]` |
| 5xx on inbox/count | HTTP 5xx | >1% 告警 | `[OPS]` |
| Idempotent upsert 異常 | 相同內容仍觸發通知／unread reset | 出現即查 | `[OPS]` |

**回滾**：人類批准後由 Agent 準備 `git revert` PR；有 migration 則依 down() 評估。不碰繳費／扣堂資料。

---

## 12. 里程碑與優先級

### P0（本 PRD 交付 — 仍受 G0–G4 約束）
| 項目 | Agent |
|---|---|
| awaiting 語意＋E1–E7／idempotent upsert＋count＋filter/inbox | `[FEATURE]` |
| TeacherHome 固定卡＋導航 | `[FEATURE]` |
| 一級 Tab inbox＋modal 回覆模式＋空狀態 | `[FEATURE]` |
| teacher／director 分校 scope（不含 super_admin 擴權） | `[FEATURE]` |
| 語意／idempotency／scope／mismatch 測試 | `[TEST]` |
| STRIDE＋FR 對照＋禁止權限擴張 Review | `[REVIEW]` |
| CHANGELOG＋必要 AI_REGRESSION／用語 | `[DOCS]` |
| **僅在人類 G2／G4 之後** monitor deploy／health | `[OPS]` |

### P1
側欄 alias、通知鈴 deep link、dedicated drawer、入口 funnel telemetry、≥5 位老師無提示實測紀錄。

### P2
Inline quick reply、下一則尚未回覆、可編輯草稿、歷史搜尋。

### Future（需獨立批准）
`super_admin` inbox／count／route 明確支援。

---

## 13. 風險／假設／開放問題

### 本專案既有證據
- CTA 進評量却看不到回饋的歷史修復（主任 focus／server `feedback=has|unread`）。  
- `analytics.unreplied_records` KPI 失真已記錄於產品 gap review 與 TD-057。  
- 雙向對話已存在；缺的是任務 inbox 與 awaiting 權威狀態。  
- 家長 upsert 可重複送出且目前可能造成假未讀／假通知。  
- feedback 員工路由群組為 teacher／director，不能推定 super_admin 路由可達。

### 業界（WebSearch）
- **ClassDojo**：教師有獨立 Chats／inbox；讀取狀態與「稍後再處理」可分開——支持「已讀 ≠ 已處理」。  
- **Seesaw Messages**：獨立 Messages 工作面＋read receipts，仍是訊息任務而非藏在作業表單深處。  
- 啟示：入口任務化、狀態維度分離；AllTrue 刻意不做成完整聊天。

### 開源對照（WebSearch）
- SchoolOS 等提供 `messages/inbox` + `unread-count` 分離端點。  
- Open-Tutor AI 等將家長—老師對話做成 portal inbox並帶兒童／課堂脈絡。  
- 本 PRD 不擴成完整 messaging hub。

### 風險與 Stop Conditions

| 風險／Stop | 緩解／強制行動 |
|---|---|
| 誤用舊 unreplied KPI | 新命名＋測試＋Review 黑名單 |
| Count／List scope 不一致 | 單一 scope 參數合約＋對測 |
| 跨表秒級 timestamp 誤序 | NFR-06；無法證明則 **停工等 G3** |
| 相同內容 upsert 假事件 | FR-002 E4＋FR-011＋測試 |
| 順便開 super_admin 權限 | FR-005；發現即 Stop，回退 |
| **未批准 merge／deploy** | G2／G4；Agent 禁止自行 merge 或宣告上線 |
| **未批准 migration／backfill** | G3；先方案後批准 |
| Inbox 查詢拖慢評量 API | 必要時窄版 endpoint |
| 使用者以為是即時客服 | 文案用「留言／回覆家長」 |

### 假設
- 既有 staff reply 已足夠作為「公開回覆」寫入路徑。  
- 正規化規則可在不改家長可見文案語意的前提下實作 E4。

### 開放問題
- [AI-RESOLVABLE] DEV 階段依查詢成本在「擴充 feedback filter」vs「窄版 inbox endpoint」二選一，寫入實作 PR 的 Decision 附註。  
- [AI-RESOLVABLE] 主任總覽是否與老師同款固定卡或僅強化既有 CTA——P0 最小為 count 語意改 awaiting＋可達 inbox。  
- [BLOCKED: 需人類 G3] 若穩定排序必須 materialized state／migration／backfill — 提出方案後等待批准。  
- [BLOCKED: 需獨立產品＋資安批准] super_admin 是否納入 inbox。

---

## 14. Definition of Done（AI 可驗證 — implementation PR 階段）

> 以下為 **implementation PR** 的完成定義。**不含** Agent 自行 merge／deploy。PLAN PR #1471 的「完成」僅指本文件 blocker 已封口。

### Implementation PR（需先有 G0）
- [ ] FR-001～002／011：PHPUnit 覆蓋 E1–E7；含 **相同內容重送不重開**、**修改內容會重開**、追問重開、內部評語不影響、已讀仍 awaiting。  
- [ ] NFR-06：穩定排序證明（測試夾具）；驗證方式：同秒／跨表案例全綠，且測試失敗條件包含「僅秒級 timestamp 策略」。  
- [ ] FR-004：awaiting 列表不受預設短日期窗截斷。  
- [ ] FR-003／005／007：老師全校 vs 主任目前分校；**無** super_admin 擴權 diff。  
- [ ] 契約隔離：新 UI／新 count **未**把 `summary.unreplied_records` 當 awaiting。  
- [ ] 前端入口：unread=0、awaiting>0 仍渲染卡片。  
- [ ] 回覆模式文案含回覆家長語意。  
- [ ] Implementation PR 的 CI：**實際跑到的** jobs 記錄 conclusion；skipped ≠ passed（報告必須分開列）。  
- [ ] CHANGELOG 條目存在於 implementation PR diff。  

### 人類 gate（非 Agent DoD）
- [ ] G2 merge：Founder／CEO 批准後才合併。  
- [ ] G3：若有 migration／backfill，另批。  
- [ ] G4：deploy／health／version 證據齊全後，才可由人類認可「已上線」；Agent 僅蒐證回報。

### PLAN 本修訂 DoD（本輪）
- [x] 三項 blocker 已寫入 PRD。  
- [x] production code changes = 0。  
- [ ] 仍停在 **[PLAN] Exit**（未進 DEV）。

---

## Todos（九類）

1. **後端 API／資料** `[FEATURE]`：awaiting＋E1–E7／idempotent upsert、count、filter 或窄版 inbox、teacher／director scope；不改 `unreplied_records`；不擴 super_admin。  
2. **前端 UI** `[FEATURE]`：TeacherHome 固定卡、導航 focus、一級 Tab inbox、modal 回覆模式、範圍列、mismatch。  
3. **UI/UX 精緻化** `[FEATURE]`：依 §5b。  
4. **測試與自動 QA** `[TEST]`：語意矩陣、E4 idempotency、穩定排序、scope、日期窗、count=list。  
5. **自動化 QA 驗收** `[TEST]`：執行 §10；報告區分 passed／failed／skipped。  
6. **資安靜態審查** `[REVIEW]`：§9＋禁止權限擴張。  
7. **Code Review** `[REVIEW]`：逐條 FR；黑名單 unreplied 誤用與 super_admin 擴權。  
8. **文件** `[DOCS]`：CHANGELOG；AI_REGRESSION 補「未讀≠待回覆／相同內容 upsert idempotent／勿用 unreplied_records」。  
9. **部署蒐證** `[OPS]`：**僅在人類 G2／G4 之後**監控 deploy.yml＋health；不得自行 merge。

---

## 決策摘要（可複製）

採「任務收件匣＋課堂脈絡」模型。  
`awaiting_staff_reply`：最新 parent public event 之後尚無 teacher／director 公開回覆。  
Parent public events：初建、實際修改原留言、追問 = 新事件；正規化後相同內容 upsert = idempotent no-op。  
未讀與 awaiting 獨立；內部評語與 mark-read 不影響 awaiting。  
禁止沿用 `analytics.unreplied_records`。  
P0 角色僅 teacher／director；不擴張 super_admin。  
老師預設「我的所有分校」；主任預設「目前分校」。  
Founder／CEO 為產品定義、merge、migration／backfill、production activation 的 A。  
PRD 批准只授權 ARCH／DEV／implementation PR；不授權 merge／deploy。

---

## Exit Checklist — [PLAN]

- [x] PRD 14 節完整（含 §1b Stage Gates）  
- [x] Todos 九類已標 Agent  
- [x] §13 已查本專案文件＋WebSearch 業界／開源  
- [x] Blocker 1 RACI／gates 已封口  
- [x] Blocker 2 P0 角色僅 teacher／director 已封口  
- [x] Blocker 3 FR-002 家長事件＋idempotency＋穩定排序／G3 已封口  
- [ ] **暫不進 DEV** — 等 Founder／CEO 明確批准 G0
