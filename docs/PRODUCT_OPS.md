# AllTrue Product Ops — Post-release Metrics Review SOP

> **狀態**：v1（2026-05-23）｜**權威來源**：本檔｜**Epic**：#469 ｜**子 issue**：#489 #490
> 本檔規範 feature merge / 上線後，**T+7 / T+14 / T+30** 三個檢核點如何回看指標，避免 ship 完就忘。

## 為什麼

- Epic #457 等大型 feature 多有「上線 → 沒人追指標 → 三個月後才發現未達標」現象。
- 大公司（Stripe / Linear / Notion）的 product ops 都有固定 retrospect 節奏。

## 適用範圍

- 所有 **T2 / T3 feature**（影響商業流程、UI、家長/老師體驗）
- 所有以 Epic 形式管理的多 PR 工作項
- 不適用：純 bug fix（走 `bug-fix-plan.mdc`）、docs-only、CI/infra 變更

## 流程

### 1. Merge 當下（owner = PR 作者或 Epic owner）
- [ ] PR description 含 `Success Metrics` 區塊（指標 + baseline + target）
- [ ] 開一張 `metrics-review` issue，標題 `Metrics Review: <feature> T+7/T+14`，body 含：
  - 觀察期間
  - 量測管道（adoption insights endpoint / 後台報表 / log digest）
  - 三個檢核點日期

### 2. T+7 檢核
- 量測：實際 vs target；任何 critical metric 為 0 → 立即 root cause
- 決策：`keep` / `iterate` / `rollback`
- 把當週數據 + 決策貼回 issue

### 3. T+14 檢核
- 同上；若 `iterate`，列出後續 follow-up（新 issue / 加入 TECH_DEBT）

### 4. T+30 收尾
- 寫一段 takeaway，關閉 `metrics-review` issue
- 若指標達標：在 `docs/ADOPTION_QUALITY_METRICS.md` 補一行歷史紀錄
- 若指標未達標：開 retrospective issue，邀使用者（CEO）決策

## 模板

```markdown
## Metrics Review: <Feature Name> — T+7

**Merge date**: YYYY-MM-DD
**Target**：<指標> ≥ <值>（例：教師當週至少 3 次評量提交比率 ≥ 60%）
**Baseline**：<上週 / 上月>
**T+7 actual**：<數字>
**Decision**：keep / iterate / rollback
**Notes**：<2–3 句，為什麼>
```

## 與其他 SOP 的關係

- **Bug**：使用者報的 bug → `bug-fix-plan.mdc` + in-app bug system
- **SLO miss**：→ `SRE_POLICY.md` Release Freeze
- **Feature metric miss**：→ 本檔的 iterate / rollback 決策
- **長期 KPI**：→ `ADOPTION_QUALITY_METRICS.md`

## 範例：Epic #457 第一次填寫（給未來 reference）

| Feature | Target | T+7 | T+14 | Decision |
|---|---|---|---|---|
| #458 staff mission center | 主任當週啟用 ≥ 50% | _待填_ | _待填_ | _待定_ |
| #459 parent feedback v1 | 家長 7 日回覆率 ≥ 20% | _待填_ | _待填_ | _待定_ |
| #461 enterprise visual polish | 主觀專業感 pulse ≥ 4.0/5 | 依 #490 pulse 上線後測 | — | — |

---

## 子 Issue：#490 Perception Pulse Survey

- 設計細節寫在 `docs/PROFESSIONAL_PERCEPTION_SURVEY.md`（同一個 PR 一併建立）
- 本 SOP 引用該 survey 作為 #461 KPI 的量測管道
