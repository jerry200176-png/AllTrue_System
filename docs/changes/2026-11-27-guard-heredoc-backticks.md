## 2026-11-27 — chore(hooks): 擋下未加引號 heredoc 裡的反引號
<!-- release-notes: silent_ship=silent-2026-11-27-guard-heredoc-backticks -->
<!-- silent-reason: 只改開發工具（agent 的 Bash 守門員），教職員看不到差異 -->
- 用 `<<EOF`（沒加引號）寫 PR 說明時，裡面的反引號會被 shell 當成指令執行，文字整段消失（發生過兩次）。`.claude/hooks/guard_bash.py` 現在直接擋下這種寫法，並提示改用 `<<'EOF'` 或 `--body-file`。
