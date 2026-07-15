# Product Discovery Final Round — Founder Question Cull + Blind Spot Review

> **Date:** 2026-07-15  
> **Status:** Discovery only — **no DEV**. Supersedes open-ended Q1–Q20 asking in `CTO_PRODUCT_REVIEW_2026-07-15.md` §Founder.  
> **Rule:** Founder only answers irreducibly commercial decisions. Everything else is agent homework.

---

## Part I — Q1–Q20 重新分類

圖例：
- **A** = 可自行查證（去查／讀 production／跑唯讀指令；**不問**）
- **B** = 可合理推導（寫出推導；**不問**）
- **C** = 必須 Founder 決策（**保留**）
- **D** = 即使知道也不改 90 天實作策略（**刪除**）

| ID | 原題要旨 | 類 | 處置 |
|----|----------|----|------|
| Q1 | 12 個月第一商業目標 | **B** | 不问。Repo／域名／PCR／四校自營語境＝先穩自有校，不是對外 SaaS GTM（無銷售 SKU、無 franchise pack）。策略預設「穩四校信任脊骨」。若你之後真要賣給別的補習班再升級。 |
| Q2 | 每週救資料時間 | **B** | 不问。`docs/**`「CEO GO／待 CEO／NO-GO」≈30 hits／17+ files；PCR 文化＋#190/#704/#1152 已證明 Founder-as-OPS。預設 E-FOUNDER-OFFLOAD 有價值。 |
| Q3 | 哪校模範／客訴最高 | **A→B** | 不问。1062 PCR：活躍 stranded 敦化 35／東湖 28 最高；帳務事故新店（#190）；行事曆／LIFF 新莊、大安。Canary 預設敦化或新店依題材，不需你標「模範校」。 |
| Q4 | count vs date 營收占比 | **A** | 不问你。應 `ops:business-digest` 或唯讀 SQL 分 `ScheduleMode` 加總 `Charge`／Remaining×Rate。**缺的是 production 讀權，不是商業意見。** |
| Q5 | dormant #1152 政策 | **C** | **保留** |
| Q6 | #190 改不改歷史帳單 | **C** | **保留** |
| Q7 | 家長主通道 | **B** | 不问。架構已押官方 LINE（每校 OA／LIFF／webhook）。私人電話盛行也**不**取消修官方通道；只影響行銷話術。 |
| Q8 | 可否主動推播 | **C** | **保留**（併 Q18） |
| Q9 | 主任能否自助核銷 | **C** | **保留** |
| Q10 | RFID 占比 | **B／D** | 不问。`swipe-rfid` 已是 P0 smoke／核心路徑；維持即可。 |
| Q11 | 錯一堂最大代價 | **B** | 不问。`ForwardSessionGenerator` 已選「無確認 cadence → skip」，寧可缺席不造錯時段。 |
| Q12 | 90 天內擴校？ | **D** | 删除。無證據要擴；擴校工程不進預設 Top 10。真有日程你會主動說。 |
| Q13 | 競品比較 | **D** | 删除。`PRODUCT_GAP_REVIEW` 已對標；不改變信任脊骨優先。 |
| Q14 | bug 回報外部使用者 | **B／D** | 不问。CHAT_BUG／R51 預設老師／主任；維持公開回覆閘門。 |
| Q15 | 強品牌 vs 白牌 | **D** | 删除。每校獨立 LINE／子域已是白牌運作；90 天不改。 |
| Q16 | 主任信不信 AI 排序 | **D** | 删除。座艙先上**可解釋數字**（digest），不是黑箱 AI；與信任無關。 |
| Q17 | Laravel 停機窗 | **D** | 删除（90 天產品線外）。資安 P0 再單開。 |
| Q18 | 哪些通知絕不能自動 | **C（併入 Q8）** | 不單列 |
| Q19 | 最想甩掉的運維 | **B** | 不问。文件密度顯示 PCR／stranded／帳務善後＝第一刀。 |
| Q20 | 90 天嘴裡那句話 | **C** | **保留** |
| §10-1 stranded 現值 | **A** | 不问 — 讀 digest／audit |
| §10-2 = Q6 | **C** | 併入保留題 |
| §10-3 = Q5 | **C** | 併入保留題 |
| §10-4 = Q8 | **C** | 併入保留題 |
| §10-5 staff LINE 有無在送 | **A** | 不问 — log／Sentry／LINE 後台／observer 觸發實驗 |
| §10-6 90 天目標 | **B／→Q20** | 不问開題；用 Q20 當北極星 |
| §10-7 Issues 權限 | **A（環境）** | 非商業決策；要權限當 infra，不佔 Founder 題額 |

### 推導摘要（B 題關鍵論證）

1. **自營四校 ≠ SaaS 公司（Q1）**：`*.lifenet.com.tw`、分校 PCR、本校資料修復語言，沒有對外 pricing portal／tenant billing。預設 90 天＝信頼與營運，不是多租戶銷售。
2. **Founder 是 OPS bottleneck（Q2/Q19）**：CEO-gated 文件密度 + tuition 排程註解 + digest 有數無 UI。
3. **痛點校可從事故地圖推出（Q3）**：敦化 stranded、新店帳務、新莊／大安行事曆與 LIFF。
4. **官方 LINE 是已下注通道（Q7）**：繼續修 Reach；不因現場私人聯絡而停工。
5. **錯堂成本已工程化（Q11）**：skip > wrong slot。

### Agent 待查證清單（A — 不要你答文字，只要讀權或貼 log）

```
1) 最新 ops:business-digest JSON（stranded_sessions / stranded_amount / retention）
2) SQL：active StudentClass GROUP BY ScheduleMode 的合約數與 Charge 合計
3) feedback_push_log 近 14 天列數；NotificationLineDispatcher 成功/失敗 log
4) tuition:send-reminders 是否曾手動跑、家長客訴來源頻道（Gmail 標籤即可）
5) GitHub Issues 讀權（清理過時單用，非產品決策）
```

---

## Part II — 只留給 Founder 的問題（≤5）

> 請只答這五題。格式：題號 + 選項 + 一句補充。

### F1. 〔原 Q20〕90 天後，你要家長或主任嘴裡出現哪一句？
- **A**「課表／剩下的課終於是對的」→ Epic **E-PAID-VISIBILITY** 為第一
- **B**「有事後 LINE 會提醒我」→ Epic **E-REACH** 為第一
- **C**「帳單不會再跳來跳去」→ Epic **E-BILLING-TRUST** 為第一  
**為什麼仍要問：** 這是唯一會改變 Epic 排序的北極星；系統無法替你選「信任 vs 觸達 vs 帳務」誰先被市場原諒。

### F2. 〔原 Q5 / #1152〕已付但長期不上課的 dormant ~275 約，預設政策？
- **A** 傾向退費／銷課 → 做主任批次結案，**禁止**自動 gen
- **B** 保留資格等人回 → 標休眠 + 定期提醒主任，**禁止**自動 gen
- **C** 分校自行決定 → 產品只提供分類＋工具，不設全局預設  
**為什麼仍要問：** 退費／債權／人情屬商業與財務，技術只能禁止瞎生成。

### F3. 〔原 Q6 / #190〕系統歷史算錯的 6 筆週日 0 元帳單，財務態度？
- **A** 可改正義 Invoice／金額並通知家長 → 執行 repair
- **B** 絕不動歷史；只防未來 + 人工私下處理這 6 筆 → 關閉自動化修復
- **C** 只改未來續期；歷史帳單留註記不改數字  
**為什麼仍要問：** 動歷史收款＝財務／客訴政策；code 已能修，缺許可。

### F4. 〔原 Q8+Q18〕系統「主動」聯絡家長的邊界？
- **A** 評量回饋可推（安靜時段 + 退訂）；**催繳絕不自動**
- **B** 評量 + 請假結果可推；催繳仍人工
- **C** 全部暫不自動推（含評量）— 只加強 App 內找得到
- **D** 評量／催繳都可自動（強退訂）  
**為什麼仍要問：** 騷擾與催收是合規／品牌風險；flag 與 `tuition:send-reminders` 不能猜。

### F5. 〔原 Q9〕主任對「錯帳／作廢付款」的權限？
- **A** 主任可自助作廢／重算（完整稽核 log）→ 帳務 UX 產品化
- **B** 主任可申請，你（或指定一人）核准 → 做申請流，不落地直接 void
- **C** 只有你能動錢 → UI 只讀 + 指引找你  
**為什麼仍要問：** 這是組織授權，不是工程品味；決定 #1212 後續 UI 深度。

---

## Part III — Blind Spot Review

> 假設接手者是 Google Staff / Stripe Staff+ / Linear Founder Engineer。  
> **禁止延續現有 Roadmap 慣性。** 第一性原理。

### 1. 目前產品最大的「錯誤假設」是什麼？

**錯誤假設：只要核心流程「在資料庫裡正確」，使用者就會信任產品。**

證據相反：  
- 推播 dark launch → 家長必須 pull；正確評量若沒人打開 App＝不存在。  
- `Paid`∨Invoice 雙真相（G-009）→ 主任「改了又跳回」。  
- 預付餘額可 stranded（#1062）→ **付錢≠看見課**。  
- Business digest 每夜算 NT$ 風險但只進 log → 經營者看不見。

補習班買的不是 schema 正確性，是「明天孩子有沒有課、錢有沒有算錯、老師會不會回」。  
你們（含 AI）在修正確性，卻假設正確性會自動變成信任 —— **這條因果不成立**。

### 2. 有哪些工作一直在做，但沒有商業價值？

| 工作 | 為何沒商業價值 |
|------|----------------|
| Repository／control-plane／成熟度 issue（Merge Queue、DORA、staging 幻想） | 不增加續約、不減家長投訴 |
| #704 AmbientMusic／EngagementRank／SystemTrust 長期待決 | 彩蛋與內部實驗；零家長感知 |
| System B 意見箱雙軌 | UI 未接；維護稅 |
| MemPalace／AI 文件宮廷 | 協助 AI；不協助家長交學費 |
| 污染巨 PR、治理擴寫 | 消耗 review／Actions；无用戶輸出 |
| 對已 Done 項重複開治理／PCR 敘事不更新 | 製造假性 Founder 決策負擔 |

**直指 Founder：** 你核准太多「看起來像大公司」的工程劇目，卻讓「付錢看得见课」停在 owner-gated。這是優先序錯誤，不是努力不足。

### 3. 沒人在做、但應該立刻開始？

1. **Trust SLA 產品化**：對主任／家長承諾「已付堂 → N 天內必出現在行事曆；否則系統紅燈」。不是再寫 generator。  
2. **經營數字進主畫面**（digest Phase 1）— 今晚 log 裡的 NT$ 就是明天該吵架的數字。  
3. **Reach 的真實開關決策 + 觀測**（不是再實作一次推播框架）。  
4. **單一繳費狀態讀模型**給人看「為什麼是已繳」— 停手工翻 Invoice。  
5. **Dormant 資金負債清單**給主任結案（#1152）— 沉默存款是地雷。

### 4. 若要 ARR 成長最快，砍掉哪些開發？

> 註：Repo 顯示你們主要是**自營校工具**，不是標準 ARR SaaS。若「成長」＝續約率／降低流失／未來可賣 —

**砍／凍：** 治理擴張、#704 系列、System B、AI Phase 4–5 幻想、Laravel 大升級、Merge Queue/DORA、非信任路徑的 UX polish（軍階、token 色票）、再寫 incident 文件。

**保留並壓資源：** 信任脊骨（付課可見、帳務單一、通知送達）→ 這三者才影響「家長下期還繳不繳」。

沒有信任，**沒有 ARR 故事可講**；先把 churn／投訴壓住才有擴校或外售資格。

### 5. 哪一模組應重新設計，而不是一直修 Bug？

**「合約 → 物化堂次 → 前端合併 → 扣堂／帳單狀態」這一條脊骨**  
（`StudentClassController` + `ClassSession` materialization + `calendarOccurrenceMerge` + payment status）。

理由：F3/F5/F7 復發、七月半數 hotfix、五千行級 controller、正確性靠前端啟發式（G-007）—— 這是**領域模型未收斂**，不是缺測試案例。  
正確做法：單一後端 **Occurrence / Ledger Read Model**；前端只渲染；繳費狀態單一投影。  
繼續點修 = 付費的 Whack-a-Mole。

### 6. 哪種產品能力會形成真競爭優勢（非功能追趕）？

**「台灣補習班的已付堂信任閉環」：**  
RFID／點名 → 扣堂 → 評量 → 家長感知 → 續費提醒，且 **付錢必有課、狀態不撒谎**。

ClassDojo／Seesaw 沒有 RFID+包堂扣點+多校帳務；Cal.com 沒有扣堂。  
優勢不是功能清單，是：**在地營運閉環的可信度**。  
現在這優勢被 stranded／雙真相／無推播自己拆掉。

### 7. 只保留 20% 功能，留什麼？其餘為何可刪？

**保留：**
1. 登入 + 分校隔離  
2. 學生／課程合約（建課最小集）  
3. ClassSession 行事曆（老師＋主任）  
4. 出缺勤／RFID  
5. 扣堂／剩餘堂（單一真相）  
6. 繳費狀態／主任學費提醒（單一真相）  
7. 評量填寫＋核准  
8. 家長：下一堂、請假、看評量、（可選）LINE 通知  

**可刪／凍 80%：** 軍階／AmbientMusic／SystemTrust、System B、Adoption 失真 KPI、科目數花活、雙重意見箱、控制平面文件宮、多數治理 milestone、未接的進階 BI Phase 3–5、重疊的管理後台玩具。

刪的理由：不貢獻「明天有課／錢算對／老師有回」三句話。

### 8. Founder Bias 最容易走錯的地方（直接說）

1. **以工程正確性／大廠 SOP 代替家長感知** — 事故後堆 PCR、gate、文件，卻讓推播 flag 繼續 false。  
2. **把 AI／治理當成產品進度** — Agent 很忙 ≠ 續約率上升。你剛結束的治理週期必要，但若再延長就是逃避產品。  
3. **所有寫入都要你 GO** — 把公司變成你的審批佇列；主任學不會自助，系統無法規模化。  
4. **錯誤假設「功能建了等於使用者感受得到」** — PRODUCT_GAP 已寫推播缺口，仍 dark launch。  
5. **同時養太多校區與模式（count/date/package）卻沒有統一讀模型** — 複雜度是你選擇的，不是市場強迫的；複雜度債用 hotfix 償還會永遠還不完。  
6. **用 in-app bug／GitHub 吞吐量定義成功** — 成功應是 stranded NT$→0、繳費糾紛→0、家長「有通知」。

---

## Part IV — 投資人判決

### **Watch**

**不是 Invest：**  
- 看不見清晰單位經濟／ARR／外售漏斗；現況接近「自營校內部系統」。  
- 信任脊骨仍破（雙真相、stranded、reach 關著）—— 這對續費生意是致命傷。  
- Founder SPOF（CEO-gated OPS）在文件裡寫得太清楚；這不是可投資的運作槓桿。  
- 工程產能被治理與巨大污染 PR 稀釋。

**不是 Pass：**  
- 真實多校 production、RFID→帳務→評量閉環深度，是多數教育 EdTech 做不出的「醜但硬」資產。  
- July 已證明能修高痛點（週日 0 元、scheduler、LR 回填）—— 執行力存在。  
- Phase 0 digest 顯示有人開始用商業語言度量系統。

**Watch 條件（若要變成 Invest）：**  
90 天內把 F1 選中的那句北極星做成可度量結果（例如 stranded NT$ 下降 X%、繳費糾紛週數、推播送達率），並把 Founder 從逐筆 PCR 解放到政策層。  
做不到 → 降為 Pass（好工程、壞生意）。

---

## 下一步（仍不進 DEV）

1. Founder **只回 F1–F5**。  
2. 給 agent **一次** production 唯讀：digest JSON +（可選）Issues 讀權。  
3. 收齊後出 **1 頁 Execution Brief**（單一 Epic、不做清單散文），你再批「進 DEV」。

---

*Discovery author: Cloud Agent · 2026-07-15 · Hostile-friendly, Founder-bias explicit.*
