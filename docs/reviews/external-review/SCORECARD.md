# External Review Score（成效追蹤）

> 驗證「發外問 → 採納 → 實作 → 實際成效」是否真的提升產品／工程品質。  
> 每一篇進入 Draft（含之後 Posted）建一列 **ERS-NNN**。  
> 權威流程：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../GUIDE_EXTERNAL_REVIEW_LOOP.md) §8

## 生命週期狀態

`draft` → `ready` → `posted` → `replied` → `adopted` → `implemented` → `measured` → `retired`  
（可 `abandoned`：不發／發了無價值／決定不作）

## 分項分數（各 0–2，合計 0–10）

| 維度 | 0 | 1 | 2 |
|------|---|---|---|
| D1 發問品質 | 問題空泛或重複已知答案 | 有研究但仍偏寬 | 精確未知 + 可操作問題 |
| D2 社群信號 | 無回覆／純噪音 | 有相關經驗但難用 | ≥1 可驗證戰經或設計模式 |
| D3 採納清晰度 | 未採納或結論含糊 | 部分採納 | 寫入 ADR／不變式／明確不採納理由 |
| D4 實作落地 | 無 code／無流程變更 | 文件或局部實作 | Production 變更 + 回歸測試 |
| D5 實測成效 | 無度量或惡化 | 指標持平／早期正向 | 預設 KPI 達標（見各列） |

**機制健康（每 5 輪回顧一次）**：已 `measured`／`retired` 列的平均合計；若平均 **< 4** 連續兩次回顧 → 收緊發問閘門，而非加新模組。

---

## ERS-001：預付 reservation vs 出席扣

| 欄位 | 內容 |
|------|------|
| QR | [QR-005](QUESTION_REGISTRY.md#qr-005預付包堂--物化預留-vs-出席扣) |
| Draft | [`drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) |
| 生命週期 | `draft` |
| 發文日期／連結 | — |
| 社群回覆摘要 | — |
| 採納結論 | — |
| 實作（PR／ADR） | — |
| 預期 KPI | stranded prepaid 堂數下降；行事曆超額格子＝0；扣堂雙扣事故＝0 |
| 實測（日期／數字） | — |
| D1–D5 | D1=2（草稿質已達）；D2–D5=0（尚未發文） |
| 合計 | 2 / 10（發文前記錄基線，不計入機制健康平均） |

---

## 機制回顧日誌

| 回顧點 | 涵蓋輪次 | 已 measured 平均分 | 動作 |
|--------|----------|-------------------|------|
| （待 Round 3～5 後首次） | — | — | 暫不加 Decision Journal／Knowledge Base |
