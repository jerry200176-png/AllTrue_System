## 2026-10-06 — chore(ops): POP operation to bill lessons taught after a monthly contract ended (dry-run first)
<!-- release-notes: silent_ship=silent-2026-10-06-pop-unbilled-backlog -->
<!-- silent-reason: 營運修復工具，需 Founder 逐校核准後才執行；執行並驗證後另發教職員卡 -->
- 新增 POP 營運工具 `unbilled-backlog-catchup-20261006`：先 dry-run 逐校列出合約結束後已上課卻未開單的堂數，經 Founder 核准後才補開帳單。
