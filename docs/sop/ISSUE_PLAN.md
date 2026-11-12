---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-11-11
---

# 開工前計畫格式（Issue Plan）

[`DELIVERY_LIFECYCLE.md`](DELIVERY_LIFECYCLE.md) 階段 4（就緒）要求的計畫，**格式只在本檔定義**；階段文字、角色檔、issue 範本都連到這裡，不再抄一份。對標：GitHub Spec Kit（spec → plan → tasks）、Kiro specs（requirements / design / tasks）、Google design doc。

為什麼要有：2026-11-11 一天內，沒有「不可變更清單」讓 #5200 推翻了既有測試編碼的產品規則；#5186 改 class 名弄壞導覽、#5195 換掉 `<main>`；協調者的 brief 與設計文件相左（#5194）；約一半的 PR 因驗收條件到審查才浮現而審 2–3 輪。Founder 2026-11-11 指示整套 SOP 優化、除產品決策外不必逐項詢問。

## 什麼時候要

- 任何要開工的 issue（功能、bug、重構、維運）。階段 3 的規格（功能或 R3）就是這份計畫的〈問題與證據〉〈驗收條件〉等欄位，不另寫第二份。
- **簡版（5 行）**：修正約 50 行以內且**不動 UI**，可用簡版（沿用 D0 規則）。標記 `<!-- plan:v1 short -->`，五行各一句：`問題：`、`根因：`、`修法：`、`測試：`、`復原：`。**R3 工作一律用完整版**（R3 的定義只看 [`RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md) §Risk classes）；檢查腳本用 `--risk R3` 或 issue 內文的 `Risk-Class: R3` 行，遇到簡版就判不完整。簡版的其餘條件由守門人判斷，腳本量不到行數。
- 純錯字、footer 日期、單一連結（`orchestration.md` 的 fire-and-forget）不需要。

## 完整版

issue 內文放標記 `<!-- plan:v1 -->`，並依序有下列七個標題。每節「做完」的意思：

| 標題 | 做完 = |
|---|---|
| 問題與證據 | 誰、哪個畫面、現在怎麼做；每項事實標「已證實」（附連結／輸出）或「推論」。含根因與〈參考做法〉 |
| 不可變更清單 | 列出現有規則、編碼該規則的現有測試，以及依賴者：page guide 選擇器、匯出、告警、家長頁、e2e id、landmark／a11y。搜過程式碼再寫；真的沒有就寫「已搜 X，無」 |
| 範圍與不做的事 | 會改的檔案或模組，加上明列不做的事；沒列的不做 |
| 驗收條件 | 每條寫成可變成測試的一句話（輸入、動作、預期）；不可變更清單的每項都有對應條件 |
| PR 拆分 | 每個 PR 的預估大小（在 presubmit 上限內）、風險等級（`pr_declaration.py` 判定）、審查角色與模型（車道表） |
| 上線與復原 | 怎麼上線、怎麼驗證、怎麼退回；R3 或動 schema 的寫恢復範圍 |
| 待 Founder 決定 | 只有產品、金額、權限政策；一次問完，每題最多 2 個選項＋推薦。技術或證據不足不放這裡。沒有就寫「無」 |

範本：[`.github/ISSUE_TEMPLATE/04_plan.md`](../../.github/ISSUE_TEMPLATE/04_plan.md)。

## 檢查與關卡

- `node scripts/ci/issue-plan-check.mjs --issue <N>`（或 `--file`／stdin；`--json`）。只檢查標記、標題在不在、內容是不是空的或 TBD；**不評品質**。目前是 advisory，沒有 workflow 呼叫它，也不擋合併。
- 階段 4：`tech-lead`、`qa-lead` 先跑檢查；計畫不完整就不給 `gate:ready`。
- 階段 6：審查者對照 PR diff，把〈驗收條件〉與〈不可變更清單〉逐項標「met／not met」。
- 協調者交給實作者的 brief 與計畫相左時，以計畫為準，實作者要說出來（`docs/agents/orchestration.md`）。
- brief 要寫明：CI 卡在排隊沒有 job，或因基礎設施失敗時，重跑失敗的 job 或回報協調者；不要推空 commit 來重觸發 CI。
