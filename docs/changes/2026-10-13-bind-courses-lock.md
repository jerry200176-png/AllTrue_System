## 2026-10-13 — fix(billing): 共用方案綁定課程時重新上鎖檢查（#3908）
<!-- release-notes: silent_ship=silent-2026-10-13-bind-courses-lock -->
<!-- silent-reason: 只改 API `POST /course-packages/{id}/bind-courses`（舊資料搬移用，畫面沒有呼叫）的並發安全。 -->
- 綁定前的檢查以前沒有上鎖；檢查完到寫入之間若有人登記了繳費回報，那門課仍會被綁進共用方案。現在寫入前依「課程 → 方案」順序上鎖（與登記繳費回報相同順序），在鎖住的資料上重跑同一套檢查；狀態有變就整批不綁定，回 422 `bind_state_changed`。
