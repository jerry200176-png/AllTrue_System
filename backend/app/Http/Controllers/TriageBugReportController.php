<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Services\BugReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/** Read-only bug report access for a triage token (GET only); AuthenticateTriageToken audits, RestrictAccessTokenScope closes every other route. */
class TriageBugReportController extends Controller
{
    private const STATUSES = ['new', 'triaged', 'in_progress', 'resolved', 'closed'];

    public function index(Request $request)
    {
        $max = (int) config('triage_tokens.list_max_limit');
        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'max:100'],
            'reporter_user_id' => ['nullable', 'integer', 'min:1'],
            'since_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', "max:{$max}"],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid query', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $statuses = array_values(array_filter(explode(',', (string) ($data['status'] ?? ''))));
        if (array_diff($statuses, self::STATUSES) !== []) {
            return response()->json(['message' => 'Invalid status', 'allowed' => self::STATUSES], 422);
        }

        $query = BugReport::query()->withCount(['attachments', 'comments'])
            ->where('id', '>', (int) ($data['since_id'] ?? 0))
            ->orderBy('id')
            ->limit((int) ($data['limit'] ?? $max));
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        if (isset($data['reporter_user_id'])) {
            $query->where('reporter_user_id', (int) $data['reporter_user_id']);
        }

        $rows = $query->get()->map(fn (BugReport $bug) => [
            'id' => $bug->id,
            'campus_id' => $bug->CampusID,
            'title' => $bug->title,
            'severity' => $bug->severity,
            'status' => $bug->status,
            'page_key' => $bug->page_key,
            'attachments_count' => (int) $bug->getAttribute('attachments_count'),
            'comments_count' => (int) $bug->getAttribute('comments_count'),
            'created_at' => $bug->created_at?->toIso8601String(),
            'updated_at' => $bug->updated_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => $rows, 'next_since_id' => $rows->isEmpty() ? null : $rows->last()['id']]);
    }

    public function show(int $id)
    {
        $detail = BugReportService::getDetail($id, true);
        if ($detail === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // Drop the public /storage url: reads must go through the audited, revocable route.
        $detail['attachments'] = array_map(fn (array $a) => array_diff_key($a, ['url' => true]) + [
            'download_path' => "/api/v1/triage/bug-reports/{$id}/attachments/{$a['id']}",
        ], $detail['attachments']);

        return response()->json($detail);
    }

    public function attachment(int $id, int $attachmentId)
    {
        $attachment = BugReportAttachment::query()
            ->where('bug_report_id', $id)
            ->whereKey($attachmentId)
            ->first();
        if (!$attachment instanceof BugReportAttachment) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $path = (string) $attachment->getAttribute('stored_path');
        $disk = Storage::disk('public');
        if ($path === '' || str_contains($path, '..') || !$disk->exists($path)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $contents = (string) $disk->get($path);

        return response()->streamDownload(
            static function () use ($contents): void { echo $contents; },
            "bug-{$id}-attachment-{$attachmentId}" . (($ext = pathinfo($path, PATHINFO_EXTENSION)) !== '' ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : ''),
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']
        );
    }
}
