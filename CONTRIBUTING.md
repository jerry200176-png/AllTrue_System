# Contributing to AllTrue

本文件是 **GitHub 協作入口**：分支、PR、Issue、CI 與安全通報；細部工程規則以 repo 內文件為準。

## 開始之前

1. 讀 [`docs/INDEX.md`](docs/INDEX.md)（導航，避免整本通讀）。
2. 讀 [`AGENTS.md`](AGENTS.md)（AI／人類共用：分支、Commit、風險分級）。
3. 碰觸堂數／繳費／提醒規則前讀 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md)（勿擅自改條件）。
4. 高風險操作清單：[`docs/DANGEROUS_OPERATIONS.md`](docs/DANGEROUS_OPERATIONS.md)。

## 分支與命名

與 [`Presubmit Gate`](.github/workflows/presubmit.yml) 一致，建議前綴：

| 前綴 | 用途 |
|------|------|
| `feat/` | 新功能 |
| `fix/` | Bug 修復 |
| `chore/` | 文件、設定、維護、治理 |
| `td-batch*-` / `td/` | 技術債清償（依團隊習慣） |

從 `main` 開 branch，**禁止**直推 `main`、**禁止** `git push --force` 到受保護分支。

## Pull Request

- 開 PR 時會載入 [`.github/pull_request_template.md`](.github/pull_request_template.md)。
- **多階段 Issue**：描述欄用 **`Refs #123`**；**全部驗收完成的最後一個 PR** 才用 **`Closes #123`**。
- **Merge 前**：`Presubmit Gate`、`CI — PHPUnit Tests`、`Security Scan` 等 required checks 需 **success**（負責人跟到 completed）。
- 變更 `backend/app/`、`backend/routes/`、`frontend/src/` 時更新 [`docs/CHANGELOG.md`](docs/CHANGELOG.md)（docs-only 或純 workflow 可依團隊慣例省略）。

## Issue

- 建立 Issue 時請選模板（Bug／工程變更／Ops）；也可用空白 Issue。
- 機敏資訊勿貼公開 issue；資安見下方 **Security**。

## 與「大公司流程」對齊的最低配套

| 項目 | 說明 |
|------|------|
| **CODEOWNERS** | 敏感路徑自動請求 review（見 [`.github/CODEOWNERS`](.github/CODEOWNERS)）。 |
| **Branch protection** | `main` 上 required checks + 禁止 force push（設定在 GitHub 端）。 |
| **Dependabot** | [`.github/dependabot.yml`](.github/dependabot.yml) 每週／每月自動開升級 PR。 |
| **工作流** | `.github/workflows/`：`ci.yml`、`presubmit.yml`、`deploy.yml` 等；deploy 僅在合併後且有 deployable diff 時執行。 |
| **Dependency Review** | [`.github/workflows/dependency-review.yml`](.github/workflows/dependency-review.yml)：可選用 GitHub 官方的 **dependency-review**（與 `npm audit` / `composer audit` 互補）。需 **Dependency graph + GitHub Advanced Security**（私人 repo 常需付費方案）；啟用後在 **Repo settings → Variables → Actions** 設 `ENABLE_DEPENDENCY_REVIEW=true`，否則 workflow 只發 notice、不阻擋合併。主線供應鏈 gate 仍以 `ci.yml` 的 audit 為準。 |

### 再靠近一點大廠（選配、不強制）

- **Merge queue**：多人協作時在 **Settings → Rules** 開啟，減少「綠燈但合併後 main 紅」。
- **Required reviewers**：第二位 maintainer 出現後，對 `main` 要求至少 1 approve。
- **Staging**：單機 Pi 可維持現狀；若要 staging，另備環境與 deploy workflow 分流（屬架構決策）。

## Security

請閱讀根目錄 [`SECURITY.md`](SECURITY.md)（含漏洞通報方式；完整決策仍見 [`docs/SECURITY.md`](docs/SECURITY.md)）。

## License / 授權

若未另有 `LICENSE` 檔，對外再補授權條款前，請先與 repository owner 確認。
