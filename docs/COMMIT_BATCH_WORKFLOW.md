# Commit Batch Workflow (避免單一巨大變更)

本文定義 AllTrue 專案的「逐批 commit」SOP，目標是避免每次改完功能都出現單一巨大 commit（例如 `+284 -1`），提升 review 與回滾效率。

## 目標

- 每個 commit 只做一件事（single purpose）
- 每個 commit 可獨立理解、測試、回滾
- 避免把「功能改動 + 文件 + deploy 產物 + 其他歷史變更」混在同一筆

## 何時切批次

同一個需求至少切成以下批次（依實際情況可增減）：

1. `backend logic`：Controller / Service / Model / migration
2. `frontend behavior`：page / composable / API client
3. `tests`：Feature / Unit / regression
4. `docs`：CHANGELOG / lessons / runbook
5. `deploy artifact`（若必要）：`backend/public/index.html` 與 assets 同步

## 每一批的固定操作

1. 先看變更範圍  
   `git status --short`

2. 只 stage 該批次檔案（不要 `git add .`）  
   例：  
   `git add backend/app/Http/Controllers/FooController.php backend/app/Services/FooService.php`

3. 確認 staged 內容乾淨  
   `git diff --cached --stat`  
   `git diff --cached`

4. 跑最小必要驗證（該批次對應）  
   - backend：`php artisan test --filter=...` 或對應測試檔  
   - frontend：必要 smoke / lint  

5. commit（訊息描述「為什麼」而非只列「改了什麼」）  
   建議格式：
   - `fix(course): ...`
   - `feat(payroll): ...`
   - `test(course): ...`
   - `docs(runbook): ...`

## 建議 commit 粒度（實務標準）

- 理想：`20~120` 行變更 / commit（視情境）
- 可接受：單檔大改，但必須單一目的、附測試
- 警訊：一次 commit 同時碰 backend + frontend + tests + docs + deploy 產物

## 本次案例（請假/調課警示）

建議拆法：

1. `fix(frontend):` 警示口徑改為有效堂次（排除 leave/cancelled）
2. `test(backend):` 新增 `SessionCountWarningTest`（CaseA~E）
3. `chore(ops):` 診斷旗標與 mismatch log
4. `docs:` CHANGELOG + AI lessons + runbook SOP
5. `build(frontend):` deploy 產物（若有）

## 防呆規則

- 禁止習慣性 `git add .`
- commit 前必看 `git diff --cached --name-only`
- 若工作樹本身很髒，先只挑目標檔案 stage
- 若同一檔案同時有「本次改動 + 舊改動」，先以最小可提交單位切開（必要時再開新 commit 補）

## 回滾效率要求

若某批次上線異常，應可只回退單一 commit（不影響其他批次）。
這是為什麼要分批 commit，而不是全部壓成一筆。
