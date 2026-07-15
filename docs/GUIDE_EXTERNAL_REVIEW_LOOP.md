# GUIDE：External Review Loop（外部審查循環）

> **目的**：每完成約五項有價值的工作後，暫停一次、檢視**整個系統**（非僅本 PR），把「真的值得向外請教」的問題寫成 Discussion Draft。  
> **反目標**：不为了產生 Discussion 而產生 Discussion；有充分答案就继续开发。  
> **執行技能**：[`.cursor/skills/alltrue-external-review/SKILL.md`](../.cursor/skills/alltrue-external-review/SKILL.md)  
> **產物目錄**：[`docs/reviews/external-review/`](reviews/external-review/)

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

計數權威來源：[`docs/reviews/external-review/COUNTER.md`](reviews/external-review/COUNTER.md)。

---

## 2. 一輪檢查清單（系統級，非功能級）

1. 是否一直重複修同一類問題？（對照 `AI_REGRESSION_LESSONS` 復發家族 F1–F7）
2. 是否有架構／Workflow／Prompt／測試／需求層面的 Root Cause，而不只是程式錯誤？
3. 是否有自己沒把握、缺乏證據或研究不足的地方？
4. 大型專案、官方文件或成熟產品是否已有更好做法？
5. 是否存在值得向工程社群請教的**高價值**議題？

---

## 3. 強制研究閘門（Draft 前必做）

對每個候選議題，依序查完再決定：

1. 官方文件  
2. GitHub Issues / Discussions  
3. 成熟開源專案或成熟產品（Teachworks、Tutorbase、calendar system design 等）  
4. 相關技術文章  

| 結果 | 動作 |
|------|------|
| 已有充分答案 | **不**建 Draft；寫入 round log「已結案候選」＋結論一句＋來源連結 |
| 仍缺答案／取捨沒共識 | 建 Discussion Draft（**不直接改 production 程式**來「回答」該未知） |

一輪最多 **3** 份 Draft，依對產品／工程品質／UX／長期維護性影響排序。  
零 Draft 是合法、甚至常見的結果。

---

## 4. Discussion Draft 必填欄位

範本：[`docs/reviews/external-review/DRAFT_TEMPLATE.md`](reviews/external-review/DRAFT_TEMPLATE.md)

每份至少包含：

1. 背景  
2. 現有設計  
3. 已研究內容  
4. 為什麼仍然沒有答案  
5. 希望社群提供什麼經驗  
6. 建議發佈平台（Threads、Reddit、GitHub Discussions 等）

檔名：`docs/reviews/external-review/drafts/YYYY-MM-DD-NN-<slug>.md`（NN = 01 最高優先）。

---

## 5. Round Log

每輪必寫：[`docs/reviews/external-review/rounds/`](reviews/external-review/rounds/)  
檔名：`YYYY-MM-DD-round-N.md`

內容：檢視了哪些系統邊界、候選清單、研究結案／Draft 清單、計數重置、是否繼續開發。

---

## 6. 與既有流程的關係

- **不取代** PLAN／ARCH／BUG B1／SEC gates；Draft 是「外部未知」的 artifact，不是授權改碼。  
- 高風險模組（扣堂、繳費、G-007、auth）若 Draft 暗示設計變更 → 仍須 Decision-requiring + 使用者批准後才 DEV。  
- 內部已有答案的議題 → 寫回 `TECH_DEBT` / `AI_REGRESSION` / ADR，**不要**發外問。  
- Long-running 觸發點：見 `.cursor/rules/agent-long-running.mdc` §7。

---

## 7. 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-15 | 初版：觸發、研究閘門、Draft／Round 產物規範 |
