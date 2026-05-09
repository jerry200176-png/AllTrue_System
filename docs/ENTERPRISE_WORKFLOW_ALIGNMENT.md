# 與大廠式工程流程對齊（AllTrue 現況）

> 單一入口說明：**我們已內建什麼**、**與 Google / Meta 類組織常見模式的對應**、**刻意不抄什麼**（避免拖垮單機 Pi 與小團隊）。  
> 部署與備份細節仍以 [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md)、[`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md) 為準。

---

## 已對齊（repo 內可驗證）

| 大廠常見做法 | AllTrue |
|--------------|---------|
| PR 前自動檢查 | Presubmit（分支名、PR 體積、CHANGELOG 警告、CI workflow 禁 deploy、**Golden § 報告**） |
| 測試與建置 gate | `ci.yml`：PHPUnit、Vite、`npm run test:calendar`、npm/composer audit |
| 敏感路徑 review | `.github/CODEOWNERS` |
| 依賴與供應鏈 | Dependabot；選用 **Dependency Review**（需 GHAS + `ENABLE_DEPENDENCY_REVIEW=true`） |
| 變更可追溯 | `CHANGELOG`、`AI_REGRESSION_LESSONS`、Golden 路徑對照（無手動勾選） |
| 部署不經 CI 本機 | 僅 `deploy.yml` 在 main CI 綠燈後 SSH Pi（見 `auto-frontend-deploy` 規則） |

---

## Production 部署何時會跑（避免「改 CI 卻整包上 Pi」）

`deploy.yml` 僅在合併內容包含下列路徑時視為 **deployable**（見 workflow 內 Python 邏輯）：

- 前綴：`backend/`、`frontend/`、`scripts/`
- 或檔案：`composer.json`、`composer.lock`、**僅** `.github/workflows/deploy.yml`

因此：**僅改** `.github/workflows/ci.yml`、`.github/workflows/presubmit.yml`、新增 `.github/scripts/*`、`docs/*` 等 → **不觸發** Pi 部署。  
CI 專用腳本應放在 **`.github/scripts/`**，勿放 `scripts/`（否則會觸發 deploy）。

---

## 建議後續（組織在 GitHub UI 開關，無須改程式）

| 時機 | 做法 |
|------|------|
| 常態 ≥2 人在推 `main` | 在 **Branch rules** 啟用 **Merge queue**，減少「各自綠、合併後 main 紅」 |
| 第二位 maintainer | `main` 要求 **至少 1 approval** + 現有 required checks |
| 已購 GHAS | Repo Variables：`ENABLE_DEPENDENCY_REVIEW=true`，並將 Dependency Review job 納入 required checks |

---

## 刻意不抄（規模與架構）

- 多區域 staging / canary：單機 Pi 不強制；大改可另開環境再說。
- 大量 E2E 瀏覽器測試：以 PHPUnit + calendar 單元 + 上線 smoke 為主。
- 在 production Pi 跑 `php artisan test`：**永遠禁止**（P0）。

---

## 備份與資料安全（與「不要弄壞備份／資料」對齊）

- **有 pending migration 時**：`deploy.yml` 會先 **mysqldump 壓縮備份** 再 `migrate --force`（見 deploy 步驟註解）。
- **程式碼**：GitHub 為權威；Pi 上 `git reset --hard origin/main` 對齊 main。
- **還原**：須 **完整還原**檔案／commit，禁止部分還原（P0 規則）。
