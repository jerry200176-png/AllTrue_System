<?php

// Separate PHP process / PDO for PaymentReportController::void race evidence (#4248).
// Only an allowed synthetic test database on loopback may be supplied.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    alltrue_boot_race_worker($data);
} catch (InvalidArgumentException $e) {
    exit(90);
}
Illuminate\Support\Facades\DB::purge();
// Bound waits so deadlock/lock-wait cannot hang the suite or be mistaken for success.
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
alltrue_install_sql_pause(
    is_array($data['pause'] ?? null) ? $data['pause'] : null,
    'synthetic void barrier timed out'
);
alltrue_install_before_studentclass_for_update_signal(
    isset($data['signal_before_studentclass_for_update']) && is_string($data['signal_before_studentclass_for_update'])
        ? $data['signal_before_studentclass_for_update']
        : null
);
try {
    if (!empty($data['started'])) {
        touch($data['started']);
    }
    $request = Illuminate\Http\Request::create('/', 'PUT', $data['input'] ?? ['void_reason' => 'race']);
    $request->headers->set('Accept', 'application/json');
    foreach ([
        'auth_user_id' => $data['actor']['id'],
        'auth_role' => $data['actor']['role'] ?? 'director',
        'auth_campus_ids' => $data['actor']['campus_ids'] ?? [1],
    ] as $k => $v) {
        $request->attributes->set($k, $v);
    }
    $response = app(App\Http\Controllers\PaymentReportController::class)->void($request, $data['id']);
    $status = $response->getStatusCode();
    $body = $response->getData(true);
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getData(true);
} catch (Throwable $e) {
    $status = 500;
    $body = ['error' => $e::class, 'message' => $e->getMessage()];
}
echo json_encode(compact('status', 'body'), JSON_THROW_ON_ERROR);
