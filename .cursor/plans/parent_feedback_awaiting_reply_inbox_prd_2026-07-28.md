# PRD：家長留言任務收件匣（awaiting_staff_reply）

> **狀態**：Draft — 等使用者批准後才可進 DEV  
> **Risk Tier**：T2（產品工作流／前後端契約；含多校區 scope 與家長可見回覆邊界）  
> **心智模型**：任務收件匣＋課堂脈絡（不是完整聊天系統）

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 家長留言任務收件匣（awaiting staff reply） |
| 版本 | v1.0 |
| 日期 | 2026-07-28 |
| 狀態 | Draft |
| 目標角色 | 老師（primary）、主任／super_admin（secondary） |
| 關聯 | 既有 System A `learning_record_feedbacks` 雙向對話；TD-057 KPI 失真（本 PRD **不改**舊 analytics contract）；歷史找得到性修復 #138/#139／CHANGELOG 2026-05-31 雙向對話 |

---

## 2. 目標與業務背景

### 痛點（非技術語言）
老師想「回覆家長」，但系統把入口放在「學習評量」資料結構裡：入口跟「未讀」綁死會消失、回覆藏在評量表單深處、跨分校時以為沒留言。結果是家長留言沒人回，老師卻覺得「系統沒有這個功能」。

### 業務價值
- 把「家長有留言要回」變成可找到、可清完的工作清單。
- 未讀與「尚未回覆」分開，避免「看過＝處理完」的假完成。
- 維持課堂脈絡（學生／日期／科目／分校），回覆仍附著於該堂評量，不承諾即時客服。

### 可量化 KPI

| KPI | 定義 | 目標（上線後 14 天觀察） |
|---|---|---|
| 入口到達率 | 老師點首頁「家長留言」進入 inbox 的次數／有 awaiting 老師日活 | 基準建立後追蹤（P1 telemetry） |
| 待回覆清償 | `awaiting_reply_count` 日終相對日初下降、無異常歸零 | 不因「只讀不回」而歸零 |
| Count／List 一致 | 首頁 count 與點入預設列表筆數一致（同 scope） | 100%（CI + 手動 AC） |
| 誤用舊 KPI | 新 UI／新 API **不得**讀寫 `analytics.unreplied_records` 當待回覆 | 靜態審查通過 |

---

## 3. 範圍

### In Scope（P0）
- **語意**：`awaiting_staff_reply`（正式定義見 Decision Log／FR-001），與 unread 分離。
- **後端**：authoritative count + filter／窄版 inbox query；老師／主任權限與分校 scope；家長追問後重進 awaiting；員工公開回覆後退出；內部評語不影響。
- **前端**：TeacherHome 固定「家長留言」卡片（新留言／尚未回覆分開）；CTA 落地同一 inbox mode；LearningRecordsPage 一級「家長留言」Tab；預設「尚未回覆」；現有 modal「回覆模式」；內外訊息分區；空狀態與 count/list mismatch。
- **文案**：對外改用「家長留言／回覆家長／新留言／尚未回覆」；禁止 Feedback／未讀回饋當主文案。
- **禁止沿用** `analytics.unreplied_records` 作為本功能狀態來源（該欄位保留原行為，TD-057 另案）。

### Out of Scope
- 完整即時聊天（WebSocket、已送達／已讀回條給家長、附件、表情、跨堂自由開對話、訊息搜尋平台）。
- 新建第二套 messages 真相來源／雙寫。
- 側欄獨立「家長留言」（P1）、通知鈴 deep link（P1）、dedicated drawer／inline quick reply／funnel telemetry dashboard（P1/P2）。
- 修改 `analytics.unreplied_records` / Adoption KPI 語意（TD-057）。
- 繳費、扣堂、排課、請假補課、家長可見性 domain 語意變更。
- Bug 回報／請假／繳費等其他待辦混入同一 inbox。
- System B `parent-feedback/*` 意見箱整併。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（資安／Code Review） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類（CEO／老師代表） | 使用者 | I |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR／功能 | System A 雙向回覆（`learning_record_feedback_replies`、staff/parent reply API）已上線 | 已完成 |
| 既有未讀 | `me/unread-feedback-count`、`feedback=has\|unread`、主任 feedback focus | 已完成；本功能並存不取代 |
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

### US-03：家長追問重新待回
**As a** 老師，**I want** 家長追問後案件再進待回覆，**so that** 我不會漏掉新問題。

- AC-03a：家長追問後該對話重新 awaiting，且可觸發新留言（unread）語意。
- AC-03b：主任代回視為已回覆（退出 awaiting）。

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

> 該堂課存在至少一則家長對外留言，且家長最新一則留言之後，沒有任何具權限的老師或主任送出的**家長可見**回覆。

強制推論：
- 家長首則留言且無員工公開回覆 → awaiting  
- 老師或主任公開回覆後 → 非 awaiting  
- 家長再追問 → 重新 awaiting  
- 主任內部評語 → **永不**改變 awaiting  
- 僅 mark-read → 可不 unread，但仍可 awaiting  
- 從未有家長留言的評量 → **不是** awaiting，**不進** inbox  

排序鍵必須穩定：比較「最新家長對外事件」與「最新員工公開回覆」時使用 `(occurred_at, sequence_id)`，不可只靠秒級時間戳。

命名：新 API／欄位使用 `awaiting_staff_reply`／`awaiting_reply_count`／篩選值如 `feedback=awaiting_reply`（或等價 inbox query 參數）。**禁止**把本語意塞進或重新解釋 `analytics.unreplied_records`。

### FR-002 — 對外 conversation events 組成
家長對外事件至少包含：該堂 `learning_record_feedbacks` 主留言（含後續更新主內容若產品視為新家長發言）、以及 `author_role=parent` 的對話串回覆。  
員工公開回覆僅 `author_role ∈ {teacher, director}` 的對話串回覆。  
內部「主任給老師評語」不屬對外 events。

### FR-003 — Count API
提供與 inbox 預設 scope 一致的 `awaiting_reply_count`（可與 unread count 同回應或獨立端點）。  
老師：所有有權限且業務上應看到的分校加總。  
主任／超管在主任工作情境：預設目前分校；若呼叫方指定「全部授權分校」則與 UI 切換一致。

### FR-004 — Filter／Inbox query
提供列出 awaiting／新留言／全部家長留言對話的查詢能力。  
工程可選：(A) 擴充既有 learning-records 的 `feedback` 篩選；或 (B) 窄版 read-only inbox endpoint。  
選擇準則：不扭曲一般評量列表 contract、不把 inbox 特例塞爆評量 API；查詢過重則走 (B)。  
`awaiting`／`新留言` 查詢**不得**被一般評量短日期窗偷偷截斷。

### FR-005 — 權限
老師僅可見自己有權回覆的課堂留言（既有 teacher ownership 鏡像）。  
主任限授權校區。  
回覆 API 沿用既有 staff reply；本 PRD 不放寬角色。

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

---

## 7. 非功能需求 NFR

| ID | 需求 | 降級 |
|---|---|---|
| NFR-01 | 老師 awaiting count p95 < 800ms（單老師、合理資料量） | 超時回錯誤狀態＋重試，不回假 0 |
| NFR-02 | Inbox 首屏（預設尚未回覆）p95 < 1.5s | Skeleton＋可重試；不空白閃爍 |
| NFR-03 | Count 與 List 使用同一後端語意模組／服務，禁止前端推導 awaiting | Code review 擋前端推導 |
| NFR-04 | 不新增第二套 message 寫入路徑；回覆仍走既有 staff reply | — |
| NFR-05 | 若需 read model／快取欄位，必須由同一計算來源維護，並有回歸測試鎖語意 | 無快取時直接計算 |

---

## 8. 技術方向（禁止 code）

### 資料與真相
- 真相來源：既有 `learning_record_feedbacks` + `learning_record_feedback_replies`（System A）。  
- Inbox = presentation／read model，不是新 domain。  
- 舊 `learning-record-feedbacks/analytics` 的 `unreplied_records` **凍結語意**，本功能新欄位／新計數並行。

### API 形狀（合約級，非實作）
- Count：回傳 `awaiting_reply_count`，並可並排既有 unread。  
- List：`feedback=awaiting_reply`（擴充）**或** `GET .../parent-message-inbox`（窄版）；參數含 scope（單校／老師全校／主任全授權校）、分頁、狀態 chip。  
- 詳情／回覆：重用既有 feedback replies + staff reply。

### 前端表面
- `TeacherHomePage`：固定卡片＋導航 payload（inbox focus，非 listOnly 空導航）。  
- `LearningRecordsPage`：一級 Tab＋inbox mode＋回覆模式。  
- `App` 導航：老師 CTA 對齊主任 feedback focus 等級的明確 focus token（語意改為 awaiting inbox）。  
- `DirectorDashboard`：count 改吃 awaiting（目前分校）並保留可發現入口（若主任亦需固定卡，與老師同文案層級）。

### 架構取捨
1. **先計算、後決定是否物化欄位**：優先後端依對外 events 計算；僅在查詢成本不足時加同源維護的 read model。  
2. **Filter 擴充 vs 專用 inbox**：能不扭曲評量 contract 就擴充；否則窄版 inbox。  
3. **P0 回覆 UI**：modal 回覆模式，不做 drawer。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-07-28 | 心智模型＝任務收件匣＋課堂脈絡 | 純評量附註；完整訊息中心 | 符合「我要回覆家長」任務，又不承諾聊天產品 |
| 2026-07-28 | awaiting 定義採「家長最新對外事件之後無員工公開回覆」（含追問重開） | 整串從未有員工回覆；等同未讀 | 避免已讀清案；符合真實待辦 |
| 2026-07-28 | 新命名 `awaiting_*`，不重用 `unreplied_records` | 就地改 analytics 語意 | 防止 KPI／API 契約 silently break（TD-057） |
| 2026-07-28 | 老師預設全授權授課分校；主任預設目前分校 | 兩者都全校或都單校 | 老師責任跟課走；主任責任跟校區走 |
| 2026-07-28 | 首頁 count 與點入預設 scope／無日期截斷必須一致 | 首頁全量、列表 30 天窗 | 歷史 CTA 空白事故（#138 類） |
| 2026-07-28 | P0 含後端 authoritative count/filter | 只做導流＋unread | 僅導流＝containment，不滿足停止條件 |
| 2026-07-28 | P0 回覆＝既有 modal 回覆模式 | 立刻做 drawer／inline | 最小可接受聚焦，降低範圍 |
| 2026-07-28 | 側欄／通知 deep link 放 P1 | P0 全做 | 固定入口已解決「找不到」主因 |
| 2026-07-28 | API 形狀：能擴充 filter 則擴充，否則窄版 inbox | 強行塞滿 learning-records | 避免評量列表契約扭曲 |

---

## 9. 資安與存取控制

**觸發**：家長留言內容屬溝通／可能含學生學習與家庭 PII；跨分校 scope；角色邊界。

| STRIDE | 評估 |
|---|---|
| S | 既有 Bearer＋role；回覆不可冒充其他角色 author_role |
| T | 回覆內容長度驗證；禁止前端自訂 awaiting 狀態寫回 |
| R | staff reply／count mismatch 保留可追蹤 context（既有或新增 log，不含多餘 PII） |
| I | 老師不可看到無權課堂留言；主任不可越校；家長不可見內部評語（既有不變） |
| D | count／inbox 需合理分頁與節流；禁止無界全表掃描當預設 |
| E | 不新增公開端點；不放寬 teacher→director |

結論：YELLOW→實作時 `[REVIEW]` 必做；HIGH 清空才 merge。

---

## 10. QA 驗收

### Happy Path
1. 家長留言 → 老師首頁 awaiting≥1 → 進入尚未回覆 → 回覆 → awaiting 減少、全部仍可見。  
2. 家長追問 → 再進 awaiting＋可有新留言。  
3. 主任代回 → 該筆退出 awaiting。

### Edge
1. unread=0、awaiting=2 → 卡片仍顯示 2 則尚未回覆。  
2. 老師跨兩校各一筆 awaiting → 預設全看得到。  
3. 主任切換「全部授權分校」後 count／list 同步。  
4. 同秒寫入：排序鍵仍判定正確（測試夾具強制同秒不同 id）。  
5. 僅內部評語、無公開回覆 → 仍 awaiting。  
6. 無家長留言的評量 → 不出現在 inbox。

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
- PHPUnit：awaiting 語意矩陣（首則／回覆／追問／內部評語／已讀／跨校／主任 scope）。  
- PHPUnit：count total === list total（同參數）。  
- 前端單元：導航 focus／文案／卡片在 unread=0 仍渲染（若有既有 test runner）。  
- **禁止**測試主張 `analytics.unreplied_records` 等於 awaiting。

---

## 11. 上線與維運

**部署**：feature branch → CI 綠 → PR merge → `deploy.yml`（含前端 deployable diff）。  
**Migration**：P0 預設可無新表；若加 read model 欄位／索引，merge 後由 deploy 跑 `migrate --force`。  
**Feature Flag**：無獨立 flag（修復可發現性；錯誤語意比延遲上線更糟）。若需緊急關閉 inbox UI，可熱修隱藏 Tab／卡片但保留 API。  

**Observability**

| 監控 | 指標／log | 閾值 | Agent |
|---|---|---|---|
| Count／List mismatch | 前端／後端標記的 mismatch 事件 | 連續出現即查 | `[OPS]`/`[FEATURE]` |
| Count 延遲 | API p95 | >800ms 調查 | `[OPS]` |
| 5xx on inbox/count | HTTP 5xx | >1% 告警 | `[OPS]` |

**回滾**：`git revert` 合併 commit；有 migration 則依 down() 評估；預估 10 分鐘內可還原前端入口。不碰繳費／扣堂資料。

---

## 12. 里程碑與優先級

### P0（本 PRD 交付）
| 項目 | Agent |
|---|---|
| awaiting 語意服務＋count＋filter/inbox | `[FEATURE]` |
| TeacherHome 固定卡＋導航 | `[FEATURE]` |
| 一級 Tab inbox＋modal 回覆模式＋空狀態 | `[FEATURE]` |
| 角色分校 scope | `[FEATURE]` |
| 語意／scope／mismatch 測試 | `[TEST]` |
| STRIDE＋FR 對照 Review | `[REVIEW]` |
| CHANGELOG＋必要 AI_REGRESSION／用語 | `[DOCS]` |
| merge 後 deploy＋health | `[OPS]` |

### P1
側欄 alias、通知鈴 deep link、dedicated drawer、入口 funnel telemetry、≥5 位老師無提示實測紀錄。

### P2
Inline quick reply、下一則尚未回覆、可編輯草稿、歷史搜尋。

---

## 13. 風險／假設／開放問題

### 本專案既有證據
- CTA 進評量却看不到回饋的歷史修復（主任 focus／server `feedback=has|unread`）。  
- `analytics.unreplied_records` KPI 失真已記錄於產品 gap review 與 TD-057。  
- 雙向對話已存在；缺的是任務 inbox 與 awaiting 權威狀態。

### 業界（WebSearch）
- **ClassDojo**：教師有獨立 Chats／inbox；讀取狀態與「稍後再處理」可分開（甚至可手動標回 unread 當提醒）——支持「已讀 ≠ 已處理」。  
- **Seesaw Messages**：獨立 Messages 工作面＋read receipts，仍是訊息任務而非藏在作業表單深處。  
- 啟示：入口任務化、狀態維度分離；AllTrue 刻意不做成完整聊天（無即時／回條／附件）。

### 開源對照（WebSearch）
- SchoolOS 等學校 MIS 提供 `messages/inbox` + `unread-count` 分離端點。  
- Open-Tutor AI 等 PR 將家長—老師對話做成 portal inbox，並帶兒童／課堂脈絡。  
- ElimuMS／Schoolyard 類系統亦將 messaging 當獨立工作面，但常伴隨廣播／推播——本 PRD 明確不擴成該完整 hub。

### 風險

| 風險 | 緩解 |
|---|---|
| 誤用舊 unreplied KPI | 新命名＋測試＋Review 黑名單 |
| Count／List scope 不一致 | 單一 scope 參數合約＋對測 |
| 同秒事件順序錯 | `(time, id)` 排序測試 |
| Inbox 查詢拖慢評量 API | 必要時窄版 endpoint |
| 使用者以為是即時客服 | 文案用「留言／回覆家長」 |

### 假設
- 家長主留言與追問皆可視為對外 conversation events。  
- 既有 staff reply 已足夠作為「公開回覆」寫入路徑。

### 開放問題
- [AI-RESOLVABLE] DEV 階段依查詢成本在「擴充 feedback filter」vs「窄版 inbox endpoint」二選一，寫入實作 PR 的 Decision 附註。  
- [AI-RESOLVABLE] 主任總覽是否與老師同款固定卡或僅強化既有 CTA——P0 最小為 count 語意改 awaiting＋可達 inbox；視覺對齊 TeacherHome 固定卡若成本低則一併做。

---

## 14. Definition of Done（AI 可驗證）

- [ ] FR-001～003：PHPUnit 覆蓋 awaiting 矩陣與 count；驗證方式：`php artisan test --filter=AwaitingStaffReply`（或等價 suite）全綠，且案例含追問重開／內部評語不影響／已讀仍 awaiting。  
- [ ] FR-004：awaiting 列表不受預設短日期窗截斷；驗證方式：對應 Feature Test 使用遠日期夾具仍回傳。  
- [ ] FR-003/007 scope：老師全校 vs 主任目前分校；驗證方式：Feature Test 斷言 count 與 list total 同參數一致。  
- [ ] 契約隔離：驗證方式：測試或靜態檢查證明新 UI／新 count **未**把 `summary.unreplied_records` 當 awaiting。  
- [ ] 前端入口：TeacherHome 在 unread=0、awaiting>0 仍渲染卡片；驗證方式：前端單元測試或元件測試斷言。  
- [ ] 回覆模式文案：主 CTA／標題含回覆家長語意；驗證方式：前端測試或 snapshot／字串斷言。  
- [ ] CI：驗證方式：`gh run view <id> --json conclusion` = `success`。  
- [ ] CHANGELOG：驗證方式：`git diff origin/main -- docs/CHANGELOG.md` 含本功能條目。  
- [ ] Deploy／health（有 deployable diff）：驗證方式：`deploy.yml` success 且 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 含 `"status":"ok"`。  
- [ ] 停止條件全不成立：未讀歸零入口不消失；不必開進階篩選；主按鈕非「編輯評量」；跨校不默默隱藏。

---

## Todos（九類）

1. **後端 API／資料** `[FEATURE]`：awaiting 權威計算、count、filter 或窄版 inbox、scope、與 unread 分離；不改 `unreplied_records` 語意。  
2. **前端 UI** `[FEATURE]`：TeacherHome 固定卡、導航 focus、一級 Tab inbox、modal 回覆模式、範圍列、mismatch。  
3. **UI/UX 精緻化** `[FEATURE]`：依 §5b token／空狀態／分區／44px。  
4. **測試與自動 QA** `[TEST]`：語意矩陣、scope、日期窗、count=list、文案／入口回歸。  
5. **自動化 QA 驗收** `[TEST]`：執行 §10 Happy／Edge／Error 可自動化部分並記錄結果於 PR。  
6. **資安靜態審查** `[REVIEW]`：§9 STRIDE＋校區／角色。  
7. **Code Review** `[REVIEW]`：逐條 FR-001～010；黑名單舊 unreplied 誤用。  
8. **文件** `[DOCS]`：CHANGELOG；必要時 AI_REGRESSION 補「未讀≠待回覆／勿用 unreplied_records」；TECH_DEBT 僅在 P0 砍 scope 時登記。  
9. **部署與 health** `[OPS]`：merge 後監控 deploy.yml＋health（前端有變更則看 version.json）。

---

## 決策摘要（可複製）

採「任務收件匣＋課堂脈絡」模型。  
「尚未回覆」(`awaiting_staff_reply`)：該堂已有家長對外留言，且家長最新一則之後尚無老師／主任家長可見回覆；追問後重開。未讀與尚未回覆獨立。禁止沿用 `analytics.unreplied_records`。  
老師 inbox 預設「我的所有分校」；主任預設「目前分校」並提供全授權分校切換。首頁 count 與點入列表同 scope、不受短日期窗偷裁。  
P0 必須含後端 authoritative count/filter（或窄版 inbox）、固定首頁卡、一級家長留言 Tab、modal 回覆模式。僅修導流仍用 unread ≠ 完成。

---

## Exit Checklist — [PLAN]

- [x] PRD 14 節完整  
- [x] Todos 九類已標 Agent  
- [x] §13 已查本專案文件＋WebSearch 業界／開源  
- [ ] **等使用者批准後才可進入 [ARCH]/[DEV]**
