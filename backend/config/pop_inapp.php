<?php

// ADR-POP-015 (#4069, #4073). The flag is the hard off switch: off (default) = the policy never approves.
return [
    'standing_policy_enabled' => (bool) env('POP_INAPP_STANDING_POLICY', false),
    'daily_cap' => (int) env('POP_INAPP_DAILY_CAP', 20), // write units per operation per day
    'actor_user_id' => (int) env('POP_INAPP_ACTOR_USER_ID', 0), // type S author; 0 = lowest-id super admin
];
