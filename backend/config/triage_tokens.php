<?php

// Read-only AI triage tokens (Founder GO 2026-10-13); see docs/sop/BUG_INTAKE_TO_PRODUCTION.md.
return [
    'ability' => 'bug-reports:read',
    // Lifetime from creation; shortening it applies to existing tokens at once (fail-safe).
    'ttl_days' => (int) env('TRIAGE_TOKEN_TTL_DAYS', 90),
    // Recognizable secret prefix (like ghp_ / lin_api_) for logs and secret scanners.
    'secret_prefix' => 'alltrue_triage_',
    // The ONLY routes a triage token may reach; every other API route answers 403.
    // #4100 adds the billing snapshot (IDs + numbers of ONE super-admin-linked contract); removing it here also closes it.
    'allowed_routes' => [
        'triage.bug-reports.index', 'triage.bug-reports.show', 'triage.bug-reports.attachment',
        'triage.student-classes.billing-snapshot',
    ],
    'list_max_limit' => 100,
    // #4100 kill switch, default OFF (待 Founder 核准): while false the billing snapshot answers 404 even for a live link.
    'billing_snapshot_enabled' => (bool) env('TRIAGE_BILLING_SNAPSHOT_ENABLED', false),
];
