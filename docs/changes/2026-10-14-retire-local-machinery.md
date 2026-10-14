## 2026-10-14 — ci: 淘汰 Agent Session Provenance、本機 preflight 路徑白名單與 Exo 程式引用
<!-- release-notes: silent_ship=silent-2026-10-14-retire-local-machinery -->
<!-- silent-reason: 只改 CI／治理腳本與測試，沒有任何使用者可見或執行期變更。 -->
- 依 Founder 2026-10-14（三）第 6 條：刪除 `check-agent-provenance.sh`、`ci-preflight` 的 provenance 檢查與 `.agent-session/human-authored*.json`。main ruleset 仍要求「Agent Session Provenance」，所以 `agent-provenance.yml` 保留同名 job 改為直接通過（land queue 照常 dispatch），等 ruleset 移除該項後再刪。
- `make agent-preflight` 不再要求 `/home/jerry/workspace/tasks/...` 路徑白名單，cloud clone 可直接通過；保留乾淨工作樹、分支命名（改用 `scripts/ci/branch-policy.mjs`）、base 等於 `origin/main`、禁止 legacy checkout 等檢查。
- `.exo/` 加入 `.gitignore`、測試不再以 `.exo/` 路徑當範例；已追蹤的 `.exo/` 檔案另案移除。
