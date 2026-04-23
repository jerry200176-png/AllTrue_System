<?php
/**
 * PHPUnit bootstrap — 在 Laravel 載入任何東西之前強制設定 APP_ENV=testing
 *
 * 問題根因：
 *   1. phpunit.xml 的 <env> 標籤在 phpdotenv 載入 .env 之後才生效
 *   2. .env 有 APP_ENV=production，讓 migrate:fresh 進入互動確認模式
 *   3. Mockery mock 了 console output → askQuestion() 無預期呼叫 → 619 errors
 *
 * 解法：
 *   phpdotenv (Laravel 8) 使用 immutable repository — 只要 putenv 先設好，
 *   .env 就不會覆蓋它
 *
 * ──────────────────────────────────────────────────────────────────────
 * ⛔ DB_DATABASE 斷路器（2026-04-22 事故後加入）
 *
 * 事故：AI 在 /home/admin/backend/ 跑 php artisan test，RefreshDatabase
 * 對 .env 指向的 DB_DATABASE=AllTrue（production）執行 DROP + migrate:fresh，
 * 清空全部學生／課程資料。
 *
 * 防護：任何測試啟動時，若偵測到 DB_DATABASE 仍指向 production（AllTrue），
 * 立即終止並輸出錯誤，不進入 Laravel 應用程式。
 * ──────────────────────────────────────────────────────────────────────
 */

// ── DB_DATABASE 斷路器：必須在 autoload 之前執行 ──
(function () {
    // 讀取 .env 中的 DB_DATABASE（phpunit.xml 的 <env> 尚未生效）
    $envFile = __DIR__ . '/../.env';
    $dbDatabase = '';
    if (file_exists($envFile)) {
        foreach (file($envFile) as $line) {
            if (preg_match('/^DB_DATABASE=(.+)/', trim($line), $m)) {
                $dbDatabase = trim($m[1]);
                break;
            }
        }
    }
    // 也檢查環境變數（phpunit.xml force="true" 可能已設定）
    $envDbDatabase = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');

    $productionDb = 'AllTrue';
    // 若兩者都未被覆蓋成測試 DB，且 .env 指向 production → 中止
    if ($dbDatabase === $productionDb && ($envDbDatabase === '' || $envDbDatabase === $productionDb)) {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "┌─────────────────────────────────────────────────────────┐\n");
        fwrite(STDERR, "│  ⛔ CRITICAL: DB_DATABASE 斷路器觸發                    │\n");
        fwrite(STDERR, "│                                                         │\n");
        fwrite(STDERR, "│  .env 的 DB_DATABASE={$productionDb}（生產資料庫）          │\n");
        fwrite(STDERR, "│  若繼續執行，RefreshDatabase 將清空所有學生課程資料！   │\n");
        fwrite(STDERR, "│                                                         │\n");
        fwrite(STDERR, "│  正確做法：                                             │\n");
        fwrite(STDERR, "│  1. 不要在 /home/admin/backend/ 直接跑測試              │\n");
        fwrite(STDERR, "│  2. cp -r /home/admin/backend /tmp/backend-test         │\n");
        fwrite(STDERR, "│  3. 在 /tmp/backend-test 改 .env → DB_DATABASE=AllTrue_test │\n");
        fwrite(STDERR, "│  4. 才能跑 php artisan test                             │\n");
        fwrite(STDERR, "└─────────────────────────────────────────────────────────┘\n");
        fwrite(STDERR, "\n");
        exit(1);
    }
})();

$_ENV['APP_ENV']    = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

// ── Config Cache 斷路器 ──────────────────────────────────────────────────────
// 若 bootstrap/cache/config.php 存在，其內的 APP_ENV=production 會被 Laravel
// LoadEnvironmentVariables 當作 config cache 直接使用，導致 putenv 的 testing
// 完全失效，ConfirmableTrait::confirmToProceed() 觸發互動確認，Mockery mock
// 的 OutputStyle 拋出 BadMethodCallException。
// 解法：在任何 Laravel 程式碼載入前，直接刪除 config cache 檔案。
// CI 從不執行 config:cache，所以 CI 不會碰到此問題；本機就需要這段保護。
(function () {
    $configCache = __DIR__ . '/../bootstrap/cache/config.php';
    if (file_exists($configCache)) {
        @unlink($configCache);
    }
})();

require __DIR__ . '/../vendor/autoload.php';
