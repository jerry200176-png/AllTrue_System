<?php

// ADR-POP-015 (#4069, #4073). The flag is the hard off switch: off (default) = the policy never approves.
return [
    'standing_policy_enabled' => (bool) env('POP_INAPP_STANDING_POLICY', false),
    'daily_cap' => (int) env('POP_INAPP_DAILY_CAP', 20), // target reports per operation per Asia/Taipei day
    // inapp-report-resolve-after-deploy evidence, read on the Pi (empty = default). Read-only GitHub API calls;
    // the repository is public, so the token is optional and only raises the rate limit.
    'github_repo' => env('POP_INAPP_GITHUB_REPO', 'jerry200176-png/AllTrue_System'),
    'github_token' => env('GITHUB_TOKEN'),
    'deployment_manifest' => env('POP_INAPP_DEPLOYMENT_MANIFEST'), // default public/deployment.json
    'git_dir' => env('POP_INAPP_GIT_DIR'), // default base_path()
    'health_url' => env('POP_INAPP_HEALTH_URL'), // default APP_URL/api/v1/health
];
