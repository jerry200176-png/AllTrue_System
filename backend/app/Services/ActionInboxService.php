<?php

namespace App\Services;

use App\Models\ExceptionWorkflow;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-model only (B-lite + D). Never writes leave Notifications.
 *
 * Campus scope contract:
 * - mode "all"  → super_admin without branch filter (empty campus_ids is OK only here)
 * - mode "ids"  → non-empty campus_ids; empty ids must never be passed (fail-closed at controller)
 */
class ActionInboxService
{
    public const OPEN_CASE_STATUSES = ['open', 'candidate_ready'];

    public const DUE_SOON_HOURS = 48;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public const OPS_DEFAULT_PER_PAGE = 50;

    public const OPS_MAX_PER_PAGE = 100;

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return array<string, int>
     */
    public function count(array $scope, int $userId): array
    {
        $unread = $this->countUnreadNotifications($scope, $userId);
        $unresolved = $this->countCases($scope, self::OPEN_CASE_STATUSES);
        $overdue = $this->countOverdueCases($scope);
        $dueSoon = $this->countDueSoonCases($scope);
        $urgentNotifs = $this->countUrgentUnreadNotifications($scope, $userId);
        $urgentTotal = $urgentNotifs + $overdue;
        $badgeTotal = $unread + $unresolved;

        return [
            'notifications_unread' => $unread,
            'cases_unresolved' => $unresolved,
            'cases_overdue' => $overdue,
            'cases_due_soon' => $dueSoon,
            'urgent_total' => $urgentTotal,
            'badge_total' => $badgeTotal,
            // Deprecated aliases (remove after 2026-09-01). New clients must use cases_unresolved / badge_total.
            'cases_open' => $unresolved,
            'needs_attention' => $badgeTotal,
        ];
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return array<string, mixed>
     */
    public function list(
        array $scope,
        int $userId,
        ?string $lane = null,
        int $casesPage = 1,
        int $casesPerPage = self::DEFAULT_PER_PAGE,
        ?string $caseFilter = null,
        int $opsPage = 1,
        int $opsPerPage = self::OPS_DEFAULT_PER_PAGE
    ): array {
        $summary = $this->count($scope, $userId);

        $includeOps = $lane === null || $lane === '' || $lane === 'ops';
        $includeCases = $lane === null || $lane === '' || $lane === 'case';

        $ops = $includeOps
            ? $this->paginateOps($scope, $userId, $opsPage, $opsPerPage)
            : $this->emptyPage($opsPage, $opsPerPage);

        $cases = $includeCases
            ? $this->paginateCases($scope, $casesPage, $casesPerPage, $caseFilter)
            : $this->emptyPage($casesPage, $casesPerPage);

        return [
            'summary' => $summary,
            'cases' => $cases,
            'ops' => $ops,
            'meta' => [
                'contract_version' => 2,
                'scope_mode' => $scope['mode'],
                'deprecated_aliases' => [
                    'cases_open' => 'use cases_unresolved; remove after 2026-09-01',
                    'needs_attention' => 'use badge_total (neutral total, not "needs action"); remove after 2026-09-01',
                ],
                // Legacy flat mirrors for transitional clients (prefer summary + cases/ops).
                'notifications_unread' => $summary['notifications_unread'],
                'cases_unresolved' => $summary['cases_unresolved'],
                'cases_overdue' => $summary['cases_overdue'],
                'cases_due_soon' => $summary['cases_due_soon'],
                'urgent_total' => $summary['urgent_total'],
                'badge_total' => $summary['badge_total'],
                'cases_open' => $summary['cases_open'],
                'needs_attention' => $summary['needs_attention'],
            ],
        ];
    }

    /**
     * Deep-link target: single display DTO (open or closed). Same campus scope as list/count.
     *
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return array<string, mixed>|null null when not found or out of scope
     */
    public function getCase(array $scope, int $workflowId): ?array
    {
        $q = ExceptionWorkflow::query()
            ->with(['student', 'classSession'])
            ->where('type', 'student_leave')
            ->where('id', $workflowId);
        $this->applyCampusScope($q, $scope, 'campus_id');

        /** @var ExceptionWorkflow|null $w */
        $w = $q->first();
        if (!$w) {
            return null;
        }

        return $this->serializeLeaveCase($w, true);
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function countUnreadNotifications(array $scope, int $userId): int
    {
        $q = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt');
        $this->applyCampusScope($q, $scope, 'Notifications.CampusID');

        return (int) $q->count('Notifications.id');
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function countUrgentUnreadNotifications(array $scope, int $userId): int
    {
        $q = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt')
            ->where('Notifications.Severity', 'high');
        $this->applyCampusScope($q, $scope, 'Notifications.CampusID');

        return (int) $q->count('Notifications.id');
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @param  list<string>  $statuses
     */
    private function countCases(array $scope, array $statuses): int
    {
        $q = ExceptionWorkflow::query()
            ->where('type', 'student_leave')
            ->whereIn('status', $statuses);
        $this->applyCampusScope($q, $scope, 'campus_id');

        return (int) $q->count();
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function countOverdueCases(array $scope): int
    {
        $q = $this->baseUnresolvedCaseQuery($scope)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());

        return (int) $q->count();
    }

    /**
     * Due soon: not overdue, due_at within DUE_SOON_HOURS.
     *
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function countDueSoonCases(array $scope): int
    {
        $now = now();
        $until = (clone $now)->addHours(self::DUE_SOON_HOURS);
        $q = $this->baseUnresolvedCaseQuery($scope)
            ->whereNotNull('due_at')
            ->where('due_at', '>=', $now)
            ->where('due_at', '<=', $until);

        return (int) $q->count();
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function baseUnresolvedCaseQuery(array $scope): Builder
    {
        $q = ExceptionWorkflow::query()
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);
        $this->applyCampusScope($q, $scope, 'campus_id');

        return $q;
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return array{data: list<array<string, mixed>>, total: int, current_page: int, per_page: int, last_page: int, has_more: bool}
     */
    private function paginateOps(array $scope, int $userId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(self::OPS_MAX_PER_PAGE, max(1, $perPage));

        $base = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt');
        $this->applyCampusScope($base, $scope, 'Notifications.CampusID');

        $total = (int) (clone $base)->count('Notifications.id');
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = (clone $base)
            ->select(['Notifications.*', 'nr.ReadAt as read_at'])
            ->orderByDesc('Notifications.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($n) => $this->serializeNotification($n))
            ->values()
            ->all();

        return [
            'data' => $rows,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ];
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return array{data: list<array<string, mixed>>, total: int, current_page: int, per_page: int, last_page: int, has_more: bool}
     */
    private function paginateCases(array $scope, int $page, int $perPage, ?string $caseFilter): array
    {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $q = $this->baseUnresolvedCaseQuery($scope);
        $this->applyCaseFilter($q, $caseFilter);

        $total = (int) (clone $q)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        // Sort: overdue → closest due_at → candidate_ready → other open → created_at desc
        $rows = (clone $q)
            ->with(['student', 'classSession'])
            ->orderByRaw('CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at')
            ->orderByRaw("CASE WHEN status = 'candidate_ready' THEN 0 WHEN status = 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (ExceptionWorkflow $w) => $this->serializeLeaveCase($w, false))
            ->values()
            ->all();

        return [
            'data' => $rows,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ];
    }

    private function applyCaseFilter(Builder $q, ?string $caseFilter): void
    {
        $filter = $caseFilter ?: 'unresolved';
        $now = now();
        $until = (clone $now)->addHours(self::DUE_SOON_HOURS);

        switch ($filter) {
            case 'overdue':
                $q->whereNotNull('due_at')->where('due_at', '<', $now);
                break;
            case 'due_soon':
                $q->whereNotNull('due_at')
                    ->where('due_at', '>=', $now)
                    ->where('due_at', '<=', $until);
                break;
            case 'candidate_ready':
                $q->where('status', 'candidate_ready');
                break;
            case 'waiting':
            case 'open':
                $q->where('status', 'open');
                break;
            case 'unresolved':
            case 'all':
            default:
                // already limited to OPEN_CASE_STATUSES
                break;
        }
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function applyCampusScope(Builder $q, array $scope, string $column): void
    {
        if (($scope['mode'] ?? '') === 'all') {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $scope['campus_ids'] ?? [])));
        // Defensive: never treat empty ids as "all".
        if ($ids === []) {
            $q->whereRaw('1 = 0');

            return;
        }

        $q->whereIn($column, $ids);
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, current_page: int, per_page: int, last_page: int, has_more: bool}
     */
    private function emptyPage(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'total' => 0,
            'current_page' => max(1, $page),
            'per_page' => max(1, $perPage),
            'last_page' => 1,
            'has_more' => false,
        ];
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

        $severity = (string) ($n->Severity ?: 'info');
        $readAt = $n->read_at ?? null;

        return [
            'id' => 'notification:'.(int) $n->id,
            'lane' => 'ops',
            'kind' => $kind,
            'title' => (string) $n->Title,
            'summary' => $this->notificationSummary($kind, $payload),
            'status_code' => $readAt ? 'read' : 'unread',
            'status_label' => $readAt ? '已讀' : '新通知',
            'priority' => $severity,
            'due_at' => null,
            'overdue' => false,
            'occurred_at' => optional($n->OccurredAt ?: $n->created_at)->toIso8601String(),
            'campus_id' => (int) $n->CampusID,
            'source_id' => (int) $n->id,
            'read_at' => $readAt,
            'action' => [
                'label' => $target ? '前往處理' : '查看',
                'type' => 'open_notification_target',
                'target' => $target,
                'section' => null,
                'workflow_id' => null,
            ],
            // Display context only — never echo raw Payload.
            'context' => $this->notificationContext($kind, $payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLeaveCase(ExceptionWorkflow $w, bool $includeClosed): array
    {
        $student = $w->relationLoaded('student') ? $w->getRelation('student') : null;
        $studentName = ($student && method_exists($student, 'getAttribute'))
            ? (string) ($student->getAttribute('name') ?? '學生')
            : '學生';

        $session = $w->relationLoaded('classSession') ? $w->getRelation('classSession') : null;
        $payloadRaw = $w->getAttribute('payload');
        $payload = is_array($payloadRaw) ? $payloadRaw : [];
        $date = (string) ($payload['session_date'] ?? ($session ? $session->getAttribute('SessionDate') : '') ?? '');
        $start = $this->hm($payload['start_time'] ?? ($session ? $session->getAttribute('StartTime') : '') ?? '');
        $end = $this->hm($payload['end_time'] ?? ($session ? $session->getAttribute('EndTime') : '') ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));
        $reasonPreview = $reason === '' ? null : mb_substr($reason, 0, 40);

        $dueRaw = $w->getAttribute('due_at');
        $dueAt = $dueRaw ? Carbon::parse($dueRaw) : null;
        $overdue = $dueAt !== null && $dueAt->lt(now());
        $dueSoon = !$overdue && $dueAt !== null
            && $dueAt->gte(now())
            && $dueAt->lte(now()->addHours(self::DUE_SOON_HOURS));

        $status = (string) $w->getAttribute('status');
        $statusLabel = match ($status) {
            'open' => '等待安排補課',
            'candidate_ready' => '補課方案待確認',
            'confirmed' => '已安排補課',
            'waived' => '已確認不補課',
            default => $status,
        };

        $actionLabel = match ($status) {
            'open' => '安排補課',
            'candidate_ready' => '檢視並確認',
            'confirmed', 'waived' => '查看結果',
            default => '查看',
        };

        $displayPriority = $overdue ? 'overdue' : ($dueSoon ? 'due_soon' : 'normal');
        $summary = trim(($date !== '' ? $this->dateLabel($date).' ' : '').($start && $end ? "{$start}–{$end}" : ''));
        $workflowId = (int) $w->getAttribute('id');

        $dto = [
            'id' => 'workflow:'.$workflowId,
            'lane' => 'case',
            'kind' => 'student_leave',
            'title' => "{$studentName}申請請假",
            'summary' => $summary,
            'student_name' => $studentName,
            'session_date' => $date !== '' ? $date : null,
            'session_start' => $start !== '' ? $start : null,
            'session_end' => $end !== '' ? $end : null,
            'reason_preview' => $reasonPreview,
            'status_code' => $status,
            'status_label' => $statusLabel,
            'priority' => $displayPriority,
            'due_at' => $dueAt ? $dueAt->toIso8601String() : null,
            'overdue' => $overdue,
            'occurred_at' => ($created = $w->getAttribute('created_at'))
                ? Carbon::parse($created)->toIso8601String()
                : null,
            'campus_id' => (int) $w->getAttribute('campus_id'),
            'workflow_id' => $workflowId,
            'action' => [
                'label' => $actionLabel,
                'type' => 'open_exception_workflow',
                'target' => 'director',
                'section' => 'exception-workflows',
                'workflow_id' => $workflowId,
            ],
        ];

        if ($includeClosed && in_array($status, ['confirmed', 'waived'], true)) {
            $closedAt = $w->getAttribute('closed_at');
            $dto['closed_at'] = $closedAt ? Carbon::parse($closedAt)->toIso8601String() : null;
        }

        return $dto;
    }

    /**
     * Minimal display context — no raw notification Payload dump.
     *
     * @return array<string, scalar|null>
     */
    private function notificationContext(string $kind, array $payload): array
    {
        $ctx = [];
        if (isset($payload['subject']) && is_scalar($payload['subject'])) {
            $ctx['subject'] = (string) $payload['subject'];
        }
        if ($kind === 'low_sessions' && isset($payload['remaining_sessions'])) {
            $ctx['remaining_sessions'] = (int) $payload['remaining_sessions'];
        }
        // student_name is display-only for ops cards the director already can see in-app.
        if (isset($payload['student_name']) && is_string($payload['student_name'])) {
            $ctx['student_name'] = mb_substr($payload['student_name'], 0, 40);
        }

        return $ctx;
    }

    private function notificationSummary(string $kind, array $payload): string
    {
        $parts = array_filter([
            isset($payload['student_name']) ? mb_substr((string) $payload['student_name'], 0, 40) : null,
            isset($payload['subject']) ? (string) $payload['subject'] : null,
            $kind === 'low_sessions' && isset($payload['remaining_sessions'])
                ? '剩餘 '.(int) $payload['remaining_sessions'].' 堂'
                : null,
        ]);

        return implode(' ｜ ', $parts);
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
