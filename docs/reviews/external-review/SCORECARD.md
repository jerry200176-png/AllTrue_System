# External Review Score（成效追蹤）

> 驗證「發外問 → 採納 → 實作 → 實際成效」是否真的提升產品／工程品質。  
> 每一篇進入 Draft（含之後 Posted）建一列 **ERS-NNN**。  
> 權威流程：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../GUIDE_EXTERNAL_REVIEW_LOOP.md)

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

**機制健康**：Round 5 結束做首次回顧（已 `measured`／`retired` 平均）。平均 **< 4** → 收緊發問，**不加**新模組。  
**進階治理（Decision Journal／KB）**：僅 Round 5 回顧後依本表證據決定；禁止提前加。

---

## Evidence Phase — 漏斗總表（Rounds 1–5）

> 每輪結束更新。轉換率分母為上一階段累計數。  
> **本階段重點是收集證據，不是擴架構。**

| Round | Candidates | Researched | New Drafts | Published | Adopted | Product Impact 有記錄 |
|-------|------------|------------|------------|-----------|---------|----------------------|
| 1 | 5 | 5 | 1 | 0 | 0 | 0 |
| 2 | 5（重驗；無新主題） | 5 | 0 | 0 | 0 | 0 |
| 3 | — | — | — | — | — | — |
| 4 | — | — | — | — | — | — |
| 5 | — | — | — | — | — | — |
| **累計** | **5 獨特候選** | **5** | **1** | **0** | **0** | **0** |

### 累計轉換率（更新於每輪）

| 階段 | 比率 | 解讀 |
|------|------|------|
| Candidate → Researched | 5/5 = 100% | 研究閘門有執行 |
| Researched → Draft | 1/5 = 20% | 多數被結案，符合「不硬凑」 |
| Draft → Published | 0/1 = 0% | **當前瓶頸**：Draft 待人發佈／核准 |
| Published → Adopted | n/a | 尚無 Published |
| Adopted → Product Impact | n/a | 尚無 Adopted |

### 零 Draft 原因碼（Round 使用）

| 碼 | 意義 |
|----|------|
| `Z1_researched_closed` | 候選皆有充分答案／Registry closed |
| `Z2_internal_only` | 僅剩內部決策題，非社群題 |
| `Z3_reopen_none` | 重驗既有 QR，觸發條件未成立 |
| `Z4_pipeline_wait` | 已有未發表 Draft，新增發問無價值 |
| `Z5_no_signal` | 本輪無新系統訊號（無復發／無新 epic 邊界） |

### 機制效益日誌（避免錯決策／品質提升）

| Round | 類型 | 說明 |
|-------|------|------|
| 1 | 避免錯決策 | 未把 hybrid 物化／ledger 遷移當外問（已有答案）→ 避免假性社群依賴 |
| 1 | 避免錯決策 | 共用池標為 `closed_internal`，避免用工程討論代替 CEO 商業規則 |
| 1 | 品質提升 | 凍結「物化是否占用 entitlement」為唯一高價值未知（QR-005），降低 #957 範圍蔓延 |
| 2 | 避免錯決策 | 零新 Draft（Z3+Z4）：不因「要跑滿輪次」重問 QR-001～004 |
| 2 | 品質提升 | 漏斗顯示瓶頸在 Draft→Published，證明下一優先是**發佈 Draft 01** 而非加模組 |

---

## ERS-001：預付 reservation vs 出席扣

| 欄位 | 內容 |
|------|------|
| QR | [QR-005](QUESTION_REGISTRY.md#qr-005預付包堂--物化預留-vs-出席扣) |
| Draft | [`drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) |
| 生命週期 | `draft`（Round 2 仍未 Published） |
| 發文日期／連結 | — |
| 社群回覆摘要 | — |
| 採納結論 | — |
| 實作（PR／ADR） | — |
| 預期 KPI | stranded prepaid 堂數下降；行事曆超額格子＝0；扣堂雙扣事故＝0 |
| 實測（日期／數字） | — |
| D1–D5 | D1=2；D2–D5=0 |
| 合計 | 2 / 10（未發文，不計入機制健康平均） |

---

## Round 5 決策閘門（預先寫死，避免提前加模組）

Round 5 結束後才回答：

1. Draft→Published／Published→Adopted 轉換是否成立？  
2. 零 Draft 輪是否佔多數且原因健康（Z1–Z3），還是閘門過嚴／過鬆？  
3. 機制效益日誌是否出現可指認的「避免錯決策」或「產品指標改善」？  

任一為否或數據不足 → **維持現狀、收緊或維持發問**，不加 Decision Journal／Knowledge Base。  
僅在有正向證據時才提案下一治理機制。
