## 2026-10-11 — chore(ci): production-case-dump 每個 case 產生的 PHP 都會被 lint
<!-- release-notes: silent_ship=silent-2026-10-11-case-dump-php-lint-test -->
<!-- silent-reason: 只改內部診斷工作流程的測試，教職員看不到差異 -->
- 新測試把 `production-case-dump` 每個 case 產生的 PHP 跑 `php -l`（並確認輸出非空）；既有測試改用 stdin 餵 python，避免產生的程式超過單一參數 128KB 上限。
