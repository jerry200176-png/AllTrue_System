---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-05-24
---

# AllTrue SRE Policy — SLI / SLO / Error Budget / Release Freeze

> **狀態**：v1（2026-05-23）｜**權威來源**：本檔｜**Epic**：#469 ｜**子 issue**：#483 #484
> **下次審視**：2026-08-23（每季）或 SLO miss 後 7 天內

本檔定義 AllTrue 補習班管理系統的服務指標（SLI）、服務目標（SLO）、誤差預算（Error Budget）、與穩定度惡化時的功能凍結（Release Freeze）規則。

## 為什麼需要這份文件

- 過去事故（A-F）多源自「沒有客觀指標前提下繼續推 feature」。
- 主任 / 老師 / 家長依賴本系統處理刷卡、評量、繳費，**靜默 5xx 30 分鐘** = 該分校當天的營運紀錄全毀。
- 「stop the line」需要明確政策，否則 AI 與工程師會繼續推 feature。

---

## SLI 目錄（Service Level Indicators）

| ID | 名稱 | 量測方式 | 來源 |
|---|------|---------|------|
| SLI-01 | Health endpoint 可用率 | UptimeRobot 5min 探測 `GET /api/v1/health` 回 200 + `status=ok` 比率 | UptimeRobot |
| SLI-02 | RFID 刷卡延遲 P95 | Sentry transaction `POST /api/v1/swipe-rfid` p95 | Sentry |
| SLI-03 | 主任 Dashboard 延遲 P95 | Sentry transaction `GET /api/v1/director/dashboard` p95（或 alerts/tuition） | Sentry |
| SLI-04 | API 5xx 率 | Pi nginx access log + Laravel log 中 status≥500 / 全部 ratio（日彙總） | Pi log（#485）|
| SLI-05 | Deploy 後 smoke 通過率 | `deploy.yml` 結尾 smoke step 成功率（30 日滾動） | GitHub Actions |

---

## SLO（Service Level Objectives）

| SLI | SLO（30 日滾動） | 違反時的告警通道 |
|---|---|---|
| SLI-01 Health 可用率 | **≥ 99.5%**（30 日 ≈ 3.6 小時 downtime budget） | UptimeRobot → CEO LINE |
| SLI-02 RFID p95 | **≤ 500 ms** | Sentry weekly report |
| SLI-03 Dashboard p95 | **≤ 2 s** | Sentry weekly report |
| SLI-04 5xx 率 | **≤ 0.5%** of 24h requests | 每日 cron digest（#485） |
| SLI-05 Smoke 通過率 | **≥ 95%** of deploys | 連續 3 次失敗 → 自動 issue |

> **基線**：v1 採保守值（小型 B2B SaaS 行業常見區間）。第 1 個 quarter 收集真實數據後再校準。

---

## Error Budget

**月度 budget**：每月 `1 - SLO` = 例如 SLI-01 允許 ~3.6 小時不可用。

**消耗追蹤**：每月 1 日由 CEO 或被指派人從 UptimeRobot + Sentry + Actions log 匯整，貼入本檔下方 §「月度紀錄」。

---

## Release Freeze 政策

當任一 SLO 連續 14 天 miss、或月度 error budget 用罄超過 50%，自動觸發 **Release Freeze**：

### Freeze 期間規則
1. **允許 merge**：`fix/*`、`hotfix/*`、`chore/security-*`、`docs/*`（不含 feat）
2. **禁止 merge**：`feat/*`、`td-batch*-feat-*`、任何標 `feature` label 的 PR
3. **Epic 工作**：暫停 SLA；以「降低 SLI-04 5xx 率」為唯一驗收標準
4. **解除條件**：連續 7 天 SLO 全部達標 + CEO 書面（GitHub issue）確認

### 例外申請
- 標 `freeze-exception` label + PR 內 Threat Note 寫明「為何不能等」+ CEO approve
- 一個 freeze cycle 最多 2 次例外

---

## 月度紀錄

| 月份 | SLI-01 達成 | SLI-02 達成 | SLI-03 達成 | SLI-04 達成 | SLI-05 達成 | Budget 用量 | Freeze? |
|---|---|---|---|---|---|---|---|
| 2026-05 | _待收集_ | _待收集_ | _待收集_ | _待收集_ | _待收集_ | — | No |

---

## Roadmap（為何 SLO 是保守值）

- **Phase 1（2026-Q3）**：先把 SLI-01 / SLI-05 自動化（UptimeRobot + smoke）→ 收集 30 天真實基線
- **Phase 2（2026-Q4）**：SLI-02 / SLI-03 / SLI-04 接到 Sentry / log digest（#485）後再校準
- **Phase 3（2027-Q1）**：依分校規模差異拆 SLO（興隆 / 新店 / 大安 / 木柵）

## 相關文件

- `docs/OPERATIONS_RUNBOOK.md`：日常維運 SOP
- `docs/SMOKE_TEST_RUNBOOK.md`：smoke test 細節
- `docs/DANGEROUS_OPERATIONS.md`：高風險操作（migration / 還原）
- `.github/workflows/pi-health.yml`：health check cron（已存在）
- `.github/workflows/deploy.yml` §6/7：smoke + rollback（已存在）
