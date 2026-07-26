<?php

/** PB-00 parent binding observability. Prefer config() over hot-path env(). Default-off. */
return [
    'observability_enabled' => (bool) env('PARENT_BINDING_OBSERVABILITY', false),
    'phone_fingerprint_key' => (string) env('PARENT_BINDING_PHONE_HMAC_KEY', ''),
    'store_phone_fingerprint' => (bool) env('PARENT_BINDING_STORE_PHONE_FINGERPRINT', false),
    'timezone' => 'Asia/Taipei',
];
