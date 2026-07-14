<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GitHubIssueController extends Controller
{
    /**
     * GET /api/v1/github/issues
     * Proxy GitHub issues for the connected repository.
     */
    public function index(Request $request)
    {
        $repo = $request->input('repo', 'jerry200176-png/AllTrue_System');
        $state = $request->input('state', 'open');
        $labels = $request->input('labels');
        $perPage = min((int) $request->input('per_page', 30), 100);
        $page = max((int) $request->input('page', 1), 1);

        $token = env('GITHUB_TOKEN');
        if (!$token) {
            return response()->json([
                'data' => [],
                'message' => 'GitHub token 未設定',
            ], 503);
        }

        try {
            $query = [
                'state' => $state,
                'per_page' => $perPage,
                'page' => $page,
                'sort' => 'updated',
                'direction' => 'desc',
            ];
            if ($labels) {
                $query['labels'] = $labels;
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
                ->get("https://api.github.com/repos/{$repo}/issues", $query);

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'GitHub API 回傳錯誤',
                    'status' => $response->status(),
                ], 502);
            }

            $issues = collect($response->json())->map(function ($issue) {
                return [
                    'id' => $issue['id'],
                    'number' => $issue['number'],
                    'title' => $issue['title'],
                    'state' => $issue['state'],
                    'html_url' => $issue['html_url'],
                    'labels' => collect($issue['labels'] ?? [])->pluck('name'),
                    'created_at' => $issue['created_at'],
                    'updated_at' => $issue['updated_at'],
                    'user' => $issue['user']['login'] ?? null,
                ];
            });

            return response()->json(['data' => $issues->values()]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'GitHub API 連線失敗: ' . $e->getMessage(),
            ], 502);
        }
    }
}
