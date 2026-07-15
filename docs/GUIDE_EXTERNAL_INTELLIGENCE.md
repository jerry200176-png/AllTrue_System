# GUIDE：External Intelligence（持續學習）

> **Mission**：External Intelligence / Continuous Learning — 與世界交流以驗證設計、挑戰假設、縮短與成熟產品的差距。  
> **前身**：External Review（Phase 1 Evidence）— **成果全部保留**，定位為 **Discussion Quality Gate**，不是系統終點。  
> **技能**：`.cursor/skills/alltrue-external-review/SKILL.md`（檔名保留；行為＝本 GUIDE）  
> **產物目錄**：[`docs/reviews/external-review/`](reviews/external-review/)（路徑保留，避免第二套目錄）  
> **舊檔名**：[`GUIDE_EXTERNAL_REVIEW_LOOP.md`](GUIDE_EXTERNAL_REVIEW_LOOP.md) → stub，指向本檔。

**Phase 1 已證明（不可回滾）** → [`rounds/2026-07-15-round-5-retrospective.md`](reviews/external-review/rounds/2026-07-15-round-5-retrospective.md)

- Research Gate 能過濾低品質問題  
- Registry／Research／Reject／Draft／Retrospective 可運作  
- 不為 KPI／輪次硬凑 Draft  
- **不引入** Decision Journal／Knowledge Base（尚無證據支持）

---

## 0. Migration Plan（External Review → External Intelligence）

> 無縫演進：單一權威、重用產物、零平行流程、零新治理模組。

| 步驟 | 動作 | 重用 |
|------|------|------|
| M1 | Mission／Trigger／成功標準改寫（本檔） | 取代舊 GUIDE 正文；舊檔變 stub |
| M2 | Research Gate → **Discussion Quality Gate** | 同一研究清單；Reject 準則放寬為「低質才 Reject」 |
| M3 | Draft 欄位對齊學習型討論 | 更新既有 `DRAFT_TEMPLATE.md` |
| M4 | Registry 可記 `topic`（值得交流） | 既有 `QUESTION_REGISTRY.md` 擴狀態，不新建 registry |
| M5 | Scorecard → Learning Funnel | 既有 `SCORECARD.md` 換 KPI 欄位；Evidence 表保留為歷史 |
| M6 | STATUS／skill／AGENTS／INDEX 指標 | 仍指向本目錄 |
| M7 | Round 語意封存 | R1–5 只作歷史；日常用 **session**；不為湊 Round 產內容 |

**不做**：第二套 `external-intelligence/` 目錄、Decision Journal、Knowledge Base、平行 SOP。

**相容**：既有 QR-001～005、ERS-001、Draft 01、R1–5 logs **原樣保留**。

---

## 1. 目的（Purpose）

不是修 bug，也不是請社群「幫忙解題」。而是：

1. 驗證設計  
2. 挑戰既有假設  
3. 收集不同觀點  
4. 學習業界實戰經驗  
5. 縮短與世界級產品的差距  
6. 持續提升產品、工程與 Agent 能力  

---

## 2. Trigger（事件驅動）

**Unknown 不是唯一 Trigger。** 符合任一即可建立 Candidate：

| Trigger | 例 |
|---------|-----|
| High-value Discussion Opportunity | 重要 Feature／Arch／UX／Product／Workflow **完成後**，想驗證或挑戰 |
| 成熟產品差異 | 與 Stripe、Linear、GitHub、Vercel、Supabase… 明顯不同，想知道為什麼 |
| 值得分享的設計 | 不是做不出來，而是值得交流／請教 |
| 高價值未知／缺證據／重大架構（Phase 1 保留） | 仍適用 |
| 使用者要求 | 手動 |

**仍不觸發**：為了流程／Round／KPI；小修無學習價值；Registry 無 reopen 且當前工作無機會。

---

## 3. Discussion Quality Gate（原 Research Gate）

**目的**：避免低品質 Discussion — **不是**盡可能 Reject。

研究順序（不變）：官方文件 → Issues／Discussions → 成熟產品／開源 → 技術文章。

| 結果 | 動作 |
|------|------|
| 低質／無交流價值／純內部決策 | **Reject** + Registry |
| 仍有交流價值 | **Draft**（即使 Agent 已有自己的答案／推薦） |

連續多次低質趨勢 → 可選 Meta（Gate 過鬆／過嚴／Blind Spot）— 不強制產 Draft。

---

## 4. Discussion Draft（必填）

範本：[`DRAFT_TEMPLATE.md`](reviews/external-review/DRAFT_TEMPLATE.md)

1. Context  
2. Current Design  
3. Why this topic is worth discussing  
4. Existing research summary（含 Fact／Inference／Hypothesis）  
5. Alternatives considered  
6. Current recommendation  
7. Questions for the community（取捨／坑／更好設計／為何大廠不同）  
8. Expected learning  
9. Recommended platform（Threads、Reddit、GitHub Discussions、Discord…）  

另建議保留：Confidence、Business／User／Engineering Impact（Quality Gate 殘餘）。

---

## 5. Question Registry

權威：[`QUESTION_REGISTRY.md`](reviews/external-review/QUESTION_REGISTRY.md)

| 狀態 | 意義 |
|------|------|
| `closed_researched` | 研究後低質或無需外交流 |
| `closed_internal` | 純內部／CEO 決策 |
| `topic` | **值得交流的 Topic**（可尚未 Draft） |
| `draft` | 已有 Draft |
| `insight_accepted` | 社群洞見已採納 |
| `reopen_candidate` | 觸發重評 |

---

## 6. Learning Funnel（Scorecard）

權威：[`SCORECARD.md`](reviews/external-review/SCORECARD.md)

```
Candidate → Research → Reject → Draft → Publish
  → Community Response → Accepted Insight → Implemented → Measured Product Impact
```

KPI 看**整條學習漏斗**與洞見採納／產品影響 — **不是** Discussion 數量。

---

## 7. Session（非 Round 治理）

- Phase 1「Round」**正式結束**，不再以 Round 為管理單位。  
- 日常：完成重要 Feature／Arch／Workflow／Product Decision／高價值研究後 **自然**觸發 session。  
- 範本仍用 [`ROUND_TEMPLATE.md`](reviews/external-review/ROUND_TEMPLATE.md)（檔名可 `YYYY-MM-DD-session-<slug>.md`）。  
- **不為完成 Round／Session 而產生任何內容。**

狀態：[`STATUS.md`](reviews/external-review/STATUS.md)

---

## 8. 成功標準（重新定義）

成功 **不是** Reject 越多、Draft 越多、發文越多。

成功是：

1. Agent **持續主動**發現值得與世界交流的高價值議題  
2. 社群提供 Agent **原本研究不到**的新觀點  
3. 社群意見經驗證後改善產品／架構／Workflow／決策品質  
4. Agent 因外部交流**修正認知**，而非只驗證自己原本想法  
5. External Intelligence 成為**日常工程流程**一部分，而非一次性治理實驗  

---

## 9. 與既有流程

- 不取代 PLAN／ARCH／BUG／SEC；Draft ≠ 授權改碼  
- 高風險設計變更仍 Decision-requiring + 使用者批准  
- Long-running：`.cursor/rules/agent-long-running.mdc` §7  
- Journal／KB：**維持不導入**，除非日後 Learning Funnel 出現明確缺口且使用者批准  

---

## 10. 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-15 | Phase 1 External Review Evidence（R1–5） |
| 2026-07-15 | 日常事件驅動（Unknown／Blocker） |
| 2026-07-15 | **Migration → External Intelligence**；Quality Gate；Learning Funnel；Round 封存 |
