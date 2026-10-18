<?php

// Separate PHP process for BillingCorrection::applyCountCorrection vs void mixed-writer (#4248).
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
                    throw new RuntimeException('synthetic correction barrier timed out');
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
    $course = App\Models\StudentClass::query()->whereKey((int) $data['class_id'])->firstOrFail();
    $svc = app(App\Services\Billing\BillingCorrection::class);
    $result = $svc->applyCountCorrection(
        $course,
        (int) $data['new_count'],
        (int) $data['new_charge'],
        (string) ($data['reason'] ?? 'mixed-writer'),
        (int) $data['old_count'],
        (int) $data['old_charge'],
        (string) $data['confirmation_token'],
        isset($data['actor_id']) ? (int) $data['actor_id'] : null
    );
    $status = 200;
    $body = $result;
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getData(true);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $status = $e->getStatusCode();
    $body = ['message' => $e->getMessage()];
} catch (Throwable $e) {
    $status = 500;
    $body = ['error' => $e::class, 'message' => $e->getMessage()];
}
echo json_encode(compact('status', 'body'), JSON_THROW_ON_ERROR);
