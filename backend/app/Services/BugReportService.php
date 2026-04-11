<?php

namespace App\Services;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\BugReportUserRead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BugReportService
{
    public const MAX_ATTACHMENTS = 5;

    private const VALID_TRANSITIONS = [
        'new' => ['triaged', 'in_progress', 'closed'],
        'triaged' => ['in_progress', 'closed'],
        'in_progress' => ['resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => ['in_progress'],
    ];

    public static function create(array $data): BugReport
    {
        return BugReport::create($data);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public static function attachUploadedFiles(BugReport $bug, array $files): void
    {
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile && $f->isValid()));
        $files = array_slice($files, 0, self::MAX_ATTACHMENTS);
        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            $storedPath = $file->store('bug-reports/' . $bug->id, 'public');
            BugReportAttachment::create([
                'bug_report_id' => $bug->id,
                'stored_path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'created_at' => Carbon::now(),
            ]);
        }
    }

    public static function listForUser(int $userId, array $campusIds, array $filters = [], int $perPage = 20): array
    {
        $query = BugReport::query()->where('reporter_user_id', $userId)->withCount('attachments');

        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        self::applyFilters($query, $filters);

        $query->orderByDesc('created_at');
        $rows = $query->paginate($perPage);

        return self::formatPaginated($rows);
    }

    public static function listForAdmin(array $campusIds, array $filters = [], int $perPage = 20): array
    {
        $query = BugReport::query()->withCount('attachments');

        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        self::applyFilters($query, $filters);

        $query->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'triaged' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 END ASC");
        $query->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END ASC");
        $query->orderByDesc('created_at');

        $rows = $query->paginate($perPage);

        return self::formatPaginated($rows);
    }

    public static function getDetail(int $bugId, bool $includeInternalNotes = false): ?array
    {
        $bug = BugReport::find($bugId);
        if (!$bug) {
            return null;
        }

        $commentsQuery = $bug->comments()->orderBy('id');
        if (!$includeInternalNotes) {
            $commentsQuery->where('is_internal_note', false);
        }
        $comments = $commentsQuery->get()->map(fn ($c) => [
            'id' => $c->id,
            'author_user_id' => $c->author_user_id,
            'author_name' => $c->author?->Name ?? '',
            'body' => $c->body,
            'is_internal_note' => $c->is_internal_note,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->all();

        $statusLogs = $bug->statusLogs()->orderBy('created_at')->get()->map(fn ($l) => [
            'from_status' => $l->from_status,
            'to_status' => $l->to_status,
            'changed_by_name' => $l->changedByUser?->Name ?? '',
            'note' => $l->note,
            'created_at' => $l->created_at?->toIso8601String(),
        ])->all();

        // Root-relative URL so images load on the same host the browser uses (LAN IP, etc.).
        // Storage::disk('public')->url() bakes in APP_URL and breaks when APP_URL ≠ page origin.
        $attachments = $bug->attachments()->orderBy('id')->get()->map(fn ($a) => [
            'id' => $a->id,
            'url' => self::publicDiskBrowserUrl($a->stored_path),
            'original_name' => $a->original_name,
            'mime_type' => $a->mime_type,
        ])->all();

        return [
            'id' => $bug->id,
            'campus_id' => $bug->CampusID,
            'reporter_user_id' => $bug->reporter_user_id,
            'reporter_name' => $bug->reporter?->Name ?? '',
            'title' => $bug->title,
            'description' => $bug->description,
            'severity' => $bug->severity,
            'status' => $bug->status,
            'page_key' => $bug->page_key,
            'url' => $bug->url,
            'client_info' => $bug->client_info,
            'created_at' => $bug->created_at?->toIso8601String(),
            'updated_at' => $bug->updated_at?->toIso8601String(),
            'comments' => $comments,
            'status_logs' => $statusLogs,
            'attachments' => $attachments,
        ];
    }

    public static function addComment(int $bugId, int $authorId, string $body, bool $isInternal = false): BugReportComment
    {
        return BugReportComment::create([
            'bug_report_id' => $bugId,
            'author_user_id' => $authorId,
            'body' => $body,
            'is_internal_note' => $isInternal,
            'created_at' => Carbon::now(),
        ]);
    }

    public static function updateCommentVisibility(int $bugId, int $commentId, bool $isInternal): ?BugReportComment
    {
        $comment = BugReportComment::query()
            ->where('id', $commentId)
            ->where('bug_report_id', $bugId)
            ->first();

        if (!$comment) {
            return null;
        }

        $comment->is_internal_note = $isInternal;
        $comment->save();

        return $comment;
    }

    public static function changeStatus(int $bugId, int $changedBy, string $newStatus, ?string $note = null): bool
    {
        $bug = BugReport::find($bugId);
        if (!$bug) {
            return false;
        }

        $fromStatus = $bug->status;
        $allowed = self::VALID_TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }

        return DB::transaction(function () use ($bug, $changedBy, $newStatus, $note) {
            $fromStatus = $bug->status;
            $bug->status = $newStatus;
            $bug->save();

            BugReportStatusLog::create([
                'bug_report_id' => $bug->id,
                'changed_by' => $changedBy,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'note' => $note,
                'created_at' => Carbon::now(),
            ]);

            return true;
        });
    }

    public static function belongsToCampus(int $bugId, array $campusIds): bool
    {
        if (empty($campusIds)) {
            return true;
        }
        return BugReport::where('id', $bugId)->whereIn('CampusID', $campusIds)->exists();
    }

    /**
     * Reporter opened bug detail — clears unread badge for this bug (replies + status changes).
     */
    public static function markBugRead(int $userId, int $bugReportId): void
    {
        $bug = BugReport::find($bugReportId);
        if (!$bug || (int) $bug->reporter_user_id !== (int) $userId) {
            return;
        }

        BugReportUserRead::updateOrCreate(
            ['user_id' => $userId, 'bug_report_id' => $bugReportId],
            ['read_at' => Carbon::now()]
        );
    }

    /**
     * Reporter opened the bug list — treat all own reports in scope as read (sidebar badge).
     */
    public static function markReporterInboxSeenFromList(int $userId, array $campusIds): void
    {
        $now = Carbon::now();
        $query = BugReport::query()->where('reporter_user_id', $userId);
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }
        $query->orderBy('id')->chunkById(500, function ($bugs) use ($userId, $now) {
            $payload = $bugs->map(fn ($b) => [
                'user_id' => $userId,
                'bug_report_id' => $b->id,
                'read_at' => $now,
            ])->all();
            if ($payload !== []) {
                BugReportUserRead::upsert($payload, ['user_id', 'bug_report_id'], ['read_at']);
            }
        });
    }

    /**
     * Super admin opened Bug 回報 list — clears "new submission" badge for this user.
     */
    public static function markBugInboxSeenForSuperAdmin(int $userId): void
    {
        $maxId = (int) (BugReport::query()->max('id') ?? 0);
        User::where('id', $userId)->update([
            'bug_inbox_last_seen_at' => Carbon::now(),
            'bug_inbox_last_seen_bug_id' => $maxId,
        ]);
    }

    public static function unreadBadgeCount(int $userId, string $role, array $campusIds): int
    {
        $reporter = self::countReporterReplyUnread($userId, $campusIds);
        if ($role === 'super_admin') {
            return $reporter + self::countNewBugsForSuperAdminInbox($userId, $campusIds);
        }

        return $reporter;
    }

    /**
     * Reporter-facing unread: public comments or status changes by someone else after last list or detail view.
     */
    private static function countReporterReplyUnread(int $userId, array $campusIds): int
    {
        $query = BugReport::query()->where('reporter_user_id', $userId);
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        $epoch = '1970-01-01 00:00:00';
        $query->where(function ($outer) use ($userId, $epoch) {
            $outer->whereExists(function ($q) use ($userId, $epoch) {
                $q->selectRaw('1')
                    ->from('bug_report_comments as c')
                    ->whereColumn('c.bug_report_id', 'bug_reports.id')
                    ->where('c.is_internal_note', false)
                    ->where('c.author_user_id', '!=', $userId)
                    ->whereRaw(
                        'c.created_at >= bug_reports.created_at AND c.created_at > COALESCE((SELECT r.read_at FROM bug_report_user_reads r WHERE r.bug_report_id = bug_reports.id AND r.user_id = ?), ?)',
                        [$userId, $epoch]
                    );
            })->orWhereExists(function ($q) use ($userId, $epoch) {
                $q->selectRaw('1')
                    ->from('bug_report_status_logs as s')
                    ->whereColumn('s.bug_report_id', 'bug_reports.id')
                    ->where('s.changed_by', '!=', $userId)
                    ->whereRaw(
                        's.created_at >= bug_reports.created_at AND s.created_at > COALESCE((SELECT r.read_at FROM bug_report_user_reads r WHERE r.bug_report_id = bug_reports.id AND r.user_id = ?), ?)',
                        [$userId, $epoch]
                    );
            });
        });

        return (int) $query->count();
    }

    private static function countNewBugsForSuperAdminInbox(int $userId, array $campusIds): int
    {
        $user = User::find($userId);
        if (!$user) {
            return 0;
        }

        $q = BugReport::query()->where('status', 'new');
        if (!empty($campusIds)) {
            $q->whereIn('CampusID', $campusIds);
        }
        // Only filter by id after super_admin has opened Bug 回報 at least once
        if ($user->bug_inbox_last_seen_at !== null) {
            $seenMax = (int) ($user->bug_inbox_last_seen_bug_id ?? 0);
            $q->where('id', '>', $seenMax);
        }

        return (int) $q->count();
    }

    private static function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
    }

    /**
     * Browser URL for a path on the public disk (same idea as ChatService::mediaUrl).
     */
    private static function publicDiskBrowserUrl(?string $storedPath): string
    {
        if (!$storedPath) {
            return '';
        }
        if (str_starts_with($storedPath, '/')) {
            return $storedPath;
        }

        return '/storage/' . ltrim(str_replace('\\', '/', $storedPath), '/');
    }

    private static function formatPaginated($rows): array
    {
        $payload = $rows->toArray();
        $payload['data'] = collect($payload['data'])->map(fn ($r) => [
            'id' => $r['id'],
            'campus_id' => $r['CampusID'],
            'reporter_user_id' => $r['reporter_user_id'],
            'title' => $r['title'],
            'severity' => $r['severity'],
            'status' => $r['status'],
            'page_key' => $r['page_key'],
            'attachments_count' => (int) ($r['attachments_count'] ?? 0),
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ])->all();

        return $payload;
    }
}
