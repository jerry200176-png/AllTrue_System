# GUIDE：External Review（日常開發流程）

> **定位**：自 2026-07-15 起，External Review **不是獨立實驗**，而是日常開發的一部分。  
> **目的**：在高價值未知出現時，用研究閘門提升決策品質與產品成果。  
> **反目標**：為了流程而流程、以輪次為目標、沒有議題也硬觸發、為 KPI 產 Draft。  
> **技能**：[`.cursor/skills/alltrue-external-review/SKILL.md`](../.cursor/skills/alltrue-external-review/SKILL.md)  
> **產物**：[`docs/reviews/external-review/`](reviews/external-review/)

**真正的成功指標**：是否持續提升**決策品質**與**產品成果**（Adopt／Impact、避免錯決策）— 不是 session 次數、不是 Draft 數量。

Evidence phase（Rounds 1–5）已結束 → [`rounds/2026-07-15-round-5-retrospective.md`](reviews/external-review/rounds/2026-07-15-round-5-retrospective.md)。Journal／KB **不引入**。

---

## 1. 何時觸發（事件驅動）

**有下列之一 → 觸發一次 External Review session；否則不觸發、繼續開發。**

| 觸發 | 說明 |
|------|------|
| 高價值未知 | 方案取捨會顯著影響營運／金錢／安全／多校區正確性，且內部無凍結不變式 |
| 缺乏證據 | ARCH／BUG／高風險改動前，研究不足、或 Fact／Inference／Hypothesis 分不清 |
| 重大架構決策 | Decision-requiring：扣堂、繳費、物化、auth、雙真相收斂等，外部可能有可驗證戰經 |
| 使用者明確要求 | 仍可手動觸發 |

**不觸發**（即使很久沒開 session）：

- 常規 bugfix／小 UI／已知 F 族點修且不變式已有  
- 僅想「湊流程」或「很久沒跑了」  
- Registry 無 `reopen_candidate`，且當前工作無新未知  

~~每 N 項有價值工作強制暫停~~ → **已廢止**（不以計數／輪次為目標）。

---

## 2. Session 檢查清單

1. 這個未知是否真的高價值？不足以影響決策 → **結束，不開 session 產物**  
2. 掃 [`QUESTION_REGISTRY.md`](reviews/external-review/QUESTION_REGISTRY.md) reopen  
3. Candidate → Research（官方／Issues／成熟產品／文章）→ **Reject 或 Draft**  
4. Blind Spot（最沒把握／最缺證據／最可能判錯）  
5. 記 Funnel＋機制效益（避免錯決策／產品影響）→ [`SCORECARD.md`](reviews/external-review/SCORECARD.md)  

沒有符合條件的議題 → **記一句 Reject／不觸發理由即可，或完全不建檔**。

---

## 3. 研究閘門與 Draft

| 結果 | 動作 |
|------|------|
| 充分答案／內部決策 | **Reject** + Registry；不建 Draft |
| 仍無答案 | Draft（≤3）+ 品質欄位 + ERS；**不改 production 代替未知** |

Draft 範本：[`DRAFT_TEMPLATE.md`](reviews/external-review/DRAFT_TEMPLATE.md)（Impact／Confidence／Evidence F·I·H／Rejected Alternatives）。

連續多次觸發皆零 Draft → 可做 Meta Review（成熟？Gate 過保守？Blind Spot？）— **不是**產 Draft 的藉口。

---

## 4. 產物（輕量）

| 產物 | 何時 |
|------|------|
| Registry | 每個正式 Candidate（含 Reject） |
| Session log | 有觸發時；範本 [`ROUND_TEMPLATE.md`](reviews/external-review/ROUND_TEMPLATE.md)（檔名可用 `YYYY-MM-DD-session-<slug>.md`） |
| SCORECARD Funnel／ERS | 有 Candidate 或 Draft 狀態推進時 |
| Meta／Retrospective | 僅異常模式或節奏性回顧需要時 — **非每 N 次義務** |

狀態總覽：[`STATUS.md`](reviews/external-review/STATUS.md)（取代以輪次為目標的計數器）。

---

## 5. 成功指標（日常）

| 看什麼 | 不看什麼 |
|--------|----------|
| Publish→Adopt→Impact 是否發生 | session／輪次次數 |
| 錯決策是否被挡住（機制效益） | Draft 產量 |
| ERS D3–D5 是否上升 | 「有沒有定期跑」 |

---

## 6. 與既有流程

- 不取代 PLAN／ARCH／BUG／SEC；Draft ≠ 授權改碼  
- 高風險暗示設計變更 → 仍 Decision-requiring + 使用者批准  
- Long-running：`.cursor/rules/agent-long-running.mdc` §7  

---

## 7. 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-15 | 初版～Evidence phase R1–5（見 archive round logs） |
| 2026-07-15 | **正式納入日常**：事件驅動觸發；廢止輪次目標與 valuable≥5 強制暫停 |
