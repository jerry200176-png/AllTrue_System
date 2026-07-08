# Release Execution Package — 工程標準

> **權威**：CEO Reliability Engineering Phase（2026-07-09）  
> **用途**：任何 production 變更（deploy、migration、資料修復）在執行前，須提交本套件並取得單次核准。

---

## 1. 何時需要

| 變更類型 | 需要 REP |
|----------|----------|
| PR merge → deploy.yml | ✅（deployable diff） |
| Production migration | ✅ |
| Production 資料修復 artisan | ✅ |
| docs-only merge | ❌ |
| 唯讀 audit / dry-run | ❌ |

---

## 2. 套件章節（必填）

複製以下模板至 `docs/runbooks/<slug>-execution-package.md` 或 `docs/incidents/<slug>-execution-package.md`。

### 2.1 Scope（範圍）

- GitHub issue / in-app bug 編號
- 受影響模組（API、頁面、表）
- In scope / Out of scope
- 程式版本（PR #、commit SHA）

### 2.2 Risk Assessment（風險評估）

| 維度 | 評級 | 說明 |
|------|------|------|
| 資料完整性 | LOW / MED / HIGH | |
| 可用性 | LOW / MED / HIGH | |
| 回滾難度 | LOW / MED / HIGH | |
| 多校區隔離 | PASS / REVIEW | |

已知紅線：堂數扣除、繳費、auth、RFID → 至少 MED，須 SEC review。

### 2.3 Rollback Plan（回滾計畫）

- `git revert` commit 清單
- `php artisan migrate:rollback` 路徑（若有）
- 資料修復：snapshot JSON 路徑 + 還原 SQL / artisan 指令
- 回滾後驗證步驟

### 2.4 Validation Procedure（驗證程序）

- CI 必過測試清單
- Staging / CI DB 驗證指令
- Production 唯讀驗證查詢（執行前 baseline）

### 2.5 Production Checklist（執行清單）

```
[ ] DB 備份完成（mysqldump 路徑 + 時間戳）
[ ] HEAD commit 與 REP 一致
[ ] ALLOW_PROD_REPAIR=1（資料修復時；執行後移除）
[ ] 維護視窗 / 使用者通知（若需要）
[ ] 執行指令（見 §Execution Commands）
[ ] 執行後驗證（見 §Success Criteria）
[ ] ALLOW_PROD_REPAIR 還原 / snapshot 歸檔
```

### 2.6 Execution Commands（執行指令）

逐步、可複製貼上的完整指令（含 SSH 路徑 `/home/admin/backend`）。

### 2.7 Success Criteria（成功標準）

可客觀判定 PASS/FAIL 的條件（計數 = 0、status = ok、特定列 before/after）。

### 2.8 Post-Deployment Verification（事後驗證）

- `curl /api/v1/health`
- `version.json` 時間戳（前端有 deploy 時）
- Smoke test：刷卡、主任登入、今日排課
- 24h 監控項目

### 2.9 Estimated Time & User Impact

| 項目 | 估計 |
|------|------|
| 執行時間 | |
| 預期 downtime | 通常 0（線上 migration / 單筆修復） |
| 使用者可見影響 | |

---

## 3. 核准流程

```
工程師完成 REP → CEO / 授權人單次核准 → 執行 §2.6 → §2.8 驗證 → 關閉 issue / 更新 CHANGELOG
```

**禁止**：REP 未核准即 production 寫入（含 `migrate --force`、repair `--execute`）。

---

## 4. 現有 REP 索引

| 項目 | 文件 |
|------|------|
| #957 D1 unique index | [`runbooks/957-d1-deploy-runbook.md`](runbooks/957-d1-deploy-runbook.md) |
| #189 / #191 Batch 0 | [`incidents/189-191-execution-package.md`](incidents/189-191-execution-package.md) |
| #190 歷史帳務 | [`incidents/190-billing-technical-options.md`](incidents/190-billing-technical-options.md)（選項，待業務核准） |

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | CEO Reliability Engineering Phase 初版 |
