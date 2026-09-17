<?php

/*
|--------------------------------------------------------------------------
| Staff multi-role / capability flags (in-app #299 Phase A+B)
|--------------------------------------------------------------------------
|
| Default OFF. When false, AttachAuthUser keeps legacy User.type → role mapping.
| Dual-account production merges remain a separate R3 track — not enabled here.
|
*/

return [
    'multi_role_v1_enabled' => (bool) env('STAFF_MULTI_ROLE_V1', false),

    // Header used only as acting *context* (never as authority by itself).
    'acting_as_header' => 'X-Acting-As',
];
