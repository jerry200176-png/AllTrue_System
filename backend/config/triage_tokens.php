<?php

// Read-only AI triage tokens (Founder GO 2026-10-13); see docs/sop/BUG_INTAKE_TO_PRODUCTION.md.
return [
    'ability' => 'bug-reports:read',
    // Lifetime fixed at issue (expires_at). Shortening it applies to existing tokens at once;
    // raising it applies to new tokens only and never extends or revives an issued one (#4021).
    'ttl_days' => (int) env('TRIAGE_TOKEN_TTL_DAYS', 90),
    // Recognizable secret prefix (like ghp_ / lin_api_) for logs and secret scanners.
    'secret_prefix' => 'alltrue_triage_',
    // The ONLY routes a triage token may reach; every other API route answers 403.
    'allowed_routes' => ['triage.bug-reports.index', 'triage.bug-reports.show', 'triage.bug-reports.attachment'],
    'list_max_limit' => 100,
];
