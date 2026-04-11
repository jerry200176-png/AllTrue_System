<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\PendingSwipe;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationSyncService
{
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

        $created = 0;
        $updated = 0;
        $resolved = 0;

        DB::transaction(function () use (
            $managedTypes,
            $scopedCampusIds,
            $activeByKey,
            &$created,
            &$updated,
            &$resolved
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
                    $existing->fill($payload);
                    $existing->ResolvedAt = null;
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    }
                    continue;
                }

                Notification::create($payload);
                $created++;
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

        return [
            'created' => $created,
            'updated' => $updated,
            'resolved' => $resolved,
            'active_count' => count($activeByKey),
        ];
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildTuitionNotifications(array $campusIds): array
    {
        $query = StudentClass::query()
            ->with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            // Keep tuition notifications focused on unpaid classes only.
            ->where('Paid', 0);

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

            $campusId = (int) ($student->CampusID ?? 0);
            $remaining = (int) ($class->RemainingSessions ?? 0);
            $isUnpaid = (int) ($class->Paid ?? 0) === 0;
            $subject = (string) ($class->Subject ?: '課程');

            $title = "{$student->name} {$subject} 未繳費";
            $body = "剩餘 {$remaining} 堂，狀態：未繳費";

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
                    'subject' => $subject,
                    'remaining_sessions' => $remaining,
                    'paid' => !$isUnpaid,
                ],
                'OccurredAt' => now(),
                'ResolvedAt' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int>  $campusIds
     * @return array<string, array<string, mixed>>
     */
    private static function buildLowSessionsNotifications(array $campusIds): array
    {
        $query = StudentClass::query()
            ->with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where('RemainingSessions', '<=', 2)
            ->where('RemainingSessions', '>', 0); // 0 堂已由 Stop=1 處理，此處只提醒 1–2 堂

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
            $subject   = (string) ($class->Subject ?: '課程');
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
                'OccurredAt' => now(),
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
        $query = Invoice::query()
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
                'Student.name as student_name',
                'Student.CampusID as campus_id',
            ]);

        if (!empty($campusIds)) {
            $query->whereIn('Student.CampusID', $campusIds);
        }

        $rows = [];
        foreach ($query->get() as $invoice) {
            $campusId = (int) ($invoice->campus_id ?? 0);
            if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
                continue;
            }

            $dueAt = Carbon::parse($invoice->DueDate);
            $overdueDays = max(1, $dueAt->diffInDays(now()));
            $studentName = (string) ($invoice->student_name ?: '學生');
            $subject = '學費';
            $totalAmount = (int) ($invoice->TotalAmount ?? 0);
            $paidAmount = (int) ($invoice->PaidAmount ?? 0);
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
                    'status' => (string) $invoice->Status,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                ],
                'OccurredAt' => $dueAt->startOfDay(),
                'ResolvedAt' => null,
            ];
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
        $query = LearningRecord::query()
            ->with(['studentClass.student'])
            ->whereIn('Status', ['pending', 'changes_requested'])
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
            $subject = (string) ($record->Subject ?: $studentClass->Subject ?: '課程');
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
