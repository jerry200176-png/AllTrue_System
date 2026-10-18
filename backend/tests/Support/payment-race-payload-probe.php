<?php

// Synthetic stdin probe only: no app bootstrap, database connection or credential output.
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
touch($data['started']);
echo json_encode(['status' => 200, 'body' => [
    'db_keys' => array_keys($data['db']),
    'host' => $data['db']['host'],
    'database' => $data['db']['database'],
    'key_proof' => hash_hmac('sha256', 'race-probe', $data['app_key'] ?? ''),
]], JSON_THROW_ON_ERROR);
