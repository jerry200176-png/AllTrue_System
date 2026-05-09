# 老師評量速效填寫 + 主任填寫率（PRD 摘要）

1. **文件**：`teacher_learning_quick_fill_prd_2026-05-09.md`
2. **目標**：老師一打開工作台就能看見待填、一鍵開表單；主任可依老師監測評量填寫率。
3. **範圍 In**：TeacherHome UX、評量側欄角標、主任儀表板新區塊、`GET me/learning-pending-summary`、`GET reports/teacher-learning-fill-rates`。**Out**：LINE 推播、評量表單欄位改版。
4. **FR**：`(A)` 待填>0 時顯著 CTA「填寫下一筆」（優先補填>今日待填）。`(B)` 教學工作台「評量」卡片預設導向下一筆待填而非僅總覽。`(C)` 老師行動版評量 Tab 角標顯示待處理筆數。`(D)` 主任進度區顯示近 14 天各授課老師「已到班」堂數與已填評量進度進度條。
5. **資安**：兩個 API 皆需登入；老師摘要僅本人；填寫率僅 director/super_admin + `branch_id` 校區隔離。
6. **DoD**：PHPUnit 涵蓋兩個新 GET；CHANGELOG 一行；CI 綠。
