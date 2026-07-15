# Discussion Draft：預付包堂要在「排進行事曆」時預留，還是只在「出席」時扣？

| 欄位 | 內容 |
|------|------|
| 狀態 | Draft |
| 優先級 | P1（本輪唯一 Draft） |
| Round | 2026-07-15 Round 1 |
| 影響面 | 產品 / 工程品質 / UX / 長期維護 |

## 背景

我們維運多校區補習班系統：同一產品同時有「月結依堂計費」與「預付包堂／分鐘」兩種契約。預付模式的餘額已付款，但未來 `ClassSession` 若未物化，主任行事曆會「有錢沒課」；若依每週固定時段大段向前物化，又可能排出超過剩餘堂數的格子。

系統長期以**出席／核准評量**作為扣堂 chokepoint（分鐘制 ledger），不是以「建立行事曆列」扣。成熟 CRM（Teachworks、Tutorbase 等）多在 **scheduling／booking** 當下扣或攔截 package。我們反覆修的是物化路徑碎片化與 stranded prepaid，根因比较像** entitlement 何時從餘額變成行事曆占用**，而不只是缺一支 cron。

不想用再一次點修掩蓋；想聽有類似模型的團隊怎麼避雙扣與虛占。

## 現有設計

- **權威扣減**：出席相關路徑走單一扣堂服務（分鐘 ledger）；`RemainingSessions` 為衍生顯示。
- **行事曆真相**：已物化的堂次列；週檢視另有契約／例外合併規則（禁止前端散裝 if）。
- **預付痛點**：包堂餘額存在，但缺穩定的「向前生成且不得超過已付餘額」策略；夜間稽核能掃 stranded，但不能代替產品不變式。
- **約束**：多校區隔離；不可在 production 用 refresh-DB 類測試；變更扣堂屬 safety-critical，需先設計再改碼。

（不貼表名／內部 issue 編號到公開貼文；內部對照：G-010、F3、A1 分鐘扣堂。）

## 已研究內容

| 來源類型 | 連結或名稱 | 學到什麼 |
|----------|------------|----------|
| 成熟產品 | [Teachworks Package Billing](https://blog.teachworks.com/2022/12/package-billing-vs-billing-per-lesson/)；[Package Balances](https://blog.teachworks.com/2024/11/package-billing-faqs-tracking-package-balances-efficiently/) | Package 在**課被排進 calendar** 時更新 scheduled／used；可加 validation 擋超額排課 |
| 成熟產品 | [Tutorbase prepaid credits](https://tutorbase.com/blog/student-lesson-packages-tutoring)；[recurring + packages](https://tutorbase.com/blog/recurring-lesson-scheduling-software) | 常見 **book／confirm → debit**；餘額 0 不可再约；与 recurring series 綁在同一產品故事 |
| 系統設計 | [Google Calendar hybrid recurrence](https://sujeet.pro/articles/design-google-calendar) 等 | 規則 + rolling window 物化是行事曆共識；**不回答**「有限預付餘額如何截斷 window」 |
| 技術文章 | Dual-write／outbox／ledger migration（Lago、AppMaster 等） | 財務側應單一寫入來源；但「schedule hold vs attendance commit」两阶段在补教情景着墨少 |
| 本專案文件 | 復發家族 F3、G-010、分钟扣堂 A1、#957 epic | 内部已承认物化统一是根因工程；**尚未冻结**「物化是否占用 entitlement」的产品不变式 |

## 為什麼仍然沒有答案

研究後清楚的是两端极值：

1. **Schedule-time debit／block**（Teachworks 取向）：行事曆与餘額一致，但我们已把「真正消耗」定在出席；若物化也扣，容易雙扣，除非引入 hold→commit／cancel→release。  
2. **Attendance-only debit**（現狀）：財務語意乾淨，但向前物化必須另有 **cap = 剩餘 entitlement** 的投影規則；请假／调课／未出席是否釋放格子，文件没有统一教战手册。

缺的是**可复用的中间态设计**（reservation / soft-hold）在：

- 週固定時段 + 有限包堂  
- 出席才正式入账  
- 主任要先看到未來幾週課表  

同時滿足时的状态机与边界（取消、旷课、过期包）。公开教程多停留在「有余额才能约」，很少写 hold 生命周期。

## 希望社群提供什麼經驗

1. 你们若「日历占位」与「正式扣次」分离，hold 落在哪一层（DB 行、ledger  pending、仅 UI cap）？  
2. 取消／请假／no-show 时，如何释放 hold 且避免超卖与超显示？  
3. 有没有在「出席扣费」前提下做过 forward materialization cap 的生产事故或度量（stranded credits、parents 看到幽灵课）？  
4. 若只能选一个不变式：宁可日历少生成，还是宁可多生成再靠 validation 挡点名——你们如何取舍？

## 建議發佈平台

| 平台 | 理由 |
|------|------|
| Reddit `r/softwarearchitecture` 或 `r/ExperiencedDevs` | 要的是状态机／双阶段账本经验，不是推销 CRM |
| Reddit `r/SaaS`（谨慎） | 可能碰到教培/预约产品创始人，但噪音高 |
| Threads／華文工程圈 | 补習班／月結＋包堂语境更贴近；适合征求本地运营经验 |
| 勿发 GitHub 製品 Discussion（无对应上游） | 这不是单一开源库的 API 问题 |

公开贴文请改写成无内部代号、无客户 PII 的通用题；本 Draft 仅供内部定稿。

## 發表後回寫

- 社群連結：  
- 採納結論 → 寫入：ADR（物化 cap／hold 狀態機）＋必要時 `AI_REGRESSION` 不變式；**經核准前不改扣堂 production 路徑**  
