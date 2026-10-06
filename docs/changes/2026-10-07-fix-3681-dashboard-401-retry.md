## 2026-10-07 — fix(frontend): 主任總覽首次載入 401 不再出現（#3681）
<!-- release-notes: silent_ship=silent-2026-10-07-fix-3681-dashboard-401 -->
<!-- silent-reason: 只在背景補送一次授權請求，偶發失敗的機率下降，畫面與操作不變。 -->
- 主任總覽的 API 請求改用即時 session token，遇到 401 時以重新取得的 token 重試一次。
