## 2026-11-13 — chore: remove unused learning-assessment authoring page and API
<!-- release-notes: silent_ship=silent-2026-11-13-remove-learning-assessment-page -->
<!-- silent-reason: 頁面從未出現在導覽，全校區 0 筆資料；使用者畫面不變 -->
- Removed the 學習檢測 page (never linked from navigation; 0 assessments across all campuses), its API routes, controller, service and tests. The parent portal and student learning timeline are unchanged; the underlying tables and models stay (see TD-086).
