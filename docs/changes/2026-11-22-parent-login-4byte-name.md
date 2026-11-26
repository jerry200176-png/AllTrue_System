## 2026-11-22 — fix(parent-portal): a name with a 4-byte character gets not-found instead of a 500 (#5572)
<!-- release-notes: silent_ship=silent-2026-11-22-parent-login-4byte-name -->
<!-- silent-reason: 極少數輸入罕用字（4 位元組字元）的家長登入，原本出現系統錯誤，改為一般的「查無此學生」提示；其他登入行為不變。 -->

- `ParentPortalController::login` 的姓名查詢：學生姓名欄是舊 3 位元組 utf8，含 4 位元組字元的姓名不可能相符，直接視為查無（不去掉該字，避免對到另一位學生）。檢查放在共用的 `Utf8mb3SearchSanitizer::hasFourByte`。
- 測試：`ParentPortalLoginIsolationTest::test_login_with_supplementary_character_name_returns_not_found`。
