<?php

namespace App\Operations\Deployment;

/**
 * The one reader of the production runtime identity manifest (deployment.json) inside Laravel.
 *
 * The deploy executor (scripts/production-deploy/pi-remote-deploy.sh, write_deployment_manifest) writes
 * /home/admin/backend/public/deployment.json with scripts/write-deployment-manifest.py, and the web server publishes that
 * same file as /deployment.json. RELATIVE_PATH is that file seen from base_path(); a test pins it to the deploy script.
 * POP_INAPP_DEPLOYMENT_MANIFEST (config pop_inapp.deployment_manifest) overrides it. Every outcome other than a readable
 * manifest carrying a well-formed SHA is reported as a state and yields no SHAs, so callers fail closed.
 */
final class DeploymentManifest
{
    public const RELATIVE_PATH = 'public/deployment.json';
    public const KEYS = ['backend_sha', 'frontend_build_sha'];
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const UNREADABLE = 'unreadable';
    public const MALFORMED = 'malformed';
    public const NO_SHA = 'no_sha';
    private const SHA = '/^[0-9a-f]{40}\z/';

    /** An explicit $path (the executor passes the fixed deploy location) never consults the env override. */
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function path(): string
    {
        return $this->path ?? (string) (config('pop_inapp.deployment_manifest') ?: base_path(self::RELATIVE_PATH));
    }

    /** @return 'explicit'|'override'|'default' which rule chose path(); never the path itself */
    public function source(): string
    {
        if ($this->path !== null) return 'explicit';

        return config('pop_inapp.deployment_manifest') ? 'override' : 'default';
    }

    /**
     * @return array{state: string, shas: list<string>, backend_sha: ?string}
     *   shas: the distinct well-formed backend_sha and frontend_build_sha, lower-case (public values, safe to report).
     */
    public function read(): array
    {
        $path = $this->path();
        $fail = fn (string $state): array => ['state' => $state, 'shas' => [], 'backend_sha' => null];
        if (!is_file($path)) return $fail(self::MISSING);
        $raw = is_readable($path) ? @file_get_contents($path) : false;
        if (!is_string($raw)) return $fail(self::UNREADABLE);
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) return $fail(self::MALFORMED);
        $shas = [];
        foreach (self::KEYS as $key) {
            $sha = is_string($manifest[$key] ?? null) ? strtolower($manifest[$key]) : '';
            $shas[$key] = preg_match(self::SHA, $sha) === 1 ? $sha : null;
        }
        $distinct = array_values(array_unique(array_filter($shas)));
        if ($distinct === []) return $fail(self::NO_SHA);

        return ['state' => self::OK, 'shas' => $distinct, 'backend_sha' => $shas['backend_sha']];
    }
}
