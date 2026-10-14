## 2026-10-13 — fix(binding): close guardian-revocation gaps left by #3944
<!-- release-notes: silent_ship=silent-2026-10-13-guardian-revocation-gaps-3944 -->
<!-- silent-reason: 補齊 #3944「解除綁定收回權限」的兩個漏洞；教職員看到的行為已由 staff-2026-10-13-unbind-revokes-guardian-access 公告，此處無新增操作差異 -->
- 跨分校同一學生（identity group）：LINE 登入的家長 session 每次存取都會重新確認此 LINE 帳號對「目標學生」仍有監護權限；解除 A 的綁定後，即使 token 錨定在仍綁定的 B，也無法再讀取或操作 A（儀表板、繳費紀錄、切換學生、請假、學習紀錄回饋）。手機登入 session 規則不變。
- 每日 `bindings:cleanup-orphans` 改走與手動解除綁定相同的收回流程：同一個 transaction 內撤銷監護人連結、讓該 LINE 帳號的家長 session 過期，再刪除綁定。
