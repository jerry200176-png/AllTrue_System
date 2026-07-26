<?php

/*
|--------------------------------------------------------------------------
| Parent Binding Observability (PB-00)
|--------------------------------------------------------------------------
|
| Phase 0 baseline only. Does not change bind/login success paths or
| parent-facing copy. Observation writes are fail-open.
|
| Flag: parent_binding_observability
| Env:  PARENT_BINDING_OBSERVABILITY=true|false
|
*/

return [
    // Cached via config(); do not call env() from request hot paths.
    'observability_enabled' => (bool) env('PARENT_BINDING_OBSERVABILITY', true),

    // Keyed HMAC for optional phone fingerprints (domain-separated).
    // Never log or persist this key. Empty → fingerprints are not stored.
    'phone_fingerprint_key' => (string) env(
        'PARENT_BINDING_PHONE_HMAC_KEY',
        (string) env('APP_KEY', '')
    ),

    // Whether to persist phone_fingerprint on observation rows (default off).
    'store_phone_fingerprint' => (bool) env('PARENT_BINDING_STORE_PHONE_FINGERPRINT', false),

    'timezone' => 'Asia/Taipei',
];
