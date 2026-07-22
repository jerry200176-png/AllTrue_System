<?php
namespace App\Services;
use App\Models\ExceptionWorkflow;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
/** Read-model only (B-lite + D). Never writes leave Notifications. */
class ActionInboxService
{
    public const OPEN_CASE_STATUSES = ['open', 'candidate_ready'];
    public const DUE_SOON_HOURS = 48;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 50;
    public const OPS_DEFAULT_PER_PAGE = 50;
    public const OPS_MAX_PER_PAGE = 100;
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    public function count(array $scope, int $userId): array
    {
        $unread = $this->countUnread($scope, $userId, null);
        $unresolved = $this->countCases($scope);
        $overdue = $this->countCases($scope, 'overdue');
        $dueSoon = $this->countCases($scope, 'due_soon');
        $urgent = $this->countUnread($scope, $userId, 'high') + $overdue;
        $badge = $unread + $unresolved;
        return [
            'notifications_unread' => $unread,
            'cases_unresolved' => $unresolved,
            'cases_overdue' => $overdue,
            'cases_due_soon' => $dueSoon,
            'urgent_total' => $urgent,
            'badge_total' => $badge,
            // Deprecated aliases — remove after 2026-09-01
            'cases_open' => $unresolved,
            'needs_attention' => $badge,
        ];
    }
    /**
     * @param array{mode: string, campus_ids: array<int>} $scope
     * @return array<string, mixed>
     */
    public function list(
        array $scope,
        int $userId,
        ?string $lane = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $caseFilter = null,
        int $opsPage = 1,
        int $opsPerPage = self::OPS_DEFAULT_PER_PAGE
    ): array {
        $summary = $this->count($scope, $userId);
        $wantOps = $lane === null || $lane === '' || $lane === 'ops';
        $wantCases = $lane === null || $lane === '' || $lane === 'case';
        return [
            'summary' => $summary,
            'cases' => $wantCases
                ? $this->paginateCases($scope, $page, $perPage, $caseFilter)
                : $this->emptyPage($page, $perPage),
            'ops' => $wantOps
                ? $this->paginateOps($scope, $userId, $opsPage, $opsPerPage)
                : $this->emptyPage($opsPage, $opsPerPage),
            'meta' => array_merge($summary, [
                'contract_version' => 2,
                'scope_mode' => $scope['mode'],
                'deprecated_aliases' => [
                    'cases_open' => 'use cases_unresolved; remove after 2026-09-01',
                    'needs_attention' => 'use badge_total; remove after 2026-09-01',
                ],
            ]),
        ];
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    public function getCase(array $scope, int $workflowId): ?array
    {
        $q = ExceptionWorkflow::query()->with(['student', 'classSession'])
            ->where('type', 'student_leave')->where('id', $workflowId);
        $this->scopeCampus($q, $scope, 'campus_id');
        $w = $q->first();
        return $w ? $this->dtoCase($w, true) : null;
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    private function countUnread(array $scope, int $userId, ?string $severity): int
    {
        $q = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')->whereNull('nr.ReadAt');
        if ($severity !== null) {
            $q->where('Notifications.Severity', $severity);
        }
        $this->scopeCampus($q, $scope, 'Notifications.CampusID');
        return (int) $q->count('Notifications.id');
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    private function countCases(array $scope, ?string $filter = null): int
    {
        $q = $this->caseQuery($scope, $filter);
        return (int) $q->count();
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    private function caseQuery(array $scope, ?string $filter = null): Builder
    {
        $q = ExceptionWorkflow::query()
            ->where('type', 'student_leave')
            ->whereIn('status', self::OPEN_CASE_STATUSES);
        $this->scopeCampus($q, $scope, 'campus_id');
        $now = now();
        $until = (clone $now)->addHours(self::DUE_SOON_HOURS);
        if ($filter === 'overdue') {
            $q->whereNotNull('due_at')->where('due_at', '<', $now);
        } elseif ($filter === 'due_soon') {
            $q->whereNotNull('due_at')->where('due_at', '>=', $now)->where('due_at', '<=', $until);
        } elseif ($filter === 'candidate_ready') {
            $q->where('status', 'candidate_ready');
        } elseif ($filter === 'waiting' || $filter === 'open') {
            $q->where('status', 'open');
        }
        return $q;
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope @return array<string, mixed> */
    private function paginateOps(array $scope, int $userId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(self::OPS_MAX_PER_PAGE, max(1, $perPage));
        $base = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt');
        $this->scopeCampus($base, $scope, 'Notifications.CampusID');
        $total = (int) (clone $base)->count('Notifications.id');
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($page, $last);
        $rows = (clone $base)->select(['Notifications.*', 'nr.ReadAt as read_at'])
            ->orderByDesc('Notifications.id')->forPage($page, $perPage)->get()
            ->map(fn ($n) => $this->dtoOps($n))->values()->all();
        return $this->page($rows, $total, $page, $perPage, $last);
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope @return array<string, mixed> */
    private function paginateCases(array $scope, int $page, int $perPage, ?string $filter): array
    {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $q = $this->caseQuery($scope, $filter);
        $total = (int) (clone $q)->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($page, $last);
        $rows = (clone $q)->with(['student', 'classSession'])
            ->orderByRaw('CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw('due_at IS NULL ASC')->orderBy('due_at')
            ->orderByRaw("CASE WHEN status = 'candidate_ready' THEN 0 WHEN status = 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)->get()
            ->map(fn (ExceptionWorkflow $w) => $this->dtoCase($w, false))->values()->all();
        return $this->page($rows, $total, $page, $perPage, $last);
    }
    /** @param array{mode: string, campus_ids: array<int>} $scope */
    private function scopeCampus(Builder $q, array $scope, string $column): void
    {
        if ($scope['mode'] === 'all') {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $scope['campus_ids'])));
        // Never treat empty ids as all.
        $q->whereIn($column, $ids === [] ? [-1] : $ids);
    }
    /** @return array<string, mixed> */
    private function emptyPage(int $page, int $perPage): array
    {
        return $this->page([], 0, max(1, $page), max(1, $perPage), 1);
    }
    /** @param list<array<string, mixed>> $data @return array<string, mixed> */
    private function page(array $data, int $total, int $page, int $perPage, int $last): array
    {
        return [
            'data' => $data,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $last,
            'has_more' => $page < $last,
        ];
    }
    private function dtoOps($n): array
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
        $ctx = [];
        if (isset($payload['subject']) && is_scalar($payload['subject'])) {
            $ctx['subject'] = (string) $payload['subject'];
        }
        if ($kind === 'low_sessions' && isset($payload['remaining_sessions'])) {
            $ctx['remaining_sessions'] = (int) $payload['remaining_sessions'];
        }
        if (isset($payload['student_name']) && is_string($payload['student_name'])) {
            $ctx['student_name'] = mb_substr($payload['student_name'], 0, 40);
        }
        $parts = array_filter([
            $ctx['student_name'] ?? null,
            $ctx['subject'] ?? null,
            isset($ctx['remaining_sessions']) ? '剩餘 '.$ctx['remaining_sessions'].' 堂' : null,
        ]);
        $readAt = $n->read_at ?? null;
        return [
            'id' => 'notification:'.(int) $n->id,
            'lane' => 'ops',
            'kind' => $kind,
            'title' => (string) $n->Title,
            'summary' => implode(' ｜ ', $parts),
            'status_code' => $readAt ? 'read' : 'unread',
            'status_label' => $readAt ? '已讀' : '新通知',
            'priority' => (string) ($n->Severity ?: 'info'),
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
            'context' => $ctx,
        ];
    }
    private function dtoCase(ExceptionWorkflow $w, bool $includeClosed): array
    {
        $student = $w->relationLoaded('student') ? $w->getRelation('student') : null;
        $studentName = ($student && method_exists($student, 'getAttribute'))
            ? (string) ($student->getAttribute('name') ?? '學生') : '學生';
        $session = $w->relationLoaded('classSession') ? $w->getRelation('classSession') : null;
        $payload = is_array($w->getAttribute('payload')) ? $w->getAttribute('payload') : [];
        $date = (string) ($payload['session_date'] ?? ($session ? $session->getAttribute('SessionDate') : '') ?? '');
        $start = $this->hm($payload['start_time'] ?? ($session ? $session->getAttribute('StartTime') : '') ?? '');
        $end = $this->hm($payload['end_time'] ?? ($session ? $session->getAttribute('EndTime') : '') ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));
        $dueRaw = $w->getAttribute('due_at');
        $dueAt = $dueRaw ? Carbon::parse($dueRaw) : null;
        $overdue = $dueAt !== null && $dueAt->lt(now());
        $dueSoon = !$overdue && $dueAt !== null && $dueAt->gte(now())
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
        $summary = trim(($date !== '' ? $this->dateLabel($date).' ' : '').($start && $end ? "{$start}–{$end}" : ''));
        $id = (int) $w->getAttribute('id');
        $dto = [
            'id' => 'workflow:'.$id,
            'lane' => 'case',
            'kind' => 'student_leave',
            'title' => "{$studentName}申請請假",
            'summary' => $summary,
            'student_name' => $studentName,
            'session_date' => $date !== '' ? $date : null,
            'session_start' => $start !== '' ? $start : null,
            'session_end' => $end !== '' ? $end : null,
            'reason_preview' => $reason === '' ? null : mb_substr($reason, 0, 40),
            'status_code' => $status,
            'status_label' => $statusLabel,
            'priority' => $overdue ? 'overdue' : ($dueSoon ? 'due_soon' : 'normal'),
            'due_at' => $dueAt ? $dueAt->toIso8601String() : null,
            'overdue' => $overdue,
            'occurred_at' => ($c = $w->getAttribute('created_at')) ? Carbon::parse($c)->toIso8601String() : null,
            'campus_id' => (int) $w->getAttribute('campus_id'),
            'workflow_id' => $id,
            'action' => [
                'label' => $actionLabel,
                'type' => 'open_exception_workflow',
                'target' => 'director',
                'section' => 'exception-workflows',
                'workflow_id' => $id,
            ],
        ];
        if ($includeClosed && in_array($status, ['confirmed', 'waived'], true)) {
            $closed = $w->getAttribute('closed_at');
            $dto['closed_at'] = $closed ? Carbon::parse($closed)->toIso8601String() : null;
        }
        return $dto;
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
        return ($raw !== '' && preg_match('/^(\d{1,2}:\d{2})/', $raw, $m)) ? $m[1] : $raw;
    }
}
