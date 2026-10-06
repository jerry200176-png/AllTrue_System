## 2026-10-06 — refactor(course-management): 轉移堂次流程抽成 useTransferSessions
<!-- release-notes: silent_ship=silent-2026-10-06-transfer-sessions-composable -->
<!-- silent-reason: 內部重構：轉移堂次（含補登已取消堂次）的程式搬到共用 composable，請求、文案與畫面不變，教職員看不到差異 -->
- 從 `CourseManagement.vue` 搬出 transfer-sessions／recover-transfer-sessions 與目標課程查詢到 `composables/course-management/useTransferSessions.js`，並新增 vitest 釘住 URL、payload、錯誤文案與目標課程過濾。
