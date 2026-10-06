## 2026-10-06 — refactor(course-management): 合約調整／撤銷調整流程抽成 useContractAmendment
<!-- release-notes: silent_ship=silent-2026-10-06-contract-amendment-composable -->
<!-- silent-reason: 內部重構：調整合約總堂數與撤銷調整的程式搬到共用 composable，請求、文案與畫面不變，教職員看不到差異 -->
- 從 `CourseManagement.vue` 搬出 contract-amendment（預覽／送出）與 revert（預覽／送出）流程到 `composables/course-management/useContractAmendment.js`，並新增 vitest 釘住 URL、payload、過期預覽檢查與錯誤文案。
