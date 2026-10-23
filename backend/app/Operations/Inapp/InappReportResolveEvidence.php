<?php

namespace App\Operations\Inapp;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ADR-POP-015 (b) deploy evidence: the deployment manifest read on the Pi, everything else read-only from the
 * GitHub REST API (public repository). Every method fails closed (null/false/[]) on an error or an unexpected shape.
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
        $compare = $this->github("compare/{$commit}...{$of}", ['per_page' => 1]);

        return in_array($compare['status'] ?? null, ['ahead', 'identical'], true) && ($compare['merge_base_commit']['sha'] ?? null) === $commit;
    }

    /** True when the commit came from a merged pull request whose title or body references the issue or the in-app report. */
    public function fixLinkedTo(string $commit, int $issue, int $bugId): bool
    {
        $pattern = '/(?<![\w\/])#' . $issue . '(?!\d)|in-app\s*#' . $bugId . '(?!\d)/i';
        foreach ((array) $this->github("commits/{$commit}/pulls") as $pr) {
            if (is_array($pr) && !empty($pr['merged_at']) && preg_match($pattern, ($pr['title'] ?? '') . "\n" . ($pr['body'] ?? '')) === 1) return true;
        }

        return false;
    }

    /** @return array{head: string, completed_at: string}|null a deploy.yml run on main whose production deploy job concluded success */
    public function successfulDeploy(string $runId): ?array
    {
        $run = $this->github("actions/runs/{$runId}");
        $head = strtolower((string) ($run['head_sha'] ?? ''));
        if (($run['path'] ?? null) !== self::DEPLOY_WORKFLOW || (string) ($run['id'] ?? '') !== $runId
            || ($run['head_branch'] ?? null) !== 'main' || preg_match(self::SHA, $head) !== 1) return null;
        $jobs = $this->github("actions/runs/{$runId}/jobs", ['per_page' => 100, 'filter' => 'latest']);
        $deploy = array_values(array_filter((array) ($jobs['jobs'] ?? []), fn ($job): bool => is_array($job) && ($job['name'] ?? null) === self::DEPLOY_JOB));
        if (count($deploy) !== 1 || ($deploy[0]['conclusion'] ?? null) !== 'success' || !is_string($deploy[0]['completed_at'] ?? null)) return null;

        return ['head' => $head, 'completed_at' => $deploy[0]['completed_at']];
    }

    /**
     * True when a production smoke workflow (config pop_inapp.smoke_workflows) run from main by schedule, dispatch or deploy
     * succeeded and started after $after (an ISO time), and so did the named job if one is given.
     */
    public function smokePassedAfter(string $runId, ?string $jobId, string $after): bool
    {
        $run = $this->github("actions/runs/{$runId}");
        $started = strtotime((string) ($run['run_started_at'] ?? ''));
        $passed = (string) ($run['id'] ?? '') === $runId && ($run['conclusion'] ?? null) === 'success'
            && in_array($run['path'] ?? null, (array) config('pop_inapp.smoke_workflows'), true)
            && in_array($run['event'] ?? null, ['schedule', 'workflow_dispatch', 'workflow_run'], true) && ($run['head_branch'] ?? null) === 'main'
            && $started !== false && $started >= strtotime($after);
        if (!$passed || $jobId === null) return $passed;
        $job = $this->github("actions/jobs/{$jobId}");

        return (string) ($job['run_id'] ?? '') === $runId && ($job['conclusion'] ?? null) === 'success';
    }

    /**
     * True when this repository's issue carries a SourceRef for the report written by the repository owner or the
     * intake bot (body or comment). The same trust rule as bug-auto-intake.yml: anyone can quote a SourceRef in a
     * public issue, and such an issue must never be linked to the reporter.
     */
    public function issueOwnsReport(int $issueNumber, int $bugId): bool
    {
        $repo = (string) config('pop_inapp.github_repo');
        $issue = $this->github("issues/{$issueNumber}");
        if (!is_array($issue) || isset($issue['pull_request']) || (int) ($issue['number'] ?? 0) !== $issueNumber
            || !str_ends_with((string) ($issue['repository_url'] ?? ''), '/repos/' . $repo)
            || in_array($issue['state_reason'] ?? null, ['duplicate', 'not_planned'], true)) return false;
        $trusted = fn ($author): bool => in_array($author['login'] ?? null, [explode('/', $repo)[0], 'github-actions[bot]'], true);
        $ref = '/^[ \t>*_#.)0-9`-]*SourceRef\b[^\r\n]*alltrue:bug_report:' . $bugId . '(?!\d)/m';
        foreach ([$issue, ...(array) $this->github("issues/{$issueNumber}/comments", ['per_page' => 100])] as $item) {
            if (is_array($item) && $trusted($item['user'] ?? []) && preg_match($ref, (string) ($item['body'] ?? '')) === 1) return true;
        }

        return false;
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
            // Read live, never from config: a cached config must not snapshot the credential (#2833, GitHubIssueController).
            $token = env('GITHUB_TOKEN');
            if (is_string($token) && $token !== '') $request = $request->withToken($token);
            $response = $request->get("https://api.github.com/repos/{$repo}/{$path}", $query);
            $json = $response->successful() ? $response->json() : null;

            return is_array($json) ? $json : null;
        } catch (Throwable) {
            return null;
        }
    }
}
