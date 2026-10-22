<?php

namespace App\Operations\Inapp;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ADR-POP-015 (b) deploy evidence, read on the Pi. Read-only; every method fails closed (null/false) on a
 * missing file, a git or network error, or an unexpected shape.
 */
class InappReportResolveEvidence
{
    public const DEPLOY_WORKFLOW = '.github/workflows/deploy.yml';
    public const DEPLOY_JOB = 'Deploy to Production';
    private const SHA = '/^[0-9a-f]{40}$/';

    /** @return list<string> the well-formed backend_sha and frontend_build_sha of the Pi's deployment manifest */
    public function deployedShas(): array
    {
        $path = (string) (config('pop_inapp.deployment_manifest') ?: base_path('public/deployment.json'));
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($manifest)) return [];
        $shas = array_map(fn (string $key): string => strtolower((string) ($manifest[$key] ?? '')), ['backend_sha', 'frontend_build_sha']);

        return array_values(array_unique(array_filter($shas, fn (string $sha): bool => preg_match(self::SHA, $sha) === 1)));
    }

    public function isAncestor(string $commit, string $of): bool
    {
        if (preg_match(self::SHA, $commit) !== 1 || preg_match(self::SHA, $of) !== 1) return false;
        $git = new Process(['git', '-C', (string) (config('pop_inapp.git_dir') ?: base_path()), 'merge-base', '--is-ancestor', $commit, $of]);
        $git->setTimeout(20);
        try {
            $git->run();
        } catch (Throwable) {
            return false;
        }

        return $git->getExitCode() === 0; // 1 = not an ancestor; anything else (unknown object, no repo) fails closed
    }

    /** The run's head SHA when it is a deploy.yml run whose production deploy job concluded success; otherwise null. */
    public function successfulDeployHeadSha(string $runId): ?string
    {
        $run = $this->github("actions/runs/{$runId}");
        $head = strtolower((string) ($run['head_sha'] ?? ''));
        if (($run['path'] ?? null) !== self::DEPLOY_WORKFLOW || (string) ($run['id'] ?? '') !== $runId || preg_match(self::SHA, $head) !== 1) {
            return null;
        }
        $jobs = $this->github("actions/runs/{$runId}/jobs", ['per_page' => 100, 'filter' => 'latest']);
        $deploy = array_values(array_filter((array) ($jobs['jobs'] ?? []), fn ($job): bool => is_array($job) && ($job['name'] ?? null) === self::DEPLOY_JOB));

        return count($deploy) === 1 && ($deploy[0]['status'] ?? null) === 'completed' && ($deploy[0]['conclusion'] ?? null) === 'success'
            ? $head : null;
    }

    /** The in-app report ID in the issue's single SourceRef line; null for a pull request, a missing issue or no single SourceRef. */
    public function issueSourceBugId(int $issueNumber): ?int
    {
        $issue = $this->github("issues/{$issueNumber}");
        if (!is_array($issue) || isset($issue['pull_request']) || (int) ($issue['number'] ?? 0) !== $issueNumber) return null;
        preg_match_all('/^SourceRef:\s*alltrue:bug_report:([1-9][0-9]*)\s*$/m', (string) ($issue['body'] ?? ''), $m);
        $ids = array_values(array_unique($m[1]));

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    public function healthOk(): bool
    {
        $url = (string) (config('pop_inapp.health_url') ?: rtrim((string) config('app.url'), '/') . '/api/v1/health');
        try {
            return Http::timeout(10)->get($url)->status() === 200;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,int|string> $query */
    private function github(string $path, array $query = []): ?array
    {
        $repo = (string) config('pop_inapp.github_repo');
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo) !== 1) return null;
        try {
            $request = Http::timeout(10)->acceptJson();
            $token = config('pop_inapp.github_token');
            if (is_string($token) && $token !== '') $request = $request->withToken($token);
            $response = $request->get("https://api.github.com/repos/{$repo}/{$path}", $query);
            $json = $response->successful() ? $response->json() : null;

            return is_array($json) ? $json : null;
        } catch (Throwable) {
            return null;
        }
    }
}
