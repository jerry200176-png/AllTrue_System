<?php

// Separate PDO/process; reuse the guarded synthetic-database and race plumbing.
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
alltrue_install_sql_pause($data['route_pause'] ?? null, 'synthetic route binding pause timed out');
Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function ($query, $bindings) use ($data) {
    $sql = strtolower($query);
    if (str_contains($sql, 'studentclass') && str_contains($sql, 'for update')) {
        file_put_contents($data['bindings_file'], json_encode($bindings, JSON_THROW_ON_ERROR));
    }
});
Illuminate\Support\Facades\DB::listen(static function ($query) use ($data) {
    $sql = strtolower($query->sql);
    if (str_contains($sql, 'studentclass') && str_contains($sql, 'for update')) {
        touch($data['after_course_query']);
    }
});
file_put_contents($data['connection_file'], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
try {
    touch($data['started']);
    $request = Illuminate\Http\Request::create('/', 'POST', ['reason' => 'Synthetic serialized void', 'Amount' => 500]);
    $request->headers->set('Accept', 'application/json');
    $request->attributes->set('auth_role', 'director');
    $request->attributes->set('auth_campus_ids', [1]);
    $controller = app(App\Http\Controllers\BillingController::class);
    $invoice = App\Models\Invoice::findOrFail($data['invoice_id']);
    $response = $controller->{$data['operation']}($request, $invoice);
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
