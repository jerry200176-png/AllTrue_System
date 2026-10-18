<?php

// Separate PHP process / PDO for PaymentReportController::void race evidence (#4248).
// Only an allowed synthetic test database on loopback may be supplied.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$dbName = $data['db']['database'] ?? null;
$dbHost = $data['db']['host'] ?? null;
if (!alltrue_is_allowed_test_database(is_string($dbName) ? $dbName : null)
    || !alltrue_is_allowed_test_db_host(is_string($dbHost) ? $dbHost : null)) {
    exit(90);
}
config(['database.connections.mysql' => $data['db'], 'database.default' => 'mysql', 'app.key' => str_repeat('t', 32)]);
Illuminate\Support\Facades\DB::purge();
// Bound waits so deadlock/lock-wait cannot hang the suite or be mistaken for success.
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$pause = $data['pause'] ?? null;
if ($pause) {
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$pause) {
        if ($pause && str_contains(strtolower($query->sql), $pause['match'])
            && (!($pause['locked'] ?? true) || str_contains(strtolower($query->sql), 'for update'))) {
            $p = $pause;
            $pause = null;
            touch($p['ready']);
            $deadline = microtime(true) + 15;
            while (!file_exists($p['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('synthetic void barrier timed out');
                }
                usleep(5000);
            }
        }
    });
}
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
