<?php

// Exercise the same bootstrap as real race workers; output no credentials or signing key.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
alltrue_boot_race_worker($data);
$db = config('database.connections.mysql');
touch($data['started']);
echo json_encode(['status' => 200, 'body' => [
    'db_keys' => array_keys($db),
    'host' => $db['host'],
    'database' => $db['database'],
    'key_proof' => hash_hmac('sha256', 'race-probe', config('app.key')),
]], JSON_THROW_ON_ERROR);
