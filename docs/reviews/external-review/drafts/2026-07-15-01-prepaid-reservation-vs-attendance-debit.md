# Discussion Draft：預付包堂要在「排進行事曆」時預留，還是只在「出席」時扣？

> Phase 1 產出；**EI 下仍有效**（High-value Discussion Opportunity）。正文不重寫。待人 Publish 或標記 abandoned。

| 欄位 | 內容 |
|------|------|
| 狀態 | Ready to post（定稿見 [`publish/`](../publish/)） |
| 優先級 | P1（本輪唯一 Draft） |
| Trigger | High-value Discussion Opportunity（架構／產品取捨；非「做不出來」） |
| Round（historical） | 2026-07-15 Round 1 |
| Registry | [QR-005](../QUESTION_REGISTRY.md#qr-005預付包堂--物化預留-vs-出席扣)（`draft`） |
| Scorecard | [ERS-001](../SCORECARD.md) |
| Confidence Score | **4** / 5（两端產品行為為 Fact；hold 生命周期需求為 Inference；「社群必有可複製狀態機」為 Hypothesis） |
| Recommended platform | 見文末「建議發表平台」 |

---

## Business / User / Engineering Impact

| 面向 | 現況痛點 | 若問對並採納，預期改善 |
|------|----------|------------------------|
| Business | 已收款預付堂未出現在未來課表 → 營運看不到可服務容量；或過度排出未購堂 → 超賣風險 | stranded prepaid ↓；超賣／沖帳客訴 ↓；續費提醒建立在真實可排堂上 |
| User | 主任「有錢沒課」或看到超出餘額的幽靈格；老師點名／家長接送時段不稳 | 未來 N 週課表與剩餘包堂一致；請假／取消後格子與餘額同步可理解 |
| Engineering | 物化路徑碎片 + 出席扣堂 vs 排課扣 两套心智；點修 F3/G-010 易復發 | 凍結單一不變式（cap-only vs hold→commit）+ 回歸；#957 實作邊界清晰 |

**為什麼值得花社群時間**：两端「schedule debit」与「attendance debit」在公开产品文档都找得到，但**两者同时成立时的 hold 生命周期**几乎没有可复用战经。搞错会直接碰到扣堂／营收——属于高成本架构选择，适合用外部经验降低一次定错的代价。

---

## 背景

我們維運多校區補習班系統：同一產品同時有「月結依堂計費」與「預付包堂／分鐘」兩種契約。預付模式的餘額已付款，但未來堂次若未物化，主任行事曆會「有錢沒課」；若依每週固定時段大段向前物化，又可能排出超過剩餘堂數的格子。

系統長期以**出席／核准評量**作為扣堂 chokepoint（分鐘制 ledger），不是以「建立行事曆列」扣。成熟 CRM（Teachworks、Tutorbase 等）多在 **scheduling／booking** 當下扣或攔截 package。我們反覆修的是物化路徑碎片化與 stranded prepaid，根因比較像 **entitlement 何時從餘額變成行事曆占用**，而不只是缺一支 cron。

不想用再一次點修掩蓋；想聽有類似模型的團隊怎麼避雙扣與虛占。

## 現有設計

- **權威扣減**：出席相關路徑走單一扣堂服務（分鐘 ledger）；剩餘堂數為衍生顯示。
- **行事曆真相**：已物化的堂次列；週檢視另有契約／例外合併規則（禁止前端散裝 if）。
- **預付痛點**：包堂餘額存在，但缺穩定的「向前生成且不得超過已付餘額」策略；夜間稽核能掃 stranded，但不能代替產品不變式。
- **約束**：多校區隔離；不可在 production 用 refresh-DB 類測試；變更扣堂屬 safety-critical，需先設計再改碼。

（不貼表名／內部 issue 編號到公開貼文；內部對照：G-010、F3、A1 分鐘扣堂、QR-005／ERS-001。）

## Evidence Summary（Fact / Inference / Hypothesis）

| ID | 類型 | 陳述 | 來源／依據 |
|----|------|------|------------|
| E1 | Fact | Teachworks package 在課被排進 calendar 時更新 scheduled／used，並可 validation 擋超額 | [Teachworks package billing](https://blog.teachworks.com/2022/12/package-billing-vs-billing-per-lesson/)、[balances](https://blog.teachworks.com/2024/11/package-billing-faqs-tracking-package-balances-efficiently/) |
| E2 | Fact | Tutorbase 類產品常見 book／confirm → debit；餘額 0 不可再約 | [Tutorbase packages](https://tutorbase.com/blog/student-lesson-packages-tutoring) |
| E3 | Fact | 大型日曆系統採 hybrid：RRULE + rolling window + 例外；不定義預付餘額截斷 | [Google Calendar hybrid](https://sujeet.pro/articles/design-google-calendar) 等 |
| E4 | Fact | AllTrue 正式扣減在出席／評量相關路徑（分鐘 ledger），非建立行事曆列 | 本專 A1／扣堂 chokepoint 文件與程式契約 |
| E5 | Inference | 若物化也直接 debit 且出席再扣，將雙扣，除非引入 hold→commit／cancel→release | 由 E1+E4 推出 |
| E6 | Inference | 若僅 attendance debit，向前物化必須另有 cap＝剩餘 entitlement，否則會超顯 | 由 E2 精神 + E4 推出 |
| E7 | Hypothesis | 社群中已有可複製的「佔位 hold + 出席 commit」狀態機適用週固定＋有限包堂 | 尚未找到公開戰經；待驗證 |
| E8 | Hypothesis | 採 hold 模型比純 cap 更能同時滿足「主任先看未來課表」與「不超賣」 | 產品偏好假設；可能被 cap-only 否證 |

## 已研究內容

| 來源類型 | 連結或名稱 | 學到什麼 |
|----------|------------|----------|
| 成熟產品 | Teachworks Package Billing／Balances | Schedule-time 更新 package；可擋超額 |
| 成熟產品 | Tutorbase prepaid + recurring | Book=debit 故事清楚；少談 attendance-only |
| 系統設計 | Google Calendar hybrid recurrence | 窗口物化共識；不回答有限預付截斷 |
| 技術文章 | Dual-write／ledger migration（Lago、AppMaster） | 財務單一寫入；少談 schedule hold vs attendance commit |
| 本專案 | F3、G-010、A1、#957 | 物化統一是工程根因；entitlement 占用時機未凍結 |

## Rejected Alternatives

| 方案 | 為何拒絕（本階段） | 依據類型 |
|------|-------------------|----------|
| 再發「該不該 hybrid 物化」外問 | 已被 E3 + #957 覆蓋（QR-001） | Fact |
| 直接改 production：物化時也扣堂 | 與 E4 衝突，雙扣風險（E5）；屬 Decision-requiring，且本 Loop 禁止用改碼代替未知 | Inference |
| 只做 forward cron、不定義 cap／hold | 重複 G-010 症狀修復，不關閉超顯／跨賣 | Inference |
| 發文問「补習班軟體哪家最好」 | 无架构价值；违反高价值门槛 | Fact（题型不适） |

## 為什麼仍然沒有答案

研究後清楚的是两端极值（E1–E4），缺的是**可复用的中间态**（E7）：

- 週固定時段 + 有限包堂  
- 出席才正式入账  
- 主任要先看到未來幾週課表  

同時滿足时的状态机与边界（取消、旷课、过期包）。公开教程多停留在「有余额才能约」。

## 希望社群提供什麼經驗

1. 若「日曆占位」与「正式扣次」分离，hold 落在哪一层（DB 行、ledger pending、仅 UI cap）？  
2. 取消／请假／no-show 时，如何释放 hold 且避免超卖与超显示？  
3. 有没有在「出席扣费」前提下做过 forward materialization cap 的生产事故或度量？  
4. 若只能选一个不变式：宁可日历少生成，还是宁可多生成再靠 validation 挡点名——如何取捨？

## 建議發佈平台

| 平台 | 定稿 |
|------|------|
| Reddit `r/ExperiencedDevs` | [`publish/2026-07-15-01-reddit-experienceddevs.md`](../publish/2026-07-15-01-reddit-experienceddevs.md) |
| Threads（繁中） | [`publish/2026-07-15-01-threads-zh-tw.md`](../publish/2026-07-15-01-threads-zh-tw.md) |

## 發表後回寫

- 更新 [SCORECARD ERS-001](../SCORECARD.md) 生命週期與 D2–D5  
- 社群連結：（Reddit URL） （Threads URL）  
- 採納結論 → ADR（物化 cap／hold 狀態機）＋必要時 `AI_REGRESSION`；**經核准前不改扣堂 production 路徑**
