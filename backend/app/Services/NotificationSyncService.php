<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\PendingSwipe;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationSyncService
{
    /**
     * in-app #413: types written elsewhere (never created here) whose source row tells whether the
     * director still has work to do. Each resolver returns notification id => still open, only for rows
     * it could evaluate; it touches only its own Type. `substitute` stays out on purpose: its ResolvedAt
     * means "substitute undone/restored" (undo endpoint, calendar badge), not "handled".
     */
    private const SOURCE_RESOLVED_TYPES = ['deduction_failed', 'lr_feedback'];

    /**
     * Resolved lr_feedback rows older than the awaiting window cannot become awaiting again, so they
     * are not re-checked. deduction_failed has no such bound: a ledger reversal can come any time later.
     */
    private const FEEDBACK_REOPEN_LOOKBACK_DAYS = ParentFeedbackAwaitingService::AWAITING_STALE_AFTER_DAYS;

    /**
     * Sync notifications from multiple business sources.
     *
     * @param  array<int>  $campusIds  Empty array means all campuses (super_admin scope).
     * @param  int|null    $branchId   Optional forced branch scope.
     */
    public static function sync(array $campusIds = [], ?int $branchId = null): array
    {
        $scopedCampusIds = self::resolveCampusScope($campusIds, $branchId);
        $managedTypes = ['tuition', 'learning_review', 'pending_swipe', 'low_sessions'];
        $activeByKey = array_merge(
            self::buildTuitionNotifications($scopedCampusIds),
            self::buildLowSessionsNotifications($scopedCampusIds),
            self::buildInvoiceOverdueNotifications($scopedCampusIds),
            self::buildLearningNotifications($scopedCampusIds),
            self::buildPendingSwipeNotifications($scopedCampusIds)
        );

        // One notice per owed debt: an overdue-Invoice notice (it carries the paid amount, due date and
        // overdue tier) wins over the per-course notice for the same (campus, course); the per-course
        // notice stays for courses with no overdue invoice.
        $invoiceCourses = [];
        foreach ($activeByKey as $row) {
            if (($row['Type'] ?? null) === 'tuition' && ($row['SourceType'] ?? null) === 'Invoice'
                && ($row['Payload']['student_class_id'] ?? null)) {
                $invoiceCourses[(int) $row['CampusID'] . ':' . (int) $row['Payload']['student_class_id']] = true;
            }
        }
        $activeByKey = array_filter($activeByKey, fn ($row) => !(
            ($row['Type'] ?? null) === 'tuition' && ($row['SourceType'] ?? null) === 'StudentClass'
            && isset($invoiceCourses[(int) $row['CampusID'] . ':' . (int) $row['SourceID']])
        ));

        $created = 0;
        $updated = 0;
        $resolved = 0;
        $sourceKeyRacesRecovered = 0;

        DB::transaction(function () use (
            $managedTypes,
            $scopedCampusIds,
            $activeByKey,
            &$created,
            &$updated,
            &$resolved,
            &$sourceKeyRacesRecovered
        ) {
            $activeKeys = array_keys($activeByKey);

            $scopedUnresolvedQuery = Notification::query()
                ->whereIn('Type', $managedTypes)
                ->whereNull('ResolvedAt');

            if (!empty($scopedCampusIds)) {
                $scopedUnresolvedQuery->whereIn('CampusID', $scopedCampusIds);
            }

            $scopedUnresolved = $scopedUnresolvedQuery->get()->keyBy('SourceKey');

            $existingByKey = empty($activeKeys)
                ? collect()
                : Notification::whereIn('SourceKey', $activeKeys)->get()->keyBy('SourceKey');

            foreach ($activeByKey as $sourceKey => $payload) {
                /** @var Notification|null $existing */
                $existing = $existingByKey->get($sourceKey);
                if ($existing) {
                    self::reopenIfOwedChanged($existing, $payload);
                    self::applyPayload($existing, $payload);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    }
                    continue;
                }

                try {
                    $payload['OccurredAt'] ??= now();
                    Notification::create($payload);
                    $created++;
                } catch (QueryException $e) {
                    if (!self::isSourceKeyDuplicate($e)) {
                        throw $e;
                    }

                    // A normal SELECT can keep the transaction's pre-race snapshot under
                    // MySQL REPEATABLE READ. A locking read is a current read, so it sees
                    // the row committed by the concurrent winner after our snapshot began.
                    $existing = Notification::where('SourceKey', $sourceKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        $sourceKeyRacesRecovered++;
                        Log::notice('notification_source_key_race_recovered', [
                            'campus_id' => (int) ($payload['CampusID'] ?? 0),
                            'notification_type' => (string) ($payload['Type'] ?? ''),
                            'source_type' => (string) ($payload['SourceType'] ?? ''),
                            'source_key_sha256' => hash('sha256', $sourceKey),
                        ]);
                        self::reopenIfOwedChanged($existing, $payload);
                        self::applyPayload($existing, $payload);
                        if ($existing->isDirty()) {
                            $existing->save();
                            $updated++;
                        }
                        continue;
                    }

                    throw $e;
                }
            }

            foreach ($scopedUnresolved as $sourceKey => $notification) {
                if (isset($activeByKey[$sourceKey])) {
                    continue;
                }

                $notification->ResolvedAt = now();
                $notification->save();
                $resolved++;
            }
        });

        $sourceChecked = self::recheckSourceResolvedTypes($scopedCampusIds);

        return [
            'created' => $created,
            'updated' => $updated,
            'resolved' => $resolved,
            'active_count' => count($activeByKey),
            'source_key_races_recovered' => $sourceKeyRacesRecovered,
            'source_resolved' => $sourceChecked['resolved'],
            'source_reopened' => $sourceChecked['reopened'],
        ];
    }

    /**
     * @param  array<int>  $campusIds
     * @return array{resolved:int, reopened:int}
     */
    private static function recheckSourceResolvedTypes(array $campusIds): array
    {
        if (!config('perfflags.notification_source_resolve_enabled', false)) {
            return ['resolved' => 0, 'reopened' => 0];
        }
        $query = Notification::query()
            ->whereIn('Type', self::SOURCE_RESOLVED_TYPES)
            ->where(fn ($q) => $q->whereNull('ResolvedAt')
                ->orWhere('Type', '!=', 'lr_feedback')
                ->orWhere('ResolvedAt', '>=', now()->subDays(self::FEEDBACK_REOPEN_LOOKBACK_DAYS)));
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }
        $rows = $query->get();

        $resolved = 0;
        $reopened = 0;
        foreach (self::SOURCE_RESOLVED_TYPES as $type) {
            $ofType = $rows->where('Type', $type)->values();
            if ($ofType->isEmpty()) {
                continue;
            }
            $stillOpen = $type === 'deduction_failed'
                ? self::deductionFailedStillOpen($ofType)
                : self::feedbackStillOpen($ofType);
            foreach ($ofType as $notification) {
                if (!array_key_exists((int) $notification->id, $stillOpen)) {
                    continue;
                }
                $open = $stillOpen[(int) $notification->id];
                if (!$open && $notification->ResolvedAt === null) {
                    $notification->ResolvedAt = now();
                    $notification->save();
                    $resolved++;
                } elseif ($open && $notification->ResolvedAt !== null) {
                    // Same as tuition: a resolved -> open transition must show unread again.
                    $notification->ResolvedAt = null;
                    $notification->save();
                    NotificationRead::query()->where('NotificationID', $notification->id)->delete();
                    $reopened++;
                }
            }
        }

        return ['resolved' => $resolved, 'reopened' => $reopened];
    }

    /**
     * Attendance recorded but not deducted: handled once that sign-in is deducted, voided, deleted or
     * corrected to leave (never deducts), or the session now has a net deduction in the ledger (e.g. a manual 補扣).
     *
     * @param  \Illuminate\Support\Collection<int, Notification>  $notifications
     * @return array<int, bool>
     */
    private static function deductionFailedStillOpen($notifications): array
    {
        $signInIds = $notifications->where('SourceType', 'StudentSignIn')
            ->pluck('SourceID')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $signIns = StudentSignIn::query()->whereIn('id', $signInIds ?: [0])
            ->get(['id', 'StudentClassID', 'ClassSessionID', 'Status', 'SessionDeducted', 'VoidedAt'])->keyBy('id');
        $sessionIds = $signIns->pluck('ClassSessionID')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $netDeducted = SessionDeductionLedger::query()->whereIn('class_session_id', $sessionIds ?: [0])
            ->groupBy('student_class_id', 'class_session_id')
            ->selectRaw("student_class_id, class_session_id, SUM(CASE WHEN event_type='deduct' THEN 1 "
                . "WHEN event_type='reverse' THEN -1 ELSE 0 END) as net")
            ->get()
            ->filter(fn ($r) => (int) $r->net > 0)
            ->mapWithKeys(fn ($r) => [(int) $r->student_class_id . ':' . (int) $r->class_session_id => true])
            ->all();

        $out = [];
        foreach ($notifications as $notification) {
            $signInId = (int) $notification->SourceID;
            if ($notification->SourceType !== 'StudentSignIn' || $signInId <= 0) {
                continue;
            }
            $signIn = $signIns->get($signInId);
            $handled = $signIn === null
                || $signIn->VoidedAt !== null
                || (bool) $signIn->SessionDeducted
                || strtolower(trim((string) $signIn->Status)) === 'leave'
                || isset($netDeducted[(int) $signIn->StudentClassID . ':' . (int) $signIn->ClassSessionID]);
            $out[(int) $notification->id] = !$handled;
        }

        return $out;
    }

    /**
     * Parent feedback: handled once the thread is no longer awaiting a staff reply (staff replied,
     * "不需回覆" dismissed, or past the awaiting window) — the same rule as the awaiting badge.
     *
     * @param  \Illuminate\Support\Collection<int, Notification>  $notifications
     * @return array<int, bool>
     */
    private static function feedbackStillOpen($notifications): array
    {
        $feedbackIds = $notifications->where('SourceType', 'LearningRecordFeedback')
            ->pluck('SourceID')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $feedbacks = DB::table('learning_record_feedbacks')->whereIn('id', $feedbackIds ?: [0])
            ->get(['id', 'created_at', 'awaiting_dismissed_at'])->keyBy('id');
        $replies = DB::table('learning_record_feedback_replies')->whereIn('feedback_id', $feedbackIds ?: [0])
            ->get(['id', 'feedback_id', 'author_role', 'created_at'])->groupBy('feedback_id');
        $awaiting = app(ParentFeedbackAwaitingService::class);

        $out = [];
        foreach ($notifications as $notification) {
            $feedbackId = (int) $notification->SourceID;
            if ($notification->SourceType !== 'LearningRecordFeedback' || $feedbackId <= 0) {
                continue;
            }
            $feedback = $feedbacks->get($feedbackId);
            $out[(int) $notification->id] = $feedback !== null
                && $awaiting->isAwaitingStaffReply($feedback, $replies->get($feedbackId, collect()));
        }

        return $out;
    }

    /** System-only: retire tuition notices left in a student's former campus after transfer. */
    public static function retireTransferredTuition(): int
    {
        $open = Notification::query()->where('Type', 'tuition')->whereNull('ResolvedAt')
            ->whereIn('SourceType', ['StudentClass', 'Invoice'])->get(['id', 'CampusID', 'SourceType', 'SourceID']);
        $ids = fn (string $type) => $open->where('SourceType', $type)->pluck('SourceID')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $courseStudent = DB::table('StudentClass')->whereIn('ID', $ids('StudentClass') ?: [0])->pluck('StudentID', 'ID');
        $invoiceStudent = DB::table('Invoice')->whereIn('id', $ids('Invoice') ?: [0])->pluck('StudentID', 'id');
        $studentIds = $courseStudent->values()->merge($invoiceStudent->values())->map(fn ($v) => (int) $v)->unique()->values()->all();
        $campusOf = DB::table('Student')->whereIn('id', $studentIds ?: [0])->pluck('CampusID', 'id');

        $retired = 0;
        foreach ($open as $notification) {
            $owner = $notification->SourceType === 'Invoice' ? $invoiceStudent : $courseStudent;
            $studentId = (int) ($owner[(int) $notification->SourceID] ?? 0);
            $campusId = (int) ($campusOf[$studentId] ?? 0);
            if ($campusId > 0 && $campusId !== (int) $notification->CampusID) {
                $notification->ResolvedAt = now();
                $notification->save();
                $retired++;
            }
        }

        return $retired;
    }

    /**
     * TD-086: one shared cooldown + lock for POST /notifications/sync and unread-count.
     * Concurrent callers run the sync once; the rest skip and just read.
     *
     * @return array|null sync result, or null when skipped (cooldown active / another caller syncing)
     */
    public static function syncThrottled(array $campusIds = [], ?int $branchId = null, bool $force = false): ?array
    {
        if (!config('perfflags.throttle_notification_sync', true)) {
            return self::sync($campusIds, $branchId);
        }

        $cooldown = min(300, max(1, (int) config('perfflags.notification_sync_cooldown_seconds', 300)));
        $key = 'notif_sync_' . ($branchId ?? 'all') . '_' . implode('_', $campusIds);
        $fresh = static fn (): bool => ($last = Cache::get($key)) && (time() - (int) $last) <= $cooldown;

        // A manual refresh (force) ignores the cooldown but still never runs two syncs at once.
        if (!$force && $fresh()) {
            return null;
        }

        try {
            $lock = Cache::lock($key . '_lock', 120);
            $acquired = $force ? $lock->block(30) : $lock->get();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return null;
        } catch (\Throwable $e) {
            // Cache store without lock support: fall back to an unlocked sync instead of failing the request.
            report($e);
            $result = self::sync($campusIds, $branchId);
            Cache::put($key, time(), now()->addMinutes(10));

            return $result;
        }
        if (!$acquired) {
            return null;
        }

        try {
            if (!$force && $fresh()) {
                return null;
            }
            $result = self::sync($campusIds, $branchId);
            Cache::put($key, time(), now()->addMinutes(10));

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Null OccurredAt in a payload means "now() when created or re-opened"; an already-open
     * row keeps its timestamp so an unchanged source stays clean (no UPDATE).
     */
    private static function applyPayload(Notification $existing, array $payload): void
    {
        if (($payload['OccurredAt'] ?? null) === null) {
            if ($existing->ResolvedAt !== null || $existing->OccurredAt === null) {
                $payload['OccurredAt'] = now();
            } else {
                unset($payload['OccurredAt']);
            }
        }
        // The JSON column may store keys in a different order; Eloquent's array-cast dirty check
        // compares decoded arrays strictly (order-sensitive), so an unchanged Payload would still
        // be written. Compare by content (order-insensitive) and skip it when equal.
        if (array_key_exists('Payload', $payload) && is_array($existing->Payload) && $existing->Payload == $payload['Payload']) {
            unset($payload['Payload']);
        }
        $existing->fill($payload);
        $existing->ResolvedAt = null;
    }

    /**
     * A tuition alert that was resolved (re-opened now) or whose director-visible content changed
     * (title, body, severity, deep-link source, owed-amount composition) must show unread again;
     * an unchanged re-sync keeps each director's read state.
     */
    private static function reopenIfOwedChanged(Notification $existing, array $payload): void
    {
        if (($payload['Type'] ?? null) !== 'tuition') {
            return;
        }
        // Every resolved->open transition (any source) must show unread again.
        $changed = $existing->ResolvedAt !== null;
        // Content-change reset only for the per-course stream: invoice-overdue rows embed a day counter that changes daily.
        if (($payload['SourceType'] ?? null) !== 'StudentClass') {
            if ($changed) {
                NotificationRead::query()->where('NotificationID', $existing->id)->delete();
            }

            return;
        }
        foreach (['Title', 'Body', 'Severity', 'SourceType', 'SourceID'] as $f) {
            $changed = $changed || (array_key_exists($f, $payload) && (string) $existing->{$f} !== (string) $payload[$f]);
        }
        // Compare only keys the stored row already has: a legacy/partial payload gaining new keys is not a content change.
        $old = (array) ($existing->Payload ?? []);
        $changed = $changed || (array_intersect_key((array) ($payload['Payload'] ?? []), $old) != $old);
        if ($changed) {
            NotificationRead::query()->where('NotificationID', $existing->id)->delete();
        }
    }

    /**
     * docs/DIRECTOR_PAYMENT_ALERT_RULES.md: a valid settlement_day is required; the earliest open
     * (unpaid/partial) Invoice's DueDate decides the monthly window, else this month's settlement_day.
     */
    private static function monthlyInWindow(int $settlementDay, ?string $openInvoiceDueDate): bool
    {
        // A monthly course without a valid settlement_day (1-31) is not a monthly-alert course at all.
        if ($settlementDay < 1 || $settlementDay > 31) {
            return false;
        }
        $today = now()->startOfDay();
        $due = ($openInvoiceDueDate !== null && $openInvoiceDueDate !== '')
            ? Carbon::parse($openInvoiceDueDate)->startOfDay()
            : $today->copy()->startOfMonth()->addDays(min($settlementDay, $today->daysInMonth) - 1);

        return $today->gt($due) || (int) $today->diffInDays($due) < 5;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, StudentClass> $classes
     * @return \Illuminate\Database\Eloquent\Collection<int, StudentClass>
     */
    public static function withinTuitionReminderWindow(\Illuminate\Database\Eloquent\Collection $classes): \Illuminate\Database\Eloquent\Collection
    {
        $dateModeIds = $classes->filter(fn (StudentClass $c) => ($c->ScheduleMode ?? null) !== 'count')
            ->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $openInvoiceDue = empty($dateModeIds) ? [] : Invoice::query()
            ->whereIn('StudentClassID', $dateModeIds)
            ->whereIn('Status', ['unpaid', 'partial'])
            ->whereNotNull('DueDate')
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, MIN(DueDate) as earliest_due')
            ->pluck('earliest_due', 'StudentClassID')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();
        return $classes->filter(fn (StudentClass $class) => ($class->ScheduleMode ?? null) === 'count'
            || self::monthlyInWindow((int) ($class->settlement_day ?? 0), $openInvoiceDue[(int) $class->ID] ?? null))->values();
    }

    private static function isSourceKeyDuplicate(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $driverCode === 1062
            && stripos($exception->getMessage(), 'notifications_sourcekey_unique') !== false;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildTuitionNotifications(array $campusIds): array
    {
        $query = StudentClass::query()
            ->with(['student', 'subjectRecord', 'coursePackage'])
            ->where('Stop', 0)
            ->where(function ($q) {
                $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
            })
            // Keep tuition notifications focused on unpaid classes only.
            ->where(fn ($q) => $q->effectivelyUnpaid());

        if (!empty($campusIds)) {
            $query->whereHas('student', function ($sub) use ($campusIds) {
                $sub->whereIn('CampusID', $campusIds);
            });
        }

        $rows = [];
        /** @var \Illuminate\Database\Eloquent\Collection<int, StudentClass> $eligible */
        $eligible = $query->get();
        $classes = self::withinTuitionReminderWindow($eligible);
        // F7 S5: the resolver decides who still owes; flag off = legacy predicate only (plus shadow comparison).
        $statuses = [];
        $onResolver = (bool) config('billing.paid_status_outbound_notifications', true);
        if ($onResolver && $classes->isNotEmpty()) {
            try {
                $statuses = app(\App\Services\BillingPayableResolver::class)
                    ->courseStatusesByStudentClassIds($classes->pluck('ID')->map(fn ($id) => (int) $id)->all(), $classes);
            } catch (\Throwable $e) {
                Log::warning('notification_tuition_resolver_failed', ['error' => $e::class]); // fall back to the legacy decision
            }
        }
        foreach ($classes->keyBy(fn (StudentClass $c) => (int) $c->getAttribute('ID')) as $classId => $class) {
            $student = $class->student;
            if (!$student) {
                continue;
            }
            $resolved = $statuses[$classId] ?? null;
            // paid / free: nothing owed. review_required / unknown: keep the legacy decision (it is in the query).
            if ($resolved && in_array($resolved['status'], ['paid', 'free'], true)) {
                continue;
            }

            $campusId = (int) ($student->CampusID ?? 0);
            $remaining = (int) ($class->RemainingSessions ?? 0);
            $isUnpaid = (int) ($class->Paid ?? 0) === 0;
            $charge = (int) ($class->Charge ?? $class->Pay ?? 0);
            $subject = self::displaySubjectForClass($class);

            $partial = ($resolved['source'] ?? null) === 'invoice' && ($resolved['status'] ?? null) === 'partial';
            $label = $partial ? '部分已繳' : '未繳費';
            $title = "{$student->name} {$subject} {$label}";
            // Date-mode courses have no session countdown; the command also reminds those parents.
            $body = ($class->ScheduleMode ?? null) === 'count' ? "剩餘 {$remaining} 堂，狀態：{$label}" : "狀態：{$label}";
            if ($partial) {
                $body .= '，已繳 ' . (int) ($resolved['applied'] ?? 0) . '，尚欠 ' . (int) ($resolved['outstanding'] ?? 0);
            }

            $sourceKey = "tuition:{$campusId}:{$class->ID}";
            $rows[$sourceKey] = [
                'CampusID' => $campusId,
                'Type' => 'tuition',
                'Severity' => ($remaining <= 0 || $isUnpaid) ? 'high' : 'medium',
                'Title' => $title,
                'Body' => $body,
                'SourceType' => 'StudentClass',
                'SourceID' => (string) $class->ID,
                'SourceKey' => $sourceKey,
                'Payload' => [
                    'student_id' => (int) $student->id,
                    'student_name' => (string) $student->name,
                    'class_id' => (int) $class->ID,
                    'student_class_id' => (int) $classId, // NotificationsCenter deep-link key
                    'subject' => $subject,
                    'remaining_sessions' => $remaining,
                    'charge' => $charge,
                    'outstanding' => ($resolved['source'] ?? null) === 'invoice' && in_array($resolved['status'], ['unpaid', 'partial'], true)
                        ? (int) $resolved['outstanding']
                        : ($isUnpaid ? $charge : 0),
                    'paid' => !$isUnpaid,
                ],
                'OccurredAt' => null, // TD-086: null = now() on create/reopen only
                'ResolvedAt' => null,
            ];
        }

        if (!$onResolver) {
            app(\App\Services\Billing\PaidStatusShadow::class)->compare($classes, 'notification_tuition');
        }

        return $rows;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildLowSessionsNotifications(array $campusIds): array
    {
        // 已繳費但剩 1–2 堂：續課／加購提醒。未繳費者已由 buildTuitionNotifications 處理，避免同一課程兩則通知。
        $query = StudentClass::query()
            ->with(['student', 'subjectRecord', 'coursePackage'])
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where(function ($q) {
                $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
            })
            ->where(fn ($q) => $q->effectivelyPaid())
            ->where('RemainingSessions', '<=', 2)
            ->where('RemainingSessions', '>', 0); // 僅提醒 1–2 堂（0 堂不列入低堂數推播）

        if (!empty($campusIds)) {
            $query->whereHas('student', function ($sub) use ($campusIds) {
                $sub->whereIn('CampusID', $campusIds);
            });
        }

        $rows = [];
        foreach ($query->get() as $class) {
            $student = $class->student;
            if (!$student) {
                continue;
            }

            $campusId  = (int) ($student->CampusID ?? 0);
            $remaining = (int) ($class->RemainingSessions ?? 0);
            $subject   = self::displaySubjectForClass($class);
            $severity  = $remaining <= 1 ? 'high' : 'medium';

            $sourceKey = "low_sessions:{$campusId}:{$class->ID}";
            $rows[$sourceKey] = [
                'CampusID'   => $campusId,
                'Type'       => 'low_sessions',
                'Severity'   => $severity,
                'Title'      => "⚠️ {$student->name} {$subject} 剩餘堂數不足",
                'Body'       => "剩餘 {$remaining} 堂，請盡快安排續課或加購堂數。",
                'SourceType' => 'StudentClass',
                'SourceID'   => (string) $class->ID,
                'SourceKey'  => $sourceKey,
                'Payload'    => [
                    'student_id'         => (int) $student->id,
                    'student_name'       => (string) $student->name,
                    'class_id'           => (int) $class->ID,
                    'subject'            => $subject,
                    'remaining_sessions' => $remaining,
                    'paid'               => (bool) $class->Paid,
                ],
                'OccurredAt' => null, // TD-086: null = now() on create/reopen only
                'ResolvedAt' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildInvoiceOverdueNotifications(array $campusIds): array
    {
        $onResolver = (bool) config('billing.paid_status_outbound_notifications', true);
        $query = Invoice::query()->with($onResolver ? ['studentClass', 'payments'] : [])
            ->leftJoin('Student', 'Invoice.StudentID', '=', 'Student.id')
            ->whereNotNull('Invoice.DueDate')
            ->whereIn('Invoice.Status', ['unpaid', 'partial'])
            ->whereDate('Invoice.DueDate', '<', now()->toDateString())
            ->select([
                'Invoice.id',
                'Invoice.StudentID',
                'Invoice.StudentClassID',
                'Invoice.DueDate',
                'Invoice.TotalAmount',
                'Invoice.PaidAmount',
                'Invoice.Status',
                'Invoice.IssueDate',
                'Invoice.billing_period',
                'Student.name as student_name',
                'Student.CampusID as campus_id',
            ]);

        if (!empty($campusIds)) {
            $query->whereIn('Student.CampusID', $campusIds);
        }

        $amounts = app(InvoiceAmountReconciliationService::class);
        $invoices = $query->get();
        if ($onResolver) {
            $monthly = $invoices->filter(fn ($invoice) => (string) ($invoice->studentClass->ScheduleMode ?? '') === 'date');
            if ($monthly->isNotEmpty()) {
                (new \Illuminate\Database\Eloquent\Collection($monthly->all()))->load('items');
            }
            $amounts->preloadSessions($monthly->pluck('StudentClassID')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        }
        $rows = [];
        try {
        foreach ($invoices as $invoice) {
            $campusId = (int) ($invoice->campus_id ?? 0);
            if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
                continue;
            }
            if ($onResolver) {
                $projection = $amounts->resolve($invoice);
                if ((int) $projection['outstanding_amount'] <= 0) {
                    continue;
                }
                $totalAmount = (int) $projection['total_amount'];
                $paidAmount = (int) $projection['applied_amount'];
            } else {
                $totalAmount = (int) $invoice->TotalAmount;
                $paidAmount = (int) $invoice->PaidAmount;
            }

            $dueAt = Carbon::parse($invoice->DueDate);
            // Overdue tiers are calendar-day policy, not elapsed-hour policy.
            // Carbon 3 returns a float for diffInDays(), so normalize both
            // endpoints to the day boundary before preserving the integer tier.
            $overdueDays = max(1, (int) $dueAt->copy()->startOfDay()->diffInDays(now()->startOfDay()));
            $studentName = (string) ($invoice->getAttribute('student_name') ?: '學生');
            $subject = '學費';
            [$tier, $severity, $actionText] = self::resolveOverdueTier($overdueDays);

            $sourceKey = "invoice_overdue:{$campusId}:{$invoice->id}";
            $rows[$sourceKey] = [
                'CampusID' => $campusId,
                'Type' => 'tuition',
                'Severity' => $severity,
                'Title' => "{$studentName} {$subject}{$tier}（逾期 {$overdueDays} 天）",
                'Body' => "到期日 {$dueAt->toDateString()}，應繳 {$totalAmount}，已繳 {$paidAmount}。{$actionText}",
                'SourceType' => 'Invoice',
                'SourceID' => (string) $invoice->id,
                'SourceKey' => $sourceKey,
                'Payload' => [
                    'invoice_id' => (int) $invoice->id,
                    'student_id' => (int) ($invoice->StudentID ?? 0),
                    'student_class_id' => $invoice->StudentClassID ? (int) $invoice->StudentClassID : null,
                    'student_name' => $studentName,
                    'subject' => $subject,
                    'due_date' => $dueAt->toDateString(),
                    'overdue_days' => $overdueDays,
                    'overdue_tier' => $tier,
                    'status' => $onResolver ? ($paidAmount > 0 ? 'partial' : 'unpaid') : (string) $invoice->Status,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                ],
                'OccurredAt' => $dueAt->startOfDay(),
                'ResolvedAt' => null,
            ];
        }

        } finally {
            if ($onResolver) {
                $amounts->forgetPreloadedSessions();
            }
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function resolveOverdueTier(int $overdueDays): array
    {
        if ($overdueDays <= 3) {
            return ['提醒', 'low', '建議於 3 日內完成聯繫與繳費確認。'];
        }
        if ($overdueDays <= 7) {
            return ['催繳', 'medium', '建議今日完成催繳追蹤，避免持續逾期。'];
        }

        return ['急件', 'high', '請優先處理，必要時主動致電家長。'];
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildLearningNotifications(array $campusIds): array
    {
        $query = LearningRecord::active()
            ->with(['studentClass.student'])
            ->whereIn('Status', ['pending', 'changes_requested'])
            ->excludeLeaveSessionPendingReview()
            // Same "session already started" rule as the dashboard/tab (only_started=1).
            ->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [now()->format('Y-m-d H:i:s')])
            ->whereHas('studentClass', function ($sc) {
                $sc->where(function ($w) {
                    $w->where('Stop', 0)->orWhereNull('Stop');
                });
            });

        if (!empty($campusIds)) {
            $query->whereHas('studentClass.student', function ($sub) use ($campusIds) {
                $sub->whereIn('CampusID', $campusIds);
            });
        }

        $rows = [];
        foreach ($query->get() as $record) {
            $studentClass = $record->studentClass;
            $student = $studentClass?->student;
            if (!$studentClass || !$student) {
                continue;
            }

            $campusId = (int) ($student->CampusID ?? 0);
            $subject = (string) ($record->Subject ?: $studentClass->getAttribute('Subject') ?: '課程');
            $sessionDate = $record->SessionDate ?: ($record->created_at ? $record->created_at->toDateString() : null);
            $title = "待審評量：{$student->name}";
            $body = $sessionDate ? "{$subject}（{$sessionDate}）" : $subject;

            $sourceKey = "learning:{$campusId}:{$record->id}";
            $rows[$sourceKey] = [
                'CampusID' => $campusId,
                'Type' => 'learning_review',
                'Severity' => 'medium',
                'Title' => $title,
                'Body' => $body,
                'SourceType' => 'LearningRecord',
                'SourceID' => (string) $record->id,
                'SourceKey' => $sourceKey,
                'Payload' => [
                    'record_id' => (int) $record->id,
                    'student_id' => (int) $student->id,
                    'student_name' => (string) $student->name,
                    'student_class_id' => (int) $studentClass->ID,
                    'subject' => $subject,
                    'status' => (string) $record->Status,
                    'session_date' => $record->SessionDate,
                ],
                'OccurredAt' => $record->created_at ?? now(),
                'ResolvedAt' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildPendingSwipeNotifications(array $campusIds): array
    {
        $query = PendingSwipe::query();
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        $rows = [];
        foreach ($query->get() as $pendingSwipe) {
            $campusId = (int) ($pendingSwipe->CampusID ?? 0);
            $swipeAt = $pendingSwipe->SwipeAt
                ? Carbon::parse($pendingSwipe->SwipeAt)
                : ($pendingSwipe->created_at ? Carbon::parse($pendingSwipe->created_at) : now());
            $title = '未識別刷卡待處理';
            $body = "RFID {$pendingSwipe->RFID}（{$swipeAt->format('Y-m-d H:i')}）";

            $sourceKey = "pending_swipe:{$campusId}:{$pendingSwipe->id}";
            $rows[$sourceKey] = [
                'CampusID' => $campusId,
                'Type' => 'pending_swipe',
                'Severity' => 'high',
                'Title' => $title,
                'Body' => $body,
                'SourceType' => 'PendingSwipe',
                'SourceID' => (string) $pendingSwipe->id,
                'SourceKey' => $sourceKey,
                'Payload' => [
                    'pending_swipe_id' => (int) $pendingSwipe->id,
                    'rfid' => (string) $pendingSwipe->RFID,
                    'campus_id' => $campusId,
                    'student_id' => $pendingSwipe->StudentID ? (int) $pendingSwipe->StudentID : null,
                    'reason' => (string) ($pendingSwipe->Reason ?? ''),
                    'swipe_at' => $pendingSwipe->SwipeAt,
                ],
                'OccurredAt' => $swipeAt,
                'ResolvedAt' => null,
            ];
        }

        return $rows;
    }

    private static function displaySubjectForClass(StudentClass $class): string
    {
        $raw = trim((string) ($class->getAttribute('Subject') ?? ''));
        if ($raw !== '') {
            return $raw;
        }
        $row = $class->relationLoaded('subjectRecord') ? $class->subjectRecord : $class->subjectRecord()->first();
        if ($row) {
            $name = trim((string) ($row->Subject_Name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '課程 #' . (int) $class->ID;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<int>
     */
    private static function resolveCampusScope(array $campusIds, ?int $branchId): array
    {
        if ($branchId && $branchId > 0) {
            return [$branchId];
        }

        return array_values(array_unique(array_map('intval', $campusIds)));
    }
}
