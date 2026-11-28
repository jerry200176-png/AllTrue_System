## 2026-11-26 — chore(hooks): agent 推送前自動跑快速檢查
<!-- release-notes: silent_ship=silent-2026-11-26-agent-prepush-guard -->
<!-- silent-reason: 只改開發工具（agent 的 Bash 守門員與推送前檢查腳本），教職員看不到差異 -->
- 推送前的快速檢查原本靠 lefthook，但 agent 的環境沒有安裝它，檢查其實沒跑。現在 `.claude/hooks/guard_bash.py` 在每次 `git push` 前執行 `scripts/agent-prepush.sh`，沒過就擋下並印出修法。
- 檢查清單只放在 `scripts/agent-prepush.sh`；`lefthook.yml` 改成呼叫同一支腳本，並加入 `ci-preflight`。之後新增快速檢查只改這支腳本。
