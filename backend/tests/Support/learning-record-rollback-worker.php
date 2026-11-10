<?php

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try { alltrue_boot_race_worker($data); } catch (InvalidArgumentException $e) { exit(90); }
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
alltrue_install_sql_pause($data['pause'] ?? null, 'synthetic rollback barrier timed out');
file_put_contents($data['connection_file'], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
touch($data['started']);
try {
    $request = Illuminate\Http\Request::create('/api/v1/learning-records/'.$data['record_id'].'/rollback-approval', 'POST');
    $request->headers->set('Authorization', 'Bearer '.$data['token']);
    $request->headers->set('Accept', 'application/json');
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
} catch (Throwable $e) { $status = 500; }
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
