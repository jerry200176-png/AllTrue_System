# AllTrue CTO Product Review — 2026-07-15

> **性質**：產品／商業決策用唯讀審查。**不改程式、不開治理新規則。**  
> **角色**：接任 CTO / Product Engineer 後的第一份跨源交叉分析。  
> **原則**：治理已結束；只服務使用者體驗、工程效率、可靠性、商業結果。

---

## 0. 調查範圍與資訊缺口（先講清楚）

### 已交叉的來源

| 來源 | 取證結果 |
|------|----------|
| **Docs** | `INDEX`、`CHANGELOG`（2026-07）、`TECH_DEBT`、`AI_REGRESSION`（F1–F7 / G-009/G-010）、`PRODUCT_GAP_REVIEW_2026-06`、`POLICY_AI_NATIVE_ROADMAP`、`SOP_MATURITY`、`DIRECTOR_PAYMENT_ALERT_RULES`、`incidents/189-191*`、`190*`、`runbooks/1062-track-a-pcr` |
| **程式／結構** | `Kernel.php` 夜間任務；`ForwardSessionGenerator` + `sessions:generate-forward --execute --scheduled`；`BusinessDigestService`；god-controllers／god-pages LOC；`perfflags.feedback_push_enabled=false` |
| **GitHub PRs** | 開放中：#1231、#1230、#1228、#1227、#1226、#1225、#1216、#1215、#1213、#1212、#1201、#1200 |
| **GitHub Projects / Discussions** | API 回傳空（無活躍 Project / Discussion） |
| **Actions** | 有 deploy／CI／scheduler 證據鏈（R68、digest、forward-gen）— 以 docs／Kernel 為準 |

### 本次**無法**取證（不猜）

| 缺口 | 影響 |
|------|------|
| **GitHub Issues** GraphQL／REST **403／404**（integration 無權） | 無法逐條審 open issues；Q3 只能以 Docs／PR／CHANGELOG 反推「過時方向」 |
| **Gmail** | 無 MCP／憑證；無法量化「使用者／家長抱怨信件」 |
| **Sentry／Production logs 即時** | 歷史有 Sentry 建單（TD-018）；本環境無 dashboard 存取 |
| **Production DB 現況數字** | 無法讀 Pi 上最新 stranded NT$／in-app bug 佇列；#1062 舊數字「~2000 堂」可能已因夜間 forward-gen 下降 |
| **In-app bug 即時 API** | 無 production token；只能從 CHANGELOG／incident 文件看近期主題 |
| **Telemetry 採用率** | `AdoptionInsights` 存在，無 live 報表輸出 |

**因此：本報告以「可驗證證據」排序；標 `⚠ 待核實` 處需 Founder 答案或唯讀 production 查詢後才能定案。**

---

## 1. 未來 90 天只准做 10 件事（ROI 排序）

> ROI = （對主任／老師／家長可感知價值 × 降低營收／信任風險）÷（工程複雜度＋CEO 閘門成本）。  
> 刻意**不收錄**「再做一輪 Repository 治理」。

| # | 事項 | 為何高 ROI | 主要證據 | 依賴 Founder？ |
|---|------|------------|----------|----------------|
| **1** | **帳務信任閉環**：合入並驗證 PR **#1212**（PaymentStatusService）＋對主任／老師可見「為何顯示已繳／未繳」＋停掉靜默翻轉 | 錢＝續約意願；F7／G-009 明確「改了又跳回」會直接打客服 | G-009、F7、CHANGELOG #798/#799 家族、PR #1212（+698/−117，可合併） | 部分（#190 政策另列） |
| **2** | **預付堂可見性閉環（#1062 產品面，非再寫引擎）**：主任能看到 stranded NT$／休眠合約決策面；夜間 forward-gen 健康度進總覽 | 已付款卻看不到課＝變相毀約；digest 已能量化卻只進 log | `BusinessDigestService` revenue.stranded_*；`POLICY_AI_NATIVE` Phase 1；Kernel 03:45 forward-gen；PCR 仍「未核准」與夜間 `--scheduled` 並行（文件滯後） | 是（#1152 dormant、PCR 批量 GO） |
| **3** | **觸達（Reach）**：修好 TD-065 LINE Observer、TD-013 自助綁定、TD-063 opt-out UI → **翻 `PERF_FEEDBACK_PUSH`** | PRODUCT_GAP 結論：功能建好但「使用者感受不到」；推播＝留存槓桿 | PRODUCT_GAP P1；`feedback_push_enabled` default **false**；TD-063/065/013 | 是（文案／節奏／是否灌爆） |
| **4** | **CEO 閘門清零**：#190 週日 0 元歷史帳 6 筆／Invoice #690/#691 **決定修／寫銷／擱置**；更新過時「待核准」banner（#189/#191 已於 PCR-R2 執行） | 每次 Agent 讀到「待 CEO」就停工；真實商業數字卡住 | `190-*` NO-GO；`957-d1-production-readiness`；CHANGELOG 07-09 #189/#191 resolved vs package banner 仍「待核准」 | **必須** |
| **5** | **交付列車**：合入 #1200（家長 LIFF deep link）、#1228（P2 API）、#1227 合併列車；**關閉／重建**污染 PR #1215／#1201（~1370 files，含 `.claude`／大量 plans） | 小 PR 已可感知；污染 PR 燒毀 review 與 CI minutes | `gh pr view` changedFiles 1373／1372、CONFLICTING | 否（流程決策即可） |
| **6** | **行事曆／堂次「金約」測試＋剩餘 ghost 家族止血**（F3／F5／leave 一致性） | July CHANGELOG 幾乎半數 fixes 落在此模組；每修一點都回頭咬信任 | F3/F5、G-007、July 07-08~11 fixes、god pages | 否 |
| **7** | **主任營運座艙 = AI-native Phase 1**：`GET admin/business-digest` + DirectorDashboard 小控件（營收風險／留存風險／資料品質） | Phase 0 已 ship；缺 UI＝Founder 仍靠人挖 log；直接服務商業問題「今天店開得怎樣」 | `POLICY_AI_NATIVE_ROADMAP` Phase 1「highest ROI」 | 否（範圍需點頭） |
| **8** | **建／改課費用即時試算（前端）** | 低工、防 `rate_unit` 錯帳；PRODUCT_GAP P1；減少帳務事故再進 #190 類 | PRODUCT_GAP 區2 | 否 |
| **9** | **把 Founder 從夜間維運／PCR 文案裡抽出**：digest → Telegram／既有 alert 頻道；安全 nightly 白名單與「需人工 GO」清單分離；tuition 提醒是否自動（現 Kernel **註解掉**） | 每次 PCR／手動 remind 都是 Founder 成本；系統已能算，缺路由 | Kernel `tuition:send-reminders` 註解；digest log-only | 是（提醒政策） |
| **10** | **核心路徑減複雜度（有界）**：從 `StudentClassController`（~5296）／`CourseManagement.vue`（~6224）剝「摘要／繳費狀態」讀模型，餵給 #1/#7 — **不做大重構秀** | 減未來 bug 倍增率；服務交付而非美觀 | LOC；ADR-003／god-controller 警告 | 否 |

**刻意不進 Top 10**：Merge Queue、DORA 儀表板、staging 完整環境、System A/B 大合併、AmbientMusic／EngagementRank、再寫 control-plane 文件、Laravel 8→10 全遷（TD-014 列為「90 天外決策」除非資安事件）。

---

## 2. 最大瓶頸模組（證據，非感覺）

### 結論

**最大瓶頸 =「契約／物化堂次／行事曆合併／繳費狀態」四位一體的核心迴圈**  
（`StudentClass` ↔ `ClassSession` ↔ `schedules` ↔ Invoice/Payment ↔ 前端 merge／CourseManagement／Director 提醒）

不是「前端不夠漂亮」，也不是「GitHub 不夠乾淨」。

### 證據鏈

1. **復發家族集中**：F3（生成）、F5（行事曆合併）、F7（繳費雙真相）全部落在此迴圈；G-007／G-009／G-010 同區。
2. **程式質量／接觸面**：`StudentClassController` ~5296 LOC；`ClassSessionController` ~2956；`CourseManagement.vue` ~6224；`SmartCalendar.vue` ~3309；`calendarOccurrenceMerge.js` 仍是唯一合法週合併路徑。
3. **產品事故密度（July CHANGELOG）**：週日月結 0 元、幽靈請假 chip、leave_requested 兩邊矛盾、跨約重複堂、評量無法填（268 筆回填）、#1062 stranded／scheduler 從未跑 — 皆同模組。
4. **商業指標已指向此處**：`BusinessDigestService` 第一項 anomaly 就是 stranded prepaid × Rate（#1062）；AI-native Phase 0 為它而生。
5. **CEO 閘門亦卡此處**：#190 帳務、#1062／#1152 堂次、189/191 重複堂 — 全部是「核心迴圈資料壞了之後的人工善後」。

**次瓶頸（但次一級）**：通知觸達管線（功能在、flag 關、Observer 疑似失效）— 影響「感受與留存」，不直接摧毀帳簿。

---

## 3. 哪些 Issue／方向可關閉或重新定義

> **⚠ Issues API 不可讀**：下列依 Docs／PR／CHANGELOG 推斷；Founder 應用 Issues 搜尋核實後關閉。

| 候選 | 建議 | 理由 |
|------|------|------|
| `SOP_MATURITY`「進行中」所列 **#937／#938／#970** 等 06-27 項目 | **關閉或標 superseded** | Handoff 自 06-27 未清；July 已大量 ship；檔案自我標 HISTORICAL |
| 成熟度 M4：**#868 staging、#869 flags、#871 Merge Queue、#872 DORA** 等 | **重新定義為「非 90 天」或 Won't do now** | 單 Founder／四校產品；不會改善家長／主任體驗；屬工程虛榮 |
| **#704** EngagementRank／SystemTrust／AmbientMusic | **決定 Kill 或真做**；不要長期「待 CEO」 | RULE_DESIGN_SYSTEM 仍待決策；使用者幾乎無感卻佔認知 |
| PRODUCT_GAP 仍列 **TD-062 行事曆效能 P1** | **文件更新／對應 issue 關閉** | TD-062 已標 Done（Phase 1–3 + P4） |
| **污染 PR #1215／#1201**（非 issue，但佔看板） | **Close／砍枝，改開最小 PR** | 1370+ files 含 `.claude` hooks、海量 `.cursor/plans` — 無法 review，且 CONFLICTING |
| Governance／外部 intelligence 類 open PR（#1230/#1231） | **維持 docs／CI，不再擴 scope**；勿排進產品 Top 10 | 你已裁定治理結束 |
| Epic「復發家族根治」若只開子項而不綁主任可見驗收 | **重新定義 AC** | 否則 Agent 會繼續點修、使用者無感 |

### 真正重要、但 GitHub／Roadmap **未當成產品第一優先** 的 Epic（新建建議）

| 新 Epic | 為何高於多數現有待辦 |
|---------|----------------------|
| **E-BILLING-TRUST** | F7 直接造成「錢算錯／狀態跳回」→ 續約與客服；#1212 是手段不是 Epic 本體 |
| **E-PAID-VISIBILITY** | 家長／老師看得到已付的課；dormant／stranded 主任可決策；digest 必須上畫面 |
| **E-REACH** | 評價／請假／催繳「做了等於沒做」直到 LINE 真的送到；TD-065 可能自 #80 起就壞 |
| **E-FOUNDER-OFFLOAD** | PCR／手動 remind／讀 log → 少數預先授權的自動路徑；否則 90 天仍卡在你 |

---

## 4. Docs：不再真實／可刪可歸檔

| 動作 | 路徑 | 理由 |
|------|------|------|
| **清空或 ARCHIVE 交接區** | `docs/SOP_MATURITY.md` §進行中狀態（2026-06-27） | 與 July CHANGELOG 矛盾；誤導下一個 Agent 優先序 |
| **修指標** | 同檔 → `ENGINEERING_AUDIT` 路徑已進 archive | 壞連結 |
| **戳記 DONE／移 archive** | `docs/incidents/189-191-execution-package.md` banner「待 CEO」 | PCR-R2 + CHANGELOG 顯示已執行 |
| **更新落差說明** | `docs/runbooks/1062-track-a-pcr.md`「本 PR 不排程」 | Kernel + `SchedulerEvidence` 已 `03:45 --execute --scheduled`；批量 interactive PCR 仍待 GO — 文件須拆兩層 |
| **ARCHIVE（凍結草案）** | `docs/MODULE_PRODUCT_ENGINEERING_MATURITY_ROADMAP.md` | 自標 freeze；與 `POLICY_AI_NATIVE_ROADMAP` 重疊 |
| **DELETE 或維持 stub** | `docs/AI_DOC_LITERACY.md` | INDEX 已併入；避免雙入口 |
| **KEEP 快照、加歷史標** | `docs/reviews/PRODUCT_GAP_REVIEW_2026-06.md` | 仍有用，但不當 living P1 清單（TD-062 等已變） |
| **KEEP living** | `POLICY_AI_NATIVE_ROADMAP`、`CONTROL_PLANE_CONTRACT`、CHANGELOG、AI_REGRESSION、TECH_DEBT | 權威或防再犯 |
| **勿再膨脹** | 多份 INCIDENT_* | 屬 runtime 決策棧；產品優先時代**不要再加文件**，但也不必為刪而刪（成本＞收益） |
| **可考慮不進 AI always-on** | GUIDE_AGENT_SKILLS、大量 `docs/incidents/*` 執行包 | 調查時再讀；平時不佔認知 |

**刪除原則**：只刪「確定無證據價值且會誤導優先序」的入口；PCR／incident 證據包改 **戳記狀態**，不推奨硬刪（審計友好）。

---

## 5. 做了但使用者感受不到／只增複雜度

| 項目 | 現象 | 證據 |
|------|------|------|
| **學習回饋 LINE 推播** | Dark launch，`PERF_FEEDBACK_PUSH` 預設 false | config + TD-063 + PRODUCT_GAP |
| **TD-065 NotificationObserver** | 可能自 #80 起 staff LINE 從未經 observer | TECH_DEBT 明文 |
| **System B 意見箱** | UI 幾乎未接，雙軌維護 | TD-057 |
| **AdoptionInsights／失真 KPI** | `reply_rate` ≠ 真回覆 | TD-057／PRODUCT_GAP |
| **ops:business-digest** | 每早算商業風險 → 只寫 log | CHANGELOG 07-10；Phase 1 未做 |
| **SystemTrust／AmbientMusic／軍階** | #704 待 CEO；偏彩蛋／內部信任實驗 | RULE_DESIGN_SYSTEM |
| **MemPalace** | 對 AI 召回有用；對四校區家長／老師零 UX | INDEX：無 SLO、非 production |
| **Control-plane／Incident 文件叢** | 對 SRE／AI 有用；不改變任一角色畫面 | INDEX registry |
| **Repository governance checks（本輪）** | 服務交付衛生；**不是產品** | 你已裁定治理結束 |

---

## 6. UX 最差處（四角色）

> **學生**：無獨立登入產品表面；痛點體現在家長／老師日程與評量上。下列「學生」＝被排課／評量的資料主體視角。

### 主任
- **繳費／續課提醒 vs 帳單真相不一致**（Paid／Invoice OR）→ 核帳無效、狀態跳回（F7）。
- **對帳／drift／stranded 工具在 API／log**，總覽新增「今日優先」仍缺 NT$ 營收風險鑽取（Phase 1 未做）。
- **CourseManagement 過重**（六千行級）— 建課、改約、請假、加購認知負荷高；錯一次變 #190／幽靈堂。
- **CEO／人工 PCR 文化**讓主任感覺「系統要工程師才能修資料」。

### 老師
- **評量頁神級複雜**（LearningRecords ~7k LOC）＋歷史「無法填寫」缺口（七月回填 268）。
- **出缺勤 vs 課表 vs 評量對 leave 語意曾分裂**（07-08 才統一 leave_requested）— 信任已傷。
- **LINE 綁定不能自助**（TD-013）→ 通知到不了。
- TeacherHome 任務排序仍偏「系統語」而非「今天先點誰的名」（MODULE roadmap #909/#910 仍在）。

### 家長
- **回饋／請假結果靠進 App 紅點**；推播未開 → 「補習班沒下文」錯覺（PRODUCT_GAP #1）。
- **LIFF／校區 deep link** 仍在 PR #1200 — 通知點進去可能錯頁（若合入前現場仍痛）。
- **已付堂在行事曆看不到**（stranded）→ 最強負面口碑路徑。
- ParentPortal 首屏資訊過載（~2.5k LOC），缺「下一堂／待繳／待審請假」單一焦點（#912）。

### 學生（間接）
- 幽靈堂／重複堂／錯時段 → 實際到班體驗混亂、家長問責老師。
- 週日課曾算 0 元／0 堂 → 課程延續性風險（#190 家族）。
- 無自主工具：一切依賴家長／老師正確操作核心迴圈。

---

## 7. 最可能造成客服／主任抱怨（影響排序）

1. **帳務金額或繳費狀態不對／改了跳回**（F7、#190、rate_unit）— 錢＋信任。  
2. **已付錢卻沒課／行事曆空白或錯課**（F3、#1062、F5）— 「系統吃掉我們的課」。  
3. **請假／點名／評量三處講法不同**（leave 家族，七月仍在修）— 老師與家長互吵。  
4. **通知不到**（推播關、LINE 綁定、Observer）— 「為什麼都不回」。  
5. **評量填不了／假未填**（LR 缺口、重複堂）— 老師每日摩擦。  
6. **行事曆／換週慢或閃爍**（歷史 TD-018/062；多已修，投訴可能仍有慣性）。  
7. **Bug 回報後只關 GitHub 不回 App**（R51/R53；流程債）— 內部可見、對外冷漠。

---

## 8. 可自動化、讓 Founder 不必介入

| 自動化 | 現況 | 建議 |
|--------|------|------|
| 活躍預付向前滾堂 | 夜間 `--scheduled` **已在** | 文件對齊；digest 告警若 stranded 反升 |
| Stranded／LR／reproduction gate | 已排程 | 失敗 → Telegram／既有 critical 通道，勿等人翻 Pi |
| Business digest | Log-only | → 主任座艙 + Founder 早報 |
| Tuition reminders | **手動／註解** | Founder 定政策後開排程或明確「永不自動」 |
| PCR 分類 | 全部像要 CEO | 分 **預授權安全寫入** vs **真需 GO**；更新 189 banner |
| 污染 PR／過期 handoff | 人工 | Agents 預設關 CONFLICTING>300 files；SOP 交接區過期自動警告（已有治理機器則**沿用、不加規則**） |
| In-app triage 公開回覆 | 半自動 | Macro 已有；可草稿、不可自動關單 |

---

## 9. 若只能做一件：商業價值最大者

### **把「已付款的課」變成家長／老師／主任每天都看得到、狀態可信的東西（E-PAID-VISIBILITY + 帳務真相）**

**為什麼：**

- 補習班現金流與續約建立在「我付了 → 我有課 → 老師會來 → 有評量」。  
- 系統目前最貴的失敗模式是：**錢或堂次的雙真相／未物化**（digest 用 NT$ 標價；F7／#1062）。  
- 其他功能（軍階、治理、AI Phase 4）都建立在這條信任鏈之上；這條斷了，其餘都是噪音。

**一件事的最小切法（仍算「一件」）：**  
主任總覽可見 stranded NT$（digest Phase 1 一小塊）＋合入 #1212 讓繳費狀態單一說法＋確認夜間 forward-gen 讓活躍預付不再靜默消失。  
（#190 歷史 0 元若政策未定，可並列為「同一敘事下的決策」，但程式可先不寫。）

---

## 10. 資訊不足 → 需要你回答的問題（影響決策）

> 完整 20 問見文末；此處是**現在就擋決策**的最小集。

1. **現在 production digest／audit 的 stranded_sessions 與 stranded_amount 是多少？**（決定 #1062 產品優先是 P0 還是已降級）  
2. **#190 六筆：要退費、改正 Invoice、還是帳務寫銷？**（擋修復）  
3. **#1152 dormant ~275 約：退費／保留／忽略？**（擋可見性閉環）  
4. **家長學習回饋推播：是否在 30 天內允許 flip flag？**（擋 E-REACH）  
5. **LINE staff 推播實際有沒有在送？**（驗證 TD-065；決定是否動 Observer）  
6. **商業目標 90 天：留存、擴校、減客服、還是減你的值班？**（重排 Top 10）  
7. **Issues 列表權限是否可開給此 agent？**（否則 Q3 無法結案式清理）

---

## Evidence appendix（精選）

```
StudentClassController.php     5296
ClassSessionController.php     2956
CourseManagement.vue           6224
SmartCalendar.vue              3309
LearningRecordsPage.vue       ~7537  (explore)

SchedulerEvidence:
  sessions-generate-forward => sessions:generate-forward --execute --scheduled --horizon-weeks=4 @ 03:45

perfflags: feedback_push_enabled = env(PERF_FEEDBACK_PUSH, false)

Open PR contamination: #1215/#1201 ~1370 files CONFLICTING
Ship candidates: #1212, #1200, #1228
```

---

## 如果我是第一次接手：最想問 Founder 的問題

> 規則：不問 GitHub／程式／Docs 已能推導者。每題含 A/B 決策分岔。壓到真正影響方向的集合（下列 **20**）。

### Q1. 未來 12 個月，AllTrue 的第一商業目標是什麼？
- **為什麼：** 決定 Top 10 是「減客服／穩四校」還是「產品化對外銷售」。
- **A 穩經營／減事故：** Top 10 鎖 E-BILLING-TRUST + E-PAID-VISIBILITY + Reach；凍結成長功能。
- **B 準備第二品牌／對外 SaaS：** 提前多租戶硬邊界、onboarding、帳單產品化；暫緩校內彩蛋。

### Q2. 你每週花在「救資料／批 PCR／回家长」的時間大概多少？最痛的是哪類？
- **為什麼：** 量化 Founder-offload ROI。
- **A ≥5h／週且多為帳務堂次：** 自動升級 E-FOUNDER-OFFLOAD + digest 告警為 #1。
- **B ＜1h 或痛在銷售／招生：** 把工程重心改到家長觸達與轉換，而非再挖 materialization。

### Q3. 四校區裡，哪一校是「模範校」、哪一校客訴最高？
- **為什麼：** 試點與 PCA 順序；避免「平均分校」假優先。
- **A 有明確高痛校：** 一切 rollout 單校 canary。
- **B 四校差不多：** 走全域夜間任務 + 指標監控。

### Q4. 預付包堂（count）與月結（date）營收占比？
- **為什麼：** #1062／F3 主要傷 count；若月結為主，優先序要改。
- **A count ≥半數營收：** E-PAID-VISIBILITY 不動搖第 1–2。
- **B 幾乎全月結：** 把力氣轉到結算日提醒／#190 類 date-builder；forward-gen 降級。

### Q5. 「已付但長期不上課」dormant 合約，你希望預設政策是什麼？（#1152）
- **為什麼：** 275 約無法技術猜。
- **A 傾向退費／銷課：** 做主任批次結案工具，不自動生成堂。
- **B 保留資格等人回來：** 標註休眠、禁止自動 gen、定期提醒主任。

### Q6. #190 這類「系統歷史算錯的錢」，學校財務願不願意改歷史帳單？
- **為什麼：** 挡修复能否动 Invoice。
- **A 可改正義並通知家長：** 執行 190 repair。
- **B 絕不動歷史、只防未來：** 關閉修復、只加防護 + 當面人工處理 6 筆。

### Q7. 家長關係主要靠什麼通道維繫？（LINE 官方／個人／電話／現場）
- **為什麼：** 決定 Reach 投資是否打在 LINE。
- **A 官方 LINE 是主通道：** E-REACH + LIFF #1200 升到 Top 3。
- **B 靠老師私人 LINE／電話：** 產品推播 ROI 低；改老師工作台話術與名單匯出。

### Q8. 是否接受「系統主動 LINE 推評量／催繳」？怕不怕被當成騷擾？
- **為什麼：** flag flip 的產品許可。
- **A 可，需安靜時段 + 退訂：** 做 TD-063 → flip。
- **B 暫不可：** 停 E-REACH 工程，只做 App 內找得到。

### Q9. 主任是否被授權「自行核銷／作廢錯帳」還是必須找你？
- **為什麼：** 決定帳務 UX 是自助還是審批流。
- **A 主任可自助（稽核日誌）：** #1212 + 作廢流程產品化。
- **B 只有你能動錢：** UI 以只讀 + 申請單；自動化審慎。

### Q10. RFID 刷卡在日常點名占比？如果壞了，營業能撐多久？
- **為什麼：** 可靠性投資 vs 課表／帳務。
- **A 幾乎全靠刷卡：** 把 RFID SLO 拉進 Top 10（本次未進）。
- **B 多用手動點名：** 維持現狀，不優先硬體。

### Q11. 你願意為「錯一堂課」付的最大代價是什麼：公開道歉／退費／換老師？
- **為什麼：** 設定錯誤預算與可否自動 gen 課（錯時段比缺席更傷）。
- **A 退費可接受、錯時段不可：** 維持 cadence 確認門檻；寧可不 gen。
- **B 盡快補課優先：** 可放寬生成策略、加強事後校正工具。

### Q12. 下一次擴校／新分校，時間表是否已定？
- **為什麼：** 多校隔離與 onboarding 是否要進 90 天。
- **A 90 天內有：** 拉高新校開課檢查清單與資料隔離測試。
- **B 無：** 不做擴校工程。

### Q13. 有沒有正在評估的替代／競品（其他補教 SaaS）讓家長或老師在比較？
- **為什麼：** 功能對標優先級。
- **A 有（點名）：** 對標該切面（例：家長 App 體驗）。
- **B 無，主憂穩定：** 只打可靠性與帳務信任。

### Q14. In-app bug 回報的「外部使用者」主要是誰？期待 SLA？
- **為什麼：** 支援流程自動化深度。
- **A 老師／主任當客服入口，要 48h 有回：** 強制 R51 公開回覆 + 值班輪（可 AI 草稿）。
- **B 幾乎是你自己開的：** 簡化 bug 系統，減少狀態機儀式。

### Q15. 你是否希望 AllTrue 品牌出現在家長面前，還是「白牌校務工具」？
- **為什麼：** 家長 Portal 的品牌與通知署名。
- **A 強品牌：** Portal／LINE 文案產品化。
- **B 白牌：** 通知用各校名義；少做跨校品牌功能。

### Q16. 「今日優先處理」這類 AI／規則排序，主任信任嗎？還是覺得煩？
- **為什麼：** Phase 1–3 智能層做深還是收斂。
- **A 要、且要會解釋：** 做 digest drill-down。
- **B 不信任／佔版面：** 停智能，只留原始清單。

### Q17. Laravel 8 EOL／資安升版，你能接受的停機或凍功能窗口嗎？
- **為什麼：** TD-014 是否擠進 90 天。
- **A 可規劃維護窗：** 排升級 spike。
- **B 今年不能停：** 只做配置緩解，大版本踢出 Top 10。

### Q18. 有沒有「絕不能自動發送」的通知類別（催繳／請假駁回／評量負面）？
- **為什麼：** 自動化邊界；避免一次推爆客服。
- **A 催繳絕不能自動：** tuition schedule 維持關；只做內部提醒。
- **B 全部可自動但可退訂：** 打開排程 + 強 opt-out。

### Q19. 你個人下個季度最想「再也別理」的一件運維瑣事是什麼？
- **為什麼：** 直接定義 E-FOUNDER-OFFLOAD 的第一刀。
- **A 「批 PCR／看 stranded」：** 預授權 + 座艙。
- **B 「回家長消息」：** 草稿宏 + Reach，不先做 BI。

### Q20. 如果 90 天後只准我交付一個「對外可感知」成果，你要哪一個句子出現在家長或主任嘴裡？
- **為什麼：** 最終驗收口語 = 產品北極星（比任何 KPI 真）。
- **A 「課表／剩下的課終於是對的」：** 全力 E-PAID-VISIBILITY。
- **B 「有事後 LINE 會提醒我」：** 全力 E-REACH。
- **（隱含 C）「帳單不會再跳來跳去」：** 全力 E-BILLING-TRUST — 若你回這句，我把 #1/#9 對調為帳務第一。

---

## 建議的下一步（仍不寫程式，直到你點頭）

1. 你回答文末 **Q1–Q20**（或至少 §10 的 7 題 + Q20）。  
2. 若允許：**唯讀**貼一日 `ops:business-digest` 輸出 或 授權查 stranded 匯總。  
3. 你指定「先做 Epic X」後，再進 [BUG]/[DEV] — **不做治理、不做大重構秀**。

---

*審查人：Cloud Agent（CTO Review mode）· 2026-07-15 · 依據 main 樹與可訪問之 GitHub PR／Docs；Issues／Gmail／Sentry live 明確標為缺口。*
