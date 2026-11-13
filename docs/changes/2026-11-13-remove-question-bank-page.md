## 2026-11-13 — chore: remove unused question-bank authoring page and API
<!-- release-notes: silent_ship=silent-2026-11-13-remove-question-bank-page -->
<!-- silent-reason: 頁面從未出現在導覽，全校區 0 筆資料；使用者畫面不變 -->
- Removed the 題庫 page (never linked from navigation; 0 question banks across all 14 campuses), its API routes, controller, models and tests. Migrations and tables stay (see TD-087); nothing else read them.
