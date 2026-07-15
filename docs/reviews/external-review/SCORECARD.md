# External Review Score（成效追蹤）

> 完整 Question Funnel + ERS。權威：[`docs/GUIDE_EXTERNAL_REVIEW_LOOP.md`](../../GUIDE_EXTERNAL_REVIEW_LOOP.md)  
> **日常成功指標**：決策品質與產品成果（Adopt／Impact、避免錯決策）— **不是** session／輪次次數。  
> Evidence phase 存檔：[`rounds/2026-07-15-round-5-retrospective.md`](rounds/2026-07-15-round-5-retrospective.md) · 狀態：[`STATUS.md`](STATUS.md)

## Question Funnel

```
Candidate → Research → Reject ─┐
                └──────────→ Draft → Publish → Adopt → Impact
```

| 階段 | 定義 |
|------|------|
| Candidate | 納入研究的候選（含 reopen 重驗） |
| Research | 完成研究閘門 |
| Reject | 不建 Draft（必記；可為健康訊號） |
| Draft | 新建 Discussion Draft（禁 KPI 硬凑） |
| Publish | 對外發佈 |
| Adopt | ADR／不變式／明確不採納 |
| Impact | 可指認產品／工程成效 |

## ERS 生命週期（Draft 後）

`draft` → `ready` → `posted` → `replied` → `adopted` → `implemented` → `measured` → `retired`／`abandoned`

## D1–D5（各 0–2）

| 維度 | 0 | 1 | 2 |
|------|---|---|---|
| D1 發問品質 | 空泛 | 偏寬 | 精確可操作 |
| D2 社群信號 | 無／噪音 | 難用 | 可驗證戰經 |
| D3 採納 | 無／含糊 | 部分 | 清晰寫回 |
| D4 實作 | 無 | 局部 | Prod+回歸 |
| D5 實測 | 無／惡化 | 早期正向 | KPI 達標 |

---

## Question Funnel 總表（Rounds 1–5）— 最終

| Round | Candidate | Research | Reject | Draft | Publish | Adopt | Impact | 零 Draft 碼 |
|-------|-----------|----------|--------|-------|---------|-------|--------|-------------|
| 1 | 5 | 5 | 4 | 1 | 0 | 0 | 0 | — |
| 2 | 5 | 5 | 5 | 0 | 0 | 0 | 0 | Z3+Z4 |
| 3 | 5 | 5 | 5 | 0 | 0 | 0 | 0 | Z3+Z4 |
| 4 | 5 | 5 | 5 | 0 | 0 | 0 | 0 | Z3+Z4 |
| 5 | 5 | 5 | 5 | 0 | 0 | 0 | 0 | Z3+Z4 |
| **獨特** | **5** | **5** | **4 結案** | **1** | **0** | **0** | **0** | — |

### 累計轉換率（最終）

| 階段 | 比率 | 解讀 |
|------|------|------|
| Candidate → Research | 100% | 閘門有跑 |
| Research → Reject | 高 | 紀律健康（Meta：Gate 不過保守） |
| Research → Draft | 1/5 = 20% | 少而精 |
| Draft → Publish | 0/1 = 0% | **人流程瓶頸** |
| Publish → Adopt | n/a | |
| Adopt → Impact | n/a | |

### Meta Review

| 項目 | 結果 |
|------|------|
| 觸發 | Round 2–3 連續零 Draft |
| 文件 | [`rounds/2026-07-15-meta-after-round-3.md`](rounds/2026-07-15-meta-after-round-3.md) |
| 主因 | 對外問池部分成熟 + Draft→Publish 瓶頸；**B 否**（Gate 不過保守） |

### 機制效益日誌（最終）

| Round | 類型 | 說明 |
|-------|------|------|
| 1 | 避免錯決策 | 未外問 hybrid／ledger／文件膨脹；共用池內部化 |
| 1 | 品質提升 | 單一 Draft＝QR-005 |
| 2–5 | 避免錯決策 | 持續拒 KPI Draft；未加 Journal／KB |
| 3 | 品質提升 | Meta 將零 Draft 變成可檢驗假設 |
| 5 | 回顧 | Retrospective：**不引入**新治理模組 |

### 零 Draft 原因碼

`Z1` closed · `Z2` internal · `Z3` reopen none · `Z4` pipeline wait · `Z5` no signal

---

## ERS-001：預付 reservation vs 出席扣

| 欄位 | 內容 |
|------|------|
| QR | QR-005 |
| Draft | [`drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md`](drafts/2026-07-15-01-prepaid-reservation-vs-attendance-debit.md) |
| Funnel | **Draft**（Evidence phase 結束時仍未 Publish） |
| 生命週期 | `draft` |
| D1–D5 | D1=2；D2–D5=0 |
| 合計 | 2/10（不計健康平均） |
| 阻塞 | 待人 Publish 或 abandoned |

---

## Round 5 結論（摘要）

詳見 Retrospective。

| 問題 | 答案 |
|------|------|
| Loop 實際價值？ | **高品質 Reject／聚焦**已證明；社群→Impact **未證明** |
| 明確流程缺口需新模組？ | **否**（缺口＝Publish） |
| Decision Journal／KB？ | **不引入** |
| 下一步？ | 維持 Loop；人處理 Draft 01；valuable≥5 再觸發 |

Evidence phase **關閉**。後續輪次重置為常規触发（非連續湊輪）。
