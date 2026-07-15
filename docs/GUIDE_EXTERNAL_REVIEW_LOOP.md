# GUIDE：External Review Loop（外部審查循環）

> **目的**：每完成約五項有價值的工作後，暫停一次、檢視**整個系統**（非僅本 PR），把「真的值得向外請教」的問題寫成 Discussion Draft。  
> **反目標**：不为了產生 Discussion 而產生 Discussion；有充分答案就继续开发。  
> **執行技能**：[`.cursor/skills/alltrue-external-review/SKILL.md`](../.cursor/skills/alltrue-external-review/SKILL.md)  
> **產物目錄**：[`docs/reviews/external-review/`](reviews/external-review/)

**成熟度約定（2026-07-15）**：先把本流程跑滿 **3～5 輪**並看 [`SCORECARD.md`](reviews/external-review/SCORECARD.md) 成效，再決定是否加 Decision Journal／Knowledge Base。暫時不新增平行模組。

---

## 1. 觸發條件

| 條件 | 動作 |
|------|------|
| 有價值工作計數 ≥ 5（自上一輪以來） | 暫停開發流程，跑本 Loop 一輪 |
| 使用者明確要求「外部審查 / External Review」 | 立即跑一輪（不重置門檻意義；仍寫 round log） |
| 本輪安裝／首次落地 | 允許 bootstrap 一輪（本檔就緒後的 Round 1） |

**計為「有價值工作」**（+1）：

- 可合併的功能／bugfix PR（含測試）
- 關閉一個有程式變更的 in-app bug（含回歸）
- 架構決策落地（ADR merge、或 Decision-requiring 實作完成）
- 技術債清償（Open → Done 且有 code/test）
- Production 事故修復（含 PCR／incident 結案）

**不計數**：錯字、純連結、docs typo、單獨 CHANGELOG 潤飾、未合併的探索草稿。

計數權威來源：[`COUNTER.md`](reviews/external-review/COUNTER.md)。

---

## 2. 一輪檢查清單（系統級，非功能級）

1. 是否一直重複修同一類問題？（對照 `AI_REGRESSION_LESSONS` 復發家族 F1–F7）
2. 是否有架構／Workflow／Prompt／測試／需求層面的 Root Cause，而不只是程式錯誤？
3. 是否有自己沒把握、缺乏證據或研究不足的地方？
4. 大型專案、官方文件或成熟產品是否已有更好做法？
5. 是否存在值得向工程社群請教的**高價值**議題？
6. 掃 [`QUESTION_REGISTRY.md`](reviews/external-review/QUESTION_REGISTRY.md) 是否有 `reopen_candidate`

---

## 3. 強制研究閘門（Draft 前必做）

對每個候選議題，依序查完再決定：

1. 官方文件  
2. GitHub Issues / Discussions  
3. 成熟開源專案或成熟產品（Teachworks、Tutorbase、calendar system design 等）  
4. 相關技術文章  

| 結果 | 動作 |
|------|------|
| 已有充分答案 | **不**建 Draft；登記 Question Registry（`closed_*`）+ round log 摘要 |
| 仍缺答案／取捨沒共識 | 建 Discussion Draft（**不直接改 production 程式**）+ Registry `draft` + SCORECARD 新列 |

一輪最多 **3** 份 Draft，依影響排序。零 Draft 合法。

---

## 4. Question Registry

權威檔：[`QUESTION_REGISTRY.md`](reviews/external-review/QUESTION_REGISTRY.md)

每筆候選必含：為何不發問（或為何仍要問）、結論文、**未來重新評估條件**。  
Round log 只摘要 QR 編號；細節不複製兩份。

---

## 5. Discussion Draft 必填欄位

範本：[`DRAFT_TEMPLATE.md`](reviews/external-review/DRAFT_TEMPLATE.md)

| # | 欄位 |
|---|------|
| 1 | 背景 |
| 2 | 現有設計 |
| 3 | 已研究內容 |
| 4 | 為什麼仍然沒有答案 |
| 5 | 希望社群提供什麼經驗 |
| 6 | 建議發佈平台 |
| 7 | **Business / User / Engineering Impact** + 預期改善 +「為何值得花社群時間」 |
| 8 | **Confidence Score**（0–5；Ready to post 須 ≥3） |
| 9 | **Evidence Summary**（每條標 Fact／Inference／Hypothesis） |
| 10 | **Rejected Alternatives** |
| 11 | Registry + Scorecard 連結 |

檔名：`drafts/YYYY-MM-DD-NN-<slug>.md`（NN = 01 最高優先）。

---

## 6. Round Log + Blind Spot Review

範本：[`ROUND_TEMPLATE.md`](reviews/external-review/ROUND_TEMPLATE.md)  
目錄：[`rounds/`](reviews/external-review/rounds/)  
檔名：`YYYY-MM-DD-round-N.md`

除候選／Draft／計數外，**每輪結尾必寫 Blind Spot Review**：

| 類型 | 要求 |
|------|------|
| 最沒有把握 | 即使有 Draft／結案，指出最大不確定 |
| 最缺乏證據 | 指出缺度量或缺來源之處 |
| 最可能判斷錯誤 | 指出本輪最可能翻盤的結論 |

零 Draft 輪次仍要寫 Blind Spot——盲點審查不是 Discussion 的附錄。

---

## 7. External Review Score

權威檔：[`SCORECARD.md`](reviews/external-review/SCORECARD.md)

追蹤每篇 Discussion：`draft → posted → replied → adopted → implemented → measured`。  
分項 D1–D5（發問品質／社群信號／採納／實作／實測成效），合計 0–10。  
每 5 輪做一次機制健康回顧；平均分過低 → **收緊發問**，不加新模組。

---

## 8. 一輪輸出檢查表（Agent Exit）

- [ ] 候選全部寫入／更新 Question Registry  
- [ ] Draft 0–3，且通過 §5 欄位（含 Impact／Confidence／Evidence／Rejected）  
- [ ] Round log 含 Blind Spot Review  
- [ ] SCORECARD 新建或推進 ERS 列  
- [ ] COUNTER 歸零  
- [ ] 未擅自新增 Decision Journal／Knowledge Base  

---

## 9. 與既有流程的關係

- **不取代** PLAN／ARCH／BUG B1／SEC gates；Draft 不是授權改碼。  
- 高風險模組若 Draft 暗示設計變更 → Decision-requiring + 使用者批准後才 DEV。  
- 內部已有答案 → Registry `closed_*` + 必要時 `TECH_DEBT`／ADR／`AI_REGRESSION`，**不要**發外問。  
- Long-running 觸發：`.cursor/rules/agent-long-running.mdc` §7。

---

## 10. 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-15 | 初版：觸發、研究閘門、Draft／Round 產物規範 |
| 2026-07-15 | 品質強化：Question Registry、Draft Impact／Confidence／Evidence、Blind Spot、SCORECARD；約定先跑 3～5 輪 |
