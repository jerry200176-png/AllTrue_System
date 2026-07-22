<?php

namespace App\Services;

use App\Models\ExceptionWorkflow;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-model aggregator for director Action Inbox (B-lite + D).
 *
 * Notifications remain the source for ops reminders (tuition / review / swipe / …).
 * exception_workflows remain the sole source of truth for leave/makeup cases.
 * This service never writes Notification rows for student_leave.
 */
class ActionInboxService
{
    public const OPEN_CASE_STATUSES = ['open', 'candidate_ready'];

    /**
     * @param  array<int>  $campusIds  Empty = all campuses (super_admin).
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $campusIds, int $userId, ?string $lane = null): array
    {
        $ops = ($lane === null || $lane === 'ops' || $lane === '')
            ? $this->buildOpsItems($campusIds, $userId)
            : collect();
        $cases = ($lane === null || $lane === 'case' || $lane === '')
            ? $this->buildCaseItems($campusIds)
            : collect();

        $merged = $ops->concat($cases)->values();
        $sorted = $this->sortItems($merged);

        return [
            'data' => $sorted->all(),
            'meta' => [
                'notifications_unread' => $ops->filter(fn ($row) => empty($row['read_at']))->count(),
                'cases_open' => $cases->count(),
                'needs_attention' => $ops->filter(fn ($row) => empty($row['read_at']))->count() + $cases->count(),
                'count' => $sorted->count(),
            ],
        ];
    }

    /**
     * @param  array<int>  $campusIds
     * @return array{notifications_unread: int, cases_open: int, needs_attention: int}
     */
    public function count(array $campusIds, int $userId): array
    {
        $notificationsUnread = $this->countUnreadNotifications($campusIds, $userId);
        $casesOpen = $this->countOpenCases($campusIds);

        return [
            'notifications_unread' => $notificationsUnread,
            'cases_open' => $casesOpen,
            'needs_attention' => $notificationsUnread + $casesOpen,
        ];
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function countUnreadNotifications(array $campusIds, int $userId): int
    {
        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt');

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }

        return (int) $query->count('Notifications.id');
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function countOpenCases(array $campusIds): int
    {
        $query = ExceptionWorkflow::query()
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);

        if (!empty($campusIds)) {
            $query->whereIn('campus_id', $campusIds);
        }

        return (int) $query->count();
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function buildOpsItems(array $campusIds, int $userId): Collection
    {
        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->select([
                'Notifications.*',
                'nr.ReadAt as read_at',
            ])
            ->whereNull('Notifications.ResolvedAt');

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }

        return $query->orderByDesc('Notifications.id')
            ->limit(100)
            ->get()
            ->map(fn ($row) => $this->serializeNotification($row));
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function buildCaseItems(array $campusIds): Collection
    {
        $query = ExceptionWorkflow::query()
            ->with(['student', 'classSession'])
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);

        if (!empty($campusIds)) {
            $query->whereIn('campus_id', $campusIds);
        }

        return $query->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ExceptionWorkflow $workflow) => $this->serializeLeaveCase($workflow));
    }

    private function serializeNotification($notification): array
    {
        $type = (string) $notification->Type;
        $payload = is_array($notification->Payload) ? $notification->Payload : [];
        $kind = $this->normalizeNotificationKind($type);

        return [
            'id' => 'notification:'.(int) $notification->id,
            'source_id' => (int) $notification->id,
            'kind' => $kind,
            'lane' => 'ops',
            'title' => (string) $notification->Title,
            'summary' => $this->notificationSummary($kind, $payload),
            'body' => $notification->Body ? (string) $notification->Body : null,
            'status_label' => $notification->read_at ? '已讀' : '新通知',
            'priority' => (string) ($notification->Severity ?: 'info'),
            'due_at' => null,
            'overdue' => false,
            'overdue_hours' => null,
            'read_at' => $notification->read_at,
            'resolved_at' => $notification->ResolvedAt,
            'occurred_at' => optional($notification->OccurredAt ?: $notification->created_at)->toIso8601String(),
            'payload' => $payload,
            'source_type' => $notification->SourceType,
            'action' => $this->opsAction($kind),
        ];
    }

    private function serializeLeaveCase(ExceptionWorkflow $workflow): array
    {
        $studentName = (string) ($workflow->student->name ?? '學生');
        $session = $workflow->classSession;
        $payload = is_array($workflow->payload) ? $workflow->payload : [];

        $date = (string) ($payload['session_date'] ?? ($session->SessionDate ?? ''));
        $start = $this->trimToHM($payload['start_time'] ?? ($session->StartTime ?? ''));
        $end = $this->trimToHM($payload['end_time'] ?? ($session->EndTime ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));

        $dueAt = $workflow->due_at ? Carbon::parse($workflow->due_at) : null;
        $overdue = $dueAt ? $dueAt->lt(now()) : false;
        $overdueHours = null;
        if ($overdue && $dueAt) {
            $overdueHours = (int) max(1, $dueAt->diffInHours(now()));
        }

        $summaryParts = [];
        if ($date !== '') {
            $summaryParts[] = $this->formatDateLabel($date);
        }
        if ($start !== '' && $end !== '') {
            $summaryParts[] = "{$start}–{$end}";
        }

        $body = null;
        if ($reason !== '') {
            $body = '原因：'.$reason;
        }
        if ($dueAt) {
            $dueLine = '請於 '.$dueAt->timezone('Asia/Taipei')->format('n 月 j 日 H:i').' 前處理';
            $body = $body ? ($body."\n".$dueLine) : $dueLine;
        }
        if ($overdue && $overdueHours !== null) {
            $body = ($body ? $body."\n" : '')."已超過建議處理時間 {$overdueHours} 小時";
        }

        return [
            'id' => 'workflow:'.(int) $workflow->id,
            'source_id' => (int) $workflow->id,
            'kind' => 'student_leave',
            'lane' => 'case',
            'title' => "{$studentName}申請請假",
            'summary' => implode(' ', $summaryParts),
            'body' => $body,
            'status_label' => $this->caseStatusLabel((string) $workflow->status),
            'priority' => (string) ($workflow->priority ?: 'medium'),
            'due_at' => $dueAt ? $dueAt->toIso8601String() : null,
            'overdue' => $overdue,
            'overdue_hours' => $overdueHours,
            'read_at' => null,
            'resolved_at' => null,
            'occurred_at' => optional($workflow->created_at)->toIso8601String(),
            'payload' => [
                'student_id' => (int) ($workflow->student_id ?? 0),
                'student_name' => $studentName,
                'reason' => $reason,
                'session_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
            ],
            'source_type' => 'exception_workflow',
            'action' => [
                'label' => '安排補課',
                'target' => 'director',
                'section' => 'exception-workflows',
                'workflow_id' => (int) $workflow->id,
            ],
        ];
    }

    private function caseStatusLabel(string $status): string
    {
        return match ($status) {
            'candidate_ready' => '等待安排補課',
            'confirmed' => '已安排補課',
            'waived' => '已確認不補課',
            default => '等待安排補課',
        };
    }

    private function normalizeNotificationKind(string $type): string
    {
        if ($type === 'substitute_confirm' || $type === 'substitute') {
            return 'substitute';
        }
        if ($type === 'schedule_change') {
            return 'schedule_change';
        }

        return $type;
    }

    private function notificationSummary(string $kind, array $payload): string
    {
        $parts = [];
        if (!empty($payload['student_name'])) {
            $parts[] = (string) $payload['student_name'];
        }
        if (!empty($payload['subject'])) {
            $parts[] = (string) $payload['subject'];
        }
        if ($kind === 'low_sessions' && isset($payload['remaining_sessions'])) {
            $parts[] = '剩餘 '.(int) $payload['remaining_sessions'].' 堂';
        }

        return implode(' ｜ ', $parts);
    }

    private function opsAction(string $kind): array
    {
        $target = match ($kind) {
            'pending_swipe' => 'attendance',
            'learning_review' => 'learning',
            'tuition', 'low_sessions' => 'tuition-collect',
            'schedule_change', 'substitute' => 'calendar',
            default => null,
        };

        return [
            'label' => $target ? '前往處理' : '查看',
            'target' => $target,
            'section' => null,
            'workflow_id' => null,
        ];
    }

    private function sortItems(Collection $items): Collection
    {
        return $items->sort(function (array $a, array $b) {
            $aOverdue = !empty($a['overdue']) ? 0 : 1;
            $bOverdue = !empty($b['overdue']) ? 0 : 1;
            if ($aOverdue !== $bOverdue) {
                return $aOverdue <=> $bOverdue;
            }

            $aDue = $a['due_at'] ?? null;
            $bDue = $b['due_at'] ?? null;
            if ($aDue && $bDue && $aDue !== $bDue) {
                return strcmp((string) $aDue, (string) $bDue);
            }
            if ($aDue && !$bDue) {
                return -1;
            }
            if (!$aDue && $bDue) {
                return 1;
            }

            $sev = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
            $aSev = $sev[$a['priority'] ?? 'info'] ?? 3;
            $bSev = $sev[$b['priority'] ?? 'info'] ?? 3;
            if ($aSev !== $bSev) {
                return $aSev <=> $bSev;
            }

            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        })->values();
    }

    private function formatDateLabel(string $ymd): string
    {
        try {
            $dt = Carbon::parse($ymd, 'Asia/Taipei');
            $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
            $w = $weekdays[(int) $dt->dayOfWeek] ?? '';

            return $dt->format('n 月 j 日').($w !== '' ? "（{$w}）" : '');
        } catch (\Throwable $e) {
            return $ymd;
        }
    }

    private function trimToHM($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}:\d{2})/', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }
}
