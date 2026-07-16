# GitHub Issues API 403 — 診斷與最小修復（2026-07-16）

## Fact

- `gh api repos/.../issues` → **403** `Resource not accessible by integration`
- 同環境可：`git push` feature branch、`gh pr create/merge`、讀 repo metadata（`X-Accepted-Github-Permissions: metadata:read`）
- `gh api user` 亦 403；installation JWT 路徑 401 → 目前 CLI 以 **GitHub App installation token** 運作，非個人 PAT
- Repo `permissions` 對 Issues REST 呈現 triage/push **false**（與「能推 git／開 PR」並存：不同 API 權限面）

## Inference

403 主因是 **GitHub App 未授予 Issues 權限**（或缺 `issues: write`／org policy 限制 App 寫 issue），**不是** Measure 或 in-app 流程本身阻塞。PR 權限足夠故追蹤可走 PR + in-app。

## 最小權限修復（Owner 操作，不中止 Measure）

**僅申請 AllTrue_System 所需的 Issues Read & write；不得擴大其他不必要權限。**

1. GitHub → Settings → **GitHub Apps**（或 Org → Installed GitHub Apps）→ 找到 Cursor／cloud agent 所用 App  
2. Repository permissions：**Issues = Read & write**（只加這一項）  
3. 保存後 **Re-install / accept new permissions** 於 **`AllTrue_System` only**  
4. 驗證：`gh api repos/jerry200176-png/AllTrue_System/issues?per_page=1` 應 **200**  
5. 驗證通過後再恢復 GitHub Issue 追蹤（comment／label）；在此之前維持 PR + in-app

**不要**：擴大 admin、Actions、Contents 以外已足夠的權限；不要為此改用長期個人 PAT 進 CI；不要改 Trust／Day0。

## 在權限修復前的追蹤方式（已採用）

- in-app case + 公開／internal 留言  
- PR body／commits  
- repo 文件（決策包、PCR 等）  
