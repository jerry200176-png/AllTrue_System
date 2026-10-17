<?php
/**
 * DRAFT — proposed path: backend/tests/Support/agreed-fee-isolated-router.php
 *
 *   php -S 127.0.0.1:8000 tests/Support/agreed-fee-isolated-router.php
 *
 * Built-in server router: real Laravel HTTP kernel + AllTrue_test only +
 * process-local agreed-fee enable (never mutates config/billing.php).
 */
declare(strict_types=1);

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__.'/../../public'.$path;
    if ($path !== '/' && is_file($file)) {
        return false; // serve static from public/
    }
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = config('database.connections.mysql');
$host = (string) ($db['host'] ?? '');
$name = (string) ($db['database'] ?? getenv('DB_DATABASE') ?: '');
if ($name !== 'AllTrue_test' || !in_array($host, ['127.0.0.1', 'localhost', 'agreed-fee-test-mysql'], true)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "refusing non-isolated database\n";
    exit;
}

config(['billing.agreed_fee_correction_enabled' => true, 'database.default' => 'mysql']);

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $http->handle($request);
$response->send();
$http->terminate($request, $response);
