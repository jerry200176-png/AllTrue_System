<?php

// Separate PHP process for BillingCorrection::applyCountCorrection vs void mixed-writer (#4248).
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    alltrue_boot_race_worker($data);
} catch (InvalidArgumentException $e) {
    exit(90);
}
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
alltrue_install_sql_pause(
    is_array($data['pause'] ?? null) ? $data['pause'] : null,
    'synthetic correction barrier timed out'
);
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
