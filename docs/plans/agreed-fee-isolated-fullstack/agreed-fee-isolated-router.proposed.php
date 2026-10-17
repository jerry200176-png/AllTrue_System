<?php
/**
 * DRAFT — proposed install path: backend/tests/Support/agreed-fee-isolated-router.php
 *
 *   cd backend && php -S 127.0.0.1:8000 tests/Support/agreed-fee-isolated-router.php
 *
 * Real Laravel HTTP kernel. Process-local APP_KEY (same as phpunit.xml) +
 * agreed-fee flag. Never mutates config/billing.php.
 * Harness snapshot: GET /__isolated/fee-snapshot?course_id= (Bearer required).
 */
declare(strict_types=1);

require __DIR__.'/agreed-fee-isolated-db-guard.php';
agreed_fee_isolated_assert_db();

$backendRoot = dirname(__DIR__, 2); // backend/

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = $backendRoot.'/public'.$path;
    if ($path !== '/' && !str_starts_with($path, '/__isolated') && is_file($file)) {
        return false;
    }
}

require $backendRoot.'/vendor/autoload.php';
$app = require $backendRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

agreed_fee_isolated_assert_effective_connection();
agreed_fee_isolated_apply_runtime_config();
\Tests\Support\AgreedFeeTestSchema::ensureExtensionColumns();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/__isolated/fee-snapshot') {
    $courseId = (int) ($_GET['course_id'] ?? 0);
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($courseId < 1 || !preg_match('/^Bearer\s+(\S+)/', $auth, $m)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"message":"unauthorized"}';
        exit;
    }
    $token = $m[1];
    $row = Illuminate\Support\Facades\DB::table('auth_tokens')->where('token', $token)->first();
    if (!$row) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"message":"unauthorized"}';
        exit;
    }
    $course = Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $courseId)->first();
    if (!$course) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"message":"missing"}';
        exit;
    }
    $corrections = Illuminate\Support\Facades\DB::table('invoice_corrections')
        ->where('student_class_id', $courseId)->get();
    $invoiceIds = Illuminate\Support\Facades\DB::table('Invoice')
        ->where('StudentClassID', $courseId)->pluck('id');
    $payments = $invoiceIds->isEmpty() ? 0 : (int) Illuminate\Support\Facades\DB::table('Payment')
        ->whereIn('InvoiceID', $invoiceIds)->count();
    $reports = (int) Illuminate\Support\Facades\DB::table('payment_reports')
        ->where('StudentClassID', $courseId)->count();
    header('Content-Type: application/json');
    echo json_encode([
        'course_id' => $courseId,
        'charge' => (int) $course->Charge,
        'rate' => (int) $course->Rate,
        'session_count' => (int) $course->SessionCount,
        'remaining_sessions' => (int) $course->RemainingSessions,
        'used_sessions' => (int) $course->UsedSessions,
        'invoice_count' => $invoiceIds->count(),
        'open_unpaid_total' => (int) Illuminate\Support\Facades\DB::table('Invoice')
            ->where('StudentClassID', $courseId)->where('Status', 'unpaid')->sum('TotalAmount'),
        'correction_count' => $corrections->count(),
        'correction_kinds' => $corrections->pluck('kind')->values(),
        'new_charges' => $corrections->pluck('new_charge')->values(),
        'payment_count' => $payments,
        'payment_report_count' => $reports,
        'effects' => ['payment' => $payments, 'receipt' => $reports],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $http->handle($request);
$response->send();
$http->terminate($request, $response);
