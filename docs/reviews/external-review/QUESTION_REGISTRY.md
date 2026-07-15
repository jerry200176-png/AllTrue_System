# Question Registry（候選問題登記）

> 記錄每個候選問題：**為何不發問／為何發問**，以及**何時重新評估**。  
> 結案候選必登記；Draft 也登記（狀態不同）。  
> 權威流程：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../GUIDE_EXTERNAL_REVIEW_LOOP.md)

## 狀態

| 狀態 | 意義 |
|------|------|
| `closed_researched` | 研究後有充分答案 → 不發外問 |
| `closed_internal` | 屬內部決策／CEO／產品規則，非社群題 |
| `closed_implemented` | 答案已落地（ADR／code），無需再問 |
| `draft` | 已有 Discussion Draft，待發或已發 |
| `reopen_candidate` | 觸發條件已出現，下輪必重評 |

## 重新評估觸發（通用）

任一成立 → 將列目標為 `reopen_candidate`，下一輪 External Review 必重跑研究閘門：

- 相關復發家族再度出現（6 個月內同根因）
- 依賴的「充分答案」來源被證偽或產品重大改版
- AllTrue 產品不變式變更（例如扣堂 chokepoint 從出席改為排課）
- 實作後度量顯示原結論不成立（見 SCORECARD）

---

## QR-001：行事曆 RRULE vs rolling-window 物化

| 欄位 | 內容 |
|------|------|
| 狀態 | `closed_researched` |
| 首見 | Round 1（2026-07-15） |
| 一句話問題 | 重複事件該純 RRULE、全物化，還是 hybrid？ |
| 為何不發問 | Google／Exchange 等已是 hybrid（規則 + 窗口物化 + 例外）；與內部 #957 方向一致，非未知 |
| 結論文 | 內部依 #957 統一 materialization；不外問 |
| 重新評估若 | #957 放棄 hybrid、或 production 證明窗口策略無法涵蓋預付有限餘額且無法用 cap 解決 |

## QR-002：`Paid` boolean → Invoice／ledger 單一真相

| 欄位 | 內容 |
|------|------|
| 狀態 | `closed_researched` |
| 首見 | Round 1（2026-07-15） |
| 一句話問題 | 如何從 OR 語意繳費狀態遷到 ledger 衍生狀態？ |
| 為何不發問 | Lago migration、append-only ledger、shadow／dual-run playbook 已充足（對照 G-009／F7） |
| 結論文 | 內部 ADR＋shadow；不外問 |
| 重新評估若 | shadow 對帳持續失敗且無業界類比可套；或法規／會計強制即時單一總帳而現 playbook 不適用 |

## QR-003：共用堂數池跨科分配（物化）

| 欄位 | 內容 |
|------|------|
| 狀態 | `closed_internal` |
| 首見 | Round 1（2026-07-15） |
| 一句話問題 | 12 堂池如何分配到各科課表再物化？ |
| 為何不發問 | 工程模式（family pool + subject tag）已知；缺的是**商業規則**，應 CEO／產品 Decision-requiring |
| 結論文 | 等產品規則後再 DEV；非社群 Draft |
| 重新評估若 | 產品規則已寫清但仍無任何成熟產品可對照實作邊界；或規則導致跨校池語意衝突 |

## QR-004：Agent 文件／alwaysApply 膨脹

| 欄位 | 內容 |
|------|------|
| 狀態 | `closed_researched` |
| 首見 | Round 1（2026-07-15） |
| 一句話問題 | 如何避免 instruction 檔從 map 變成 manual？ |
| 為何不發問 | progressive disclosure、size budget、機械 gate 社群共識明確 |
| 結論文 | 內部以 CI／瘦身規則實作；不外問 |
| 重新評估若 | 機械 gate 落地後復發率／違規率不降，且無既有治理解法 |

## QR-005：預付包堂 — 物化預留 vs 出席扣

| 欄位 | 內容 |
|------|------|
| 狀態 | `draft` |
| 首見 | Round 1（2026-07-15） |
| 一句話問題 | 行事曆占位要不要占用 entitlement？出席才正式扣時如何避雙扣／虛占？ |
| 為何仍要問／曾發問 | 两端產品做法清楚，**hold→commit 生命周期**缺乏可復用戰經驗證（見 Draft 01） |
| Draft | [`drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) |
| Score 列 | [`SCORECARD.md`](SCORECARD.md) → ERS-001 |
| 重新評估若 | Draft 關閉後仍無可用狀態機；或實作後 stranded／超賣指標惡化 |

---

## 登記規則（Agent）

1. 每輪每個候選（含結案）→ 新增或更新一列 QR-NNN  
2. Round log 只摘要；**詳情以本 Registry 為準**  
3. 禁止刪除歷史列；狀態可變更，並在列內加一行「狀態變更：日期 + 原因」  
4. Decision Journal／Knowledge Base：**不引入**（Round 5 Retrospective 2026-07-15：缺口在 Draft→Publish，非缺模組）  
