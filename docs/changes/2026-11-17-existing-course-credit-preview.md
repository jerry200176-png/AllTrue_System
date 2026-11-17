## 2026-11-17 — feat(billing): reuse paid-credit guards for existing-target allocation preview
<!-- release-notes: silent_ship=silent-2026-11-17-existing-course-credit-preview -->
<!-- silent-reason: 交易內服務尚未接上 API、實際轉用或操作介面。 -->

- 整批既有課程預覽沿用來源付款、原約定單價、堂長與扣堂守衛；目的課程保留已上堂數，已有帳單或收款回報時拒絕重複配置。
- 全數已排課的來源須明確選擇恰好所需的未來未上堂次，且不得存在教學、出勤、帳務或具日期排課參照；預覽不取消任何堂次或變更金額。
- 預覽繫結完整來源與目的狀態，排序不影響確認內容。既有新增批次轉用仍禁止使用已排堂數；未完成寫入、整批還原與介面前，不表示本回報已交付。
