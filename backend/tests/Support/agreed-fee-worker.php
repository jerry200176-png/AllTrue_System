<?php

// Separate PHP process, separate PDO. Only the isolated synthetic test database may be supplied.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($data['db']['database'] ?? '') !== 'AllTrue_test' || ($data['db']['host'] ?? '') !== 'agreed-fee-test-mysql') exit(90);
config(['database.connections.mysql' => $data['db'], 'database.default' => 'mysql',
    'billing.agreed_fee_correction_enabled' => true, 'app.key' => str_repeat('t', 32)]);
Illuminate\Support\Facades\DB::purge();
$pause = $data['pause'] ?? null;
if ($pause) {
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$pause) {
        if ($pause && str_contains(strtolower($query->sql), $pause['match']) && (!($pause['locked'] ?? true) || str_contains(strtolower($query->sql), 'for update'))) {
            $p = $pause; $pause = null;
            touch($p['ready']);
            $deadline = microtime(true) + 15;
            while (!file_exists($p['release'])) {
                if (microtime(true) > $deadline) throw new RuntimeException('synthetic barrier timed out');
                usleep(5000);
            }
        }
    });
}
try {
    if (!empty($data['started'])) touch($data['started']);
    if ($data['op'] === 'fee') {
        $body = app(App\Services\Billing\AgreedFeeCorrection::class)->run($data['course_id'], $data['input'], $data['actor'], true);
        $status = 200;
    } else {
        $request = Illuminate\Http\Request::create('/', 'POST', $data['input']);
        $request->headers->set('Accept', 'application/json');
        foreach (['auth_user_id' => $data['actor']['id'], 'auth_role' => 'director', 'auth_campus_ids' => [1]] as $k => $v) $request->attributes->set($k, $v);
        $controller = $data['op'] === 'issue' || $data['op'] === 'payment' || $data['op'] === 'invoice_void' || $data['op'] === 'exception_void'
            ? app(App\Http\Controllers\BillingController::class) : app(App\Http\Controllers\PaymentReportController::class);
        $response = match ($data['op']) {
            'issue' => $controller->store($request, app(App\Services\Billing\InvoiceIssuer::class)),
            'confirm' => $controller->confirm($request, $data['id']),
            'void' => $controller->void($request, $data['id']),
            'record' => $controller->directorRecord($request),
            'payment' => $controller->recordPayment($request, App\Models\Invoice::findOrFail($data['id'])),
            'invoice_void' => $controller->voidInvoice($request, App\Models\Invoice::findOrFail($data['id'])),
            'exception_void' => $controller->exceptionVoidInvoice($request, App\Models\Invoice::findOrFail($data['id'])),
        };
        $status = $response->getStatusCode(); $body = $response->getData(true);
    }
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode(); $body = $e->getResponse()->getData(true);
} catch (Throwable $e) {
    $status = 500; $body = ['error' => $e::class, 'message' => $e->getMessage()];
}
echo json_encode(compact('status', 'body'), JSON_THROW_ON_ERROR);
