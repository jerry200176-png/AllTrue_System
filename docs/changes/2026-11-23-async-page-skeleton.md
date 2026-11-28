## 2026-11-23 — fix(ui): 修正頁面載入骨架閃現
<!-- release-notes: silent_ship=silent-2026-11-23-async-page-skeleton -->
<!-- silent-reason: 僅調整頁面載入提示的顯示時間，使用流程與操作方式照舊。 -->

- 頁面在 300 毫秒內載入完成時略過骨架。骨架顯示後至少保留 300 毫秒，避免一閃即逝。
