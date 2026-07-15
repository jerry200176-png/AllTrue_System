# External Review Score（成效追蹤）

> 驗證 External Review 是否真的提升產品／工程品質。  
> Scorecard 記錄**完整 Question Funnel**，不只 Draft。  
> 權威：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../GUIDE_EXTERNAL_REVIEW_LOOP.md)

## Question Funnel（完整階段）

```
Candidate → Research → Reject ─┐
                └──────────→ Draft → Publish → Adopt → Impact
```

| 階段 | 定義 |
|------|------|
| Candidate | 本輪提出、值得納入研究的候選（含 reopen 重驗） |
| Research | 完成研究閘門（官方／Issues／成熟產品／文章） |
| Reject | 研究後**不**建 Draft（含 `closed_*`／內部決策／無 reopen）— **必記**，不算失敗 |
| Draft | 新建 Discussion Draft（≤3；禁止為 KPI 硬凑） |
| Publish | 對外發佈（社群貼文上線） |
| Adopt | 結論寫入 ADR／不變式／明確不採納理由 |
| Impact | 產品／工程有可指認成效（KPI、復發下降、避免錯決策已驗證） |

ERS 列僅給進入 Draft（含之後）的問題；**Reject 的量與理由**在漏斗總表與 Registry。

## ERS 生命週期（Draft 之後）

`draft` → `ready` → `posted` → `replied` → `adopted` → `implemented` → `measured` → `retired`  
（可 `abandoned`）

## 分項分數 D1–D5（各 0–2，合計 0–10）

| 維度 | 0 | 1 | 2 |
|------|---|---|---|
| D1 發問品質 | 空泛或重複已知 | 有研究但仍偏寬 | 精確未知 + 可操作 |
| D2 社群信號 | 無／噪音 | 相關但難用 | ≥1 可驗證戰經 |
| D3 採納清晰度 | 未採納／含糊 | 部分採納 | ADR／不變式／明確不採納 |
| D4 實作落地 | 無變更 | 文件或局部 | Production + 回歸 |
| D5 實測成效 | 無／惡化 | 持平／早期正向 | KPI 達標 |

---

## Evidence Phase — Question Funnel 總表（Rounds 1–5）

> 每輪結束更新。**沒有值得問就不要問** — Reject 高、Draft 低可以是健康訊號。  
> 禁止為輪次或 KPI 產生 Draft。

| Round | Candidate | Research | Reject | Draft | Publish | Adopt | Impact | 零 Draft 碼 |
|-------|-----------|----------|--------|-------|---------|-------|--------|-------------|
| 1 | 5 | 5 | 4 | 1 | 0 | 0 | 0 | — |
| 2 | 5（重驗） | 5 | 5 | 0 | 0 | 0 | 0 | Z3+Z4 |
| 3 | — | — | — | — | — | — | — | — |
| 4 | — | — | — | — | — | — | — | — |
| 5 | — | — | — | — | — | — | — | — |
| **累計（獨特候補）** | **5** | **5** | **4 結案 + 重驗 Reject** | **1** | **0** | **0** | **0** | — |

### 累計轉換率

| 階段 | 比率 | 解讀 |
|------|------|------|
| Candidate → Research | 5/5 = 100% | 閘門有跑 |
| Research → Reject | 4/5 = 80%（R1）；R2 全 Reject 新 Draft | 多數已知答案／內部題 — 可能健康 |
| Research → Draft | 1/5 = 20% | 符合不硬凑 |
| Draft → Publish | 0/1 = 0% | **當前瓶頸**（人發佈） |
| Publish → Adopt | n/a | |
| Adopt → Impact | n/a | |

### 零 Draft 原因碼

| 碼 | 意義 |
|----|------|
| `Z1_researched_closed` | 充分答案／Registry closed |
| `Z2_internal_only` | 僅內部決策 |
| `Z3_reopen_none` | 重驗無 reopen |
| `Z4_pipeline_wait` | 已有未發表 Draft |
| `Z5_no_signal` | 無新系統訊號 |

### 連續零 Draft → Meta Review

| 欄位 | 值 |
|------|-----|
| 觸發 | **連續兩輪** New Draft = 0 |
| 目前連續 | **1**（僅 Round 2；Round 1 有 Draft） |
| 下次若 Round 3 亦 0 | 必做 [`META_REVIEW_TEMPLATE.md`](META_REVIEW_TEMPLATE.md) |
| 檢查三問 | (A) 系統真成熟？ (B) Research Gate 過保守？ (C) Blind Spot 漏題？ |

Meta Review **不是**產 Draft 的藉口；結論可以是「維持拒寫」或「放寬某一類研究範圍」。

### 機制效益日誌

| Round | 類型 | 說明 |
|-------|------|------|
| 1 | 避免錯決策 | 未外問 hybrid／ledger（已有答案） |
| 1 | 避免錯決策 | 共用池 → `closed_internal` |
| 1 | 品質提升 | 唯一 Draft＝entitlement 占用時機（QR-005） |
| 2 | 避免錯決策 | 零 Draft（Z3+Z4）；不為湊輪重問 |
| 2 | 品質提升 | 漏斗含 Reject；瓶頸＝Draft→Publish |

---

## ERS 列（僅 Draft 後）

### ERS-001：預付 reservation vs 出席扣

| 欄位 | 內容 |
|------|------|
| QR | [QR-005](QUESTION_REGISTRY.md#qr-005預付包堂--物化預留-vs-出席扣) |
| Draft | [`drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) |
| Funnel 位置 | Draft（未 Publish） |
| 生命週期 | `draft` |
| 發文／連結 | — |
| 採納／實作／KPI | — |
| D1–D5 | D1=2；D2–D5=0 |
| 合計 | 2 / 10（未發文，不計健康平均） |

---

## Round 5：Evidence-based Retrospective（目標重定）

Round 5 **不是**「決定要不要加治理模組」的開關。

Round 5 必產出：[`RETROSPECTIVE_TEMPLATE.md`](RETROSPECTIVE_TEMPLATE.md) 實例  
`rounds/YYYY-MM-DD-round-5-retrospective.md`

依五輪資料回答：

1. External Review 的**實際價值**（漏斗何處有效、機制效益是否可指認）  
2. **限制**（例如卡在 Publish、或 Gate 過嚴／過鬆、Blind Spot 模式）  
3. **下一步**（維持／收緊／放寬發問；流程微調）  
4. **然後才**決定是否引入 Decision Journal、Knowledge Base 或其他治理 — 證據不足則明確「不引入」

禁止在 Round 5 前預建 Journal／KB。
