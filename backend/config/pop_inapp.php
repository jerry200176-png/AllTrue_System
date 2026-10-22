<?php

// ADR-POP-015 (#4069, #4073). The flag is the hard off switch: off (default) = the policy never approves.
return [
    // ADR-POP-015 §3 pop_inapp_agent requester credential lifetime from issuance (InappAgentClients::issue()).
    'agent_client_ttl_days' => (int) env('POP_INAPP_AGENT_TTL_DAYS', 90),
    'standing_policy_enabled' => (bool) env('POP_INAPP_STANDING_POLICY', false),
    'daily_cap' => (int) env('POP_INAPP_DAILY_CAP', 20), // target reports per operation per Asia/Taipei day
];
