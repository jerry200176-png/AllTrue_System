<?php

/*
 * Read-only "AI triage" access tokens for in-app bug reports (Founder GO 2026-10-13).
 *
 * Modeled on GitHub fine-grained PATs / Sentry auth tokens / Linear API keys:
 * one narrow ability, a fixed lifetime, hash-only storage (Sanctum's
 * personal_access_tokens table), shown once, revocable by a super admin.
 * Ops guide: docs/sop/BUG_INTAKE_TO_PRODUCTION.md §雲端唯讀金鑰.
 */
return [
    // The only ability a triage token ever carries.
    'ability' => 'bug-reports:read',

    // Lifetime counted from creation. Shortening it applies to existing tokens
    // immediately (fail-safe); lengthening never revives a revoked token.
    'ttl_days' => (int) env('TRIAGE_TOKEN_TTL_DAYS', 90),

    // Visible prefix of the secret part so a leaked token is recognizable
    // in logs and secret scanners (like ghp_ / sntrys_ / lin_api_).
    'secret_prefix' => 'alltrue_triage_',

    // The ONLY routes a triage token may reach. Every other API route answers
    // 403 to a request that presents one (RestrictAccessTokenScope).
    'allowed_routes' => [
        'triage.bug-reports.index',
        'triage.bug-reports.show',
        'triage.bug-reports.attachment',
    ],

    'list_max_limit' => 100,
];
