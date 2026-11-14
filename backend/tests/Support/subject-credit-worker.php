<?php

// Separate process/PDO; reuse the maintained loopback synthetic-database gate and bounded race harness.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try { alltrue_boot_race_worker($data); } catch (InvalidArgumentException $e) { exit(90); }
Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
alltrue_install_sql_pause($data['pause'] ?? null, 'isolated subject-credit barrier timed out');
file_put_contents($data['connection_file'], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
touch($data['started']);
try {
    if ($data['op'] === 'apply') {
        $r = app(App\Services\Billing\SubjectCreditMovement::class)->apply(App\Models\StudentClass::findOrFail($data['course_id']),
            $data['input'], $data['actor_id'], [$data['campus_id']]);
        $status = $r['status']; $body = $r['body'];
    } else {
        $request = Illuminate\Http\Request::create('/', 'PUT', $data['input']);
        $request->headers->set('Accept', 'application/json');
        foreach (['auth_role' => 'director', 'auth_campus_ids' => [$data['campus_id']], 'auth_user_id' => $data['actor_id']] as $k => $v) $request->attributes->set($k, $v);
        $r = app(App\Http\Controllers\PaymentReportController::class)->void($request, $data['report_id']);
        $status = $r->getStatusCode(); $body = $r->getData(true);
    }
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode(); $body = $e->getResponse()->getData(true);
} catch (Throwable $e) {
    $status = 500; $body = ['error' => $e::class, 'message' => $e->getMessage()];
}
echo json_encode(compact('status', 'body'), JSON_THROW_ON_ERROR);
