<?php
/**
 * DRAFT — install as: backend/tests/Support/agreed-fee-isolated-router.php
 *
 * Harness routes under /__isolated/* (Bearer + seeded course allowlist + unexpired token).
 */
declare(strict_types=1);

require __DIR__.'/agreed-fee-isolated-db-guard.php';
agreed_fee_isolated_assert_db();

$backendRoot = dirname(__DIR__, 2);
if (is_file($backendRoot.'/bootstrap/cache/config.php')) {
    agreed_fee_isolated_refuse('bootstrap/cache/config.php present');
}

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = $backendRoot.'/public'.$path;
    if ($path !== '/' && !str_starts_with($path, '/__isolated') && is_file($file)) {
        return false;
    }
}

$app = agreed_fee_isolated_boot_application($backendRoot);
\Tests\Support\AgreedFeeTestSchema::ensureExtensionColumns();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$authorizeIsolated = static function (int $courseId): object {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($courseId < 1 || !preg_match('/^Bearer\s+(\S+)/', $auth, $m)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"message":"unauthorized"}';
        exit;
    }
    $token = $m[1];
    $row = Illuminate\Support\Facades\DB::table('auth_tokens')->where('token', $token)->first();
    if (!$row || empty($row->expires_at) || \Carbon\Carbon::parse($row->expires_at)->isPast()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"message":"token_expired_or_missing"}';
        exit;
    }
    $allowPath = '/tmp/agreed-fee-isolated-courses.json';
    if (!is_file($allowPath)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo '{"message":"allowlist_missing"}';
        exit;
    }
    $allow = json_decode((string) file_get_contents($allowPath), true, 512, JSON_THROW_ON_ERROR);
    if ((int) ($allow['user_id'] ?? 0) !== (int) $row->user_id) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo '{"message":"token_user_mismatch"}';
        exit;
    }
    $ids = array_map('intval', $allow['course_ids'] ?? []);
    if (!in_array($courseId, $ids, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo '{"message":"course_not_in_seed_allowlist"}';
        exit;
    }
    $course = Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $courseId)->first();
    if (!$course) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"message":"missing"}';
        exit;
    }

    return (object) ['token_row' => $row, 'course' => $course, 'allow' => $allow];
};

if ($path === '/__isolated/fee-snapshot') {
    $courseId = (int) ($_GET['course_id'] ?? 0);
    $ctx = $authorizeIsolated($courseId);
    $course = $ctx->course;
    $corrections = Illuminate\Support\Facades\DB::table('invoice_corrections')
        ->where('student_class_id', $courseId)->orderBy('id')->get();
    $invoices = Illuminate\Support\Facades\DB::table('Invoice')
        ->where('StudentClassID', $courseId)->orderBy('id')->get();
    $original = $invoices->first();
    $replacement = $invoices->count() > 1 ? $invoices->last() : null;
    $items = $original
        ? Illuminate\Support\Facades\DB::table('InvoiceItem')->where('InvoiceID', $original->id)->orderBy('id')->get()
        : collect();
    $invoiceIds = $invoices->pluck('id');
    $payments = $invoiceIds->isEmpty() ? 0 : (int) Illuminate\Support\Facades\DB::table('Payment')
        ->whereIn('InvoiceID', $invoiceIds)->count();
    $reports = (int) Illuminate\Support\Facades\DB::table('payment_reports')
        ->where('StudentClassID', $courseId)->count();
    $notif = (int) Illuminate\Support\Facades\DB::table('Notifications')->count();
    $audit = (int) Illuminate\Support\Facades\DB::table('security_audit_events')
        ->where('event_type', 'billing.agreed_fee_corrected')
        ->where('subject_type', 'student_class')->where('subject_id', $courseId)->count();

    header('Content-Type: application/json');
    echo json_encode([
        'course_id' => $courseId,
        'charge' => (int) $course->Charge,
        'rate' => (int) $course->Rate,
        'session_count' => (int) $course->SessionCount,
        'remaining_sessions' => (int) $course->RemainingSessions,
        'used_sessions' => (int) $course->UsedSessions,
        'start_date' => (string) $course->StartDate,
        'end_date' => (string) $course->EndDate,
        'class_type' => (string) $course->ClassType,
        'invoice_count' => $invoices->count(),
        'original_invoice' => $original ? [
            'id' => (int) $original->id,
            'TotalAmount' => (int) $original->TotalAmount,
            'PaidAmount' => (int) $original->PaidAmount,
            'Status' => (string) $original->Status,
            'Note' => (string) $original->Note,
            'IssueDate' => (string) $original->IssueDate,
            'DueDate' => (string) ($original->DueDate ?? ''),
        ] : null,
        'original_invoice_items' => $items->map(static fn ($i) => [
            'id' => (int) $i->id,
            'Amount' => (int) $i->Amount,
            'Description' => (string) $i->Description,
        ])->values(),
        'replacement_invoice' => $replacement ? [
            'id' => (int) $replacement->id,
            'TotalAmount' => (int) $replacement->TotalAmount,
            'PaidAmount' => (int) $replacement->PaidAmount,
            'Status' => (string) $replacement->Status,
            'IssueDate' => (string) $replacement->IssueDate,
            'DueDate' => (string) ($replacement->DueDate ?? ''),
        ] : null,
        'open_unpaid_total' => (int) $invoices->where('Status', 'unpaid')->sum('TotalAmount'),
        'correction_count' => $corrections->count(),
        'correction_kinds' => $corrections->pluck('kind')->values(),
        'new_charges' => $corrections->pluck('new_charge')->values(),
        'correction_ids' => $corrections->pluck('id')->values(),
        'payment_count' => $payments,
        'payment_report_count' => $reports,
        'audit_agreed_fee_count' => $audit,
        'notification_count' => $notif,
        'effects' => ['payment' => $payments, 'receipt' => $reports, 'notification' => 0],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($path === '/__isolated/bump-tracked-state' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $courseId = (int) ($input['course_id'] ?? 0);
    $ctx = $authorizeIsolated($courseId);
    // Mutate a state_hash-tracked field (RemainingSessions) without going through fee apply.
    Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $courseId)->update([
        'RemainingSessions' => max(0, (int) $ctx->course->RemainingSessions - 1),
    ]);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'course_id' => $courseId,
        'remaining_sessions' => (int) Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $courseId)->value('RemainingSessions'),
    ], JSON_THROW_ON_ERROR);
    exit;
}

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $http->handle($request);
$response->send();
$http->terminate($request, $response);
