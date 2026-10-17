<?php

// Separate PHP process / PDO for PaymentReportController::void race evidence (#4248).
// Only the isolated synthetic test database may be supplied.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($data['db']['database'] ?? '') !== 'AllTrue_test'
    || !in_array($data['db']['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    exit(90);
}
config(['database.connections.mysql' => $data['db'], 'database.default' => 'mysql', 'app.key' => str_repeat('t', 32)]);
Illuminate\Support\Facades\DB::purge();
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
