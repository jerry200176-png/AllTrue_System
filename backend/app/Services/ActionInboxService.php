<?php

namespace App\Services;

use App\Models\ExceptionWorkflow;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-model only (B-lite + D). Never writes leave Notifications.
 */
class ActionInboxService
{
    public const OPEN_CASE_STATUSES = ['open', 'candidate_ready'];

    /**
     * @param  array<int>  $campusIds
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $campusIds, int $userId, ?string $lane = null): array
    {
        $ops = ($lane === null || $lane === '' || $lane === 'ops')
            ? $this->buildOpsItems($campusIds, $userId)
            : collect();
        $cases = ($lane === null || $lane === '' || $lane === 'case')
            ? $this->buildCaseItems($campusIds)
            : collect();

        $sorted = $this->sortItems($ops->concat($cases)->values());

        return [
            'data' => $sorted->all(),
            'meta' => [
                'notifications_unread' => $ops->filter(fn ($r) => empty($r['read_at']))->count(),
                'cases_open' => $cases->count(),
                'needs_attention' => $ops->filter(fn ($r) => empty($r['read_at']))->count() + $cases->count(),
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
        $unread = $this->countUnreadNotifications($campusIds, $userId);
        $cases = $this->countOpenCases($campusIds);

        return [
            'notifications_unread' => $unread,
            'cases_open' => $cases,
            'needs_attention' => $unread + $cases,
        ];
    }

    /** @param  array<int>  $campusIds */
    private function countUnreadNotifications(array $campusIds, int $userId): int
    {
        $q = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt');
        if (!empty($campusIds)) {
            $q->whereIn('Notifications.CampusID', $campusIds);
        }

        return (int) $q->count('Notifications.id');
    }

    /** @param  array<int>  $campusIds */
    private function countOpenCases(array $campusIds): int
    {
        $q = ExceptionWorkflow::query()
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);
        if (!empty($campusIds)) {
            $q->whereIn('campus_id', $campusIds);
        }

        return (int) $q->count();
    }

    /** @param  array<int>  $campusIds */
    private function buildOpsItems(array $campusIds, int $userId): Collection
    {
        $q = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->select(['Notifications.*', 'nr.ReadAt as read_at'])
            ->whereNull('Notifications.ResolvedAt');
        if (!empty($campusIds)) {
            $q->whereIn('Notifications.CampusID', $campusIds);
        }

        return $q->orderByDesc('Notifications.id')->limit(100)->get()
            ->map(fn ($n) => $this->serializeNotification($n));
    }

    /** @param  array<int>  $campusIds */
    private function buildCaseItems(array $campusIds): Collection
    {
        $q = ExceptionWorkflow::query()
            ->with(['student', 'classSession'])
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);
        if (!empty($campusIds)) {
            $q->whereIn('campus_id', $campusIds);
        }

        return $q->orderByRaw('due_at IS NULL ASC')->orderBy('due_at')->orderByDesc('id')
            ->limit(50)->get()
            ->map(fn (ExceptionWorkflow $w) => $this->serializeLeaveCase($w));
    }

    private function serializeNotification($n): array
    {
        $type = (string) $n->Type;
        $kind = in_array($type, ['substitute', 'substitute_confirm'], true) ? 'substitute' : $type;
        $payload = is_array($n->Payload) ? $n->Payload : [];
        $target = match ($kind) {
            'pending_swipe' => 'attendance',
            'learning_review' => 'learning',
            'tuition', 'low_sessions' => 'tuition-collect',
            'schedule_change', 'substitute' => 'calendar',
            default => null,
        };

        return [
            'id' => 'notification:'.(int) $n->id,
            'source_id' => (int) $n->id,
            'kind' => $kind,
            'lane' => 'ops',
            'title' => (string) $n->Title,
            'summary' => $this->notificationSummary($kind, $payload),
            'body' => $n->Body ? (string) $n->Body : null,
            'status_label' => $n->read_at ? '已讀' : '新通知',
            'priority' => (string) ($n->Severity ?: 'info'),
            'due_at' => null,
            'overdue' => false,
            'overdue_hours' => null,
            'read_at' => $n->read_at,
            'resolved_at' => $n->ResolvedAt,
            'occurred_at' => optional($n->OccurredAt ?: $n->created_at)->toIso8601String(),
            'payload' => $payload,
            'source_type' => $n->SourceType,
            'action' => [
                'label' => $target ? '前往處理' : '查看',
                'target' => $target,
                'section' => null,
                'workflow_id' => null,
            ],
        ];
    }

    private function serializeLeaveCase(ExceptionWorkflow $w): array
    {
        $student = $w->relationLoaded('student') ? $w->getRelation('student') : null;
        $studentName = ($student && method_exists($student, 'getAttribute'))
            ? (string) ($student->getAttribute('name') ?? '學生')
            : '學生';
        $studentId = ($student && method_exists($student, 'getAttribute'))
            ? (int) ($student->getAttribute('id') ?? 0)
            : (int) ($w->getAttribute('student_id') ?? 0);

        $session = $w->relationLoaded('classSession') ? $w->getRelation('classSession') : null;
        $payloadRaw = $w->getAttribute('payload');
        $payload = is_array($payloadRaw) ? $payloadRaw : [];
        $date = (string) ($payload['session_date'] ?? ($session ? $session->getAttribute('SessionDate') : '') ?? '');
        $start = $this->hm($payload['start_time'] ?? ($session ? $session->getAttribute('StartTime') : '') ?? '');
        $end = $this->hm($payload['end_time'] ?? ($session ? $session->getAttribute('EndTime') : '') ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));
        $dueRaw = $w->getAttribute('due_at');
        $dueAt = $dueRaw ? Carbon::parse($dueRaw) : null;
        $overdue = $dueAt !== null && $dueAt->lt(now());
        $overdueHours = $overdue ? (int) max(1, $dueAt->diffInHours(now())) : null;

        $summary = trim(($date !== '' ? $this->dateLabel($date).' ' : '').($start && $end ? "{$start}–{$end}" : ''));
        $bodyParts = [];
        if ($reason !== '') {
            $bodyParts[] = '原因：'.$reason;
        }
        if ($dueAt) {
            $bodyParts[] = '請於 '.$dueAt->timezone('Asia/Taipei')->format('n 月 j 日 H:i').' 前處理';
        }
        if ($overdueHours !== null) {
            $bodyParts[] = "已超過建議處理時間 {$overdueHours} 小時";
        }

        $workflowId = (int) $w->getAttribute('id');

        return [
            'id' => 'workflow:'.$workflowId,
            'source_id' => $workflowId,
            'kind' => 'student_leave',
            'lane' => 'case',
            'title' => "{$studentName}申請請假",
            'summary' => $summary,
            'body' => $bodyParts === [] ? null : implode("\n", $bodyParts),
            'status_label' => '等待安排補課',
            'priority' => (string) ($w->getAttribute('priority') ?: 'medium'),
            'due_at' => $dueAt ? $dueAt->toIso8601String() : null,
            'overdue' => $overdue,
            'overdue_hours' => $overdueHours,
            'read_at' => null,
            'resolved_at' => null,
            'occurred_at' => optional($w->getAttribute('created_at'))->toIso8601String(),
            'payload' => [
                'student_id' => $studentId,
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
                'workflow_id' => $workflowId,
            ],
        ];
    }

    private function notificationSummary(string $kind, array $payload): string
    {
        $parts = array_filter([
            $payload['student_name'] ?? null,
            $payload['subject'] ?? null,
            $kind === 'low_sessions' && isset($payload['remaining_sessions'])
                ? '剩餘 '.(int) $payload['remaining_sessions'].' 堂'
                : null,
        ]);

        return implode(' ｜ ', $parts);
    }

    private function sortItems(Collection $items): Collection
    {
        return $items->sort(function (array $a, array $b) {
            $ao = !empty($a['overdue']) ? 0 : 1;
            $bo = !empty($b['overdue']) ? 0 : 1;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
            $ad = (string) ($a['due_at'] ?? '');
            $bd = (string) ($b['due_at'] ?? '');
            if ($ad !== '' && $bd !== '' && $ad !== $bd) {
                return strcmp($ad, $bd);
            }
            if ($ad !== '' && $bd === '') {
                return -1;
            }
            if ($ad === '' && $bd !== '') {
                return 1;
            }
            $sev = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];

            return ($sev[$a['priority'] ?? 'info'] ?? 3) <=> ($sev[$b['priority'] ?? 'info'] ?? 3)
                ?: strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        })->values();
    }

    private function dateLabel(string $ymd): string
    {
        try {
            $dt = Carbon::parse($ymd, 'Asia/Taipei');
            $w = ['日', '一', '二', '三', '四', '五', '六'][(int) $dt->dayOfWeek] ?? '';

            return $dt->format('n 月 j 日').($w !== '' ? "（{$w}）" : '');
        } catch (\Throwable $e) {
            return $ymd;
        }
    }

    private function hm($value): string
    {
        $raw = trim((string) $value);
        if ($raw !== '' && preg_match('/^(\d{1,2}:\d{2})/', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }
}
