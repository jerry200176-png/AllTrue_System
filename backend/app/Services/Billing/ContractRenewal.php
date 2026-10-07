<?php

namespace App\Services\Billing;

use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\TransactionDiscountCalculator;

/**
 * Renewal / purchase money rules, moved out of StudentClassController (ARCH2-C3).
 * Slice 1: duplicate finders, discount redaction and the purchase_batch preview branch.
 * Methods take actor id / role explicitly so the module never reads the HTTP request.
 */
final class ContractRenewal
{
    public function findDuplicatePurchaseBatch(StudentClass $studentClass, string $startDate, int $sessions): ?StudentClass
    {
        return StudentClass::query()->where('ID', '<>', $studentClass->ID)
            ->where('StudentID', $studentClass->StudentID)
            ->where('SubjectID', $studentClass->SubjectID)
            ->where('ScheduleMode', 'count')
            ->where('StartDate', $startDate)
            ->where('SessionCount', $sessions)
            ->where(function ($q) {
                $q->whereNull('Stop')->orWhere('Stop', 0);
            })
            ->orderBy('ID')
            ->first();
    }

    public function findDuplicateMonthlyRenewal(StudentClass $studentClass, string $startDate, string $endDate): ?StudentClass
    {
        return app(\App\Services\MonthlyRenewalPeriodService::class)->findDuplicate($studentClass, $startDate, $endDate);
    }

    public function redactRenewalDiscount(array $preview, bool $financial): array
    {
        if (!$financial) {
            unset($preview['billing']['discount'], $preview['payload']['discount']);
        }
        return $preview;
    }

    /**
     * purchase_batch branch of the renewal preview.
     *
     * @param array<string, mixed> $data
     * @return array{blockers: list<array<string, mixed>>, proposed_course: array<string, mixed>, billing: array<string, mixed>, schedule: array<string, mixed>}
     */
    public function previewPurchaseBatch(StudentClass $studentClass, array $data, int $actorId, string $role): array
    {
        $blockers = [];
        $schedule = [
            'created_sessions' => 0,
            'first_session_date' => null,
            'last_session_date' => null,
        ];
        if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'count') {
            $blockers[] = [
                'code' => 'monthly_course_purchase_batch',
                'message' => '月結制課程不可加購堂數，請使用月結續報。',
            ];
        }

        $sessions = (int) ($data['sessions'] ?? 0);
        $startDate = ContractSessionSchedule::normalizeDateString($data['start_date'] ?? null);
        if ($sessions < 1) {
            $blockers[] = [
                'code' => 'sessions_required',
                'message' => '請輸入本次新增堂數。',
            ];
        }
        if (!$startDate) {
            $blockers[] = [
                'code' => 'start_date_required',
                'message' => '請選擇新批次開課日。',
            ];
        }

        $rate = (float) ($studentClass->Rate ?? 0);
        $rateUnit = (string) ($studentClass->rate_unit ?? 'session');
        $globalDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
        $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($studentClass);
        $totalHours = 0;
        $charge = 0;
        if ($sessions > 0) {
            if ($rateUnit === 'hour') {
                $durSum = 0;
                $slotCount = max(1, count($slots));
                foreach ($slots as $slot) {
                    $durSum += !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $globalDur;
                }
                $avgDur = $durSum / $slotCount;
                $totalHours = (int) round(($sessions * $avgDur) / 60);
                $charge = (int) round($rate * $totalHours);
            } else {
                $totalHours = (int) round(($sessions * $globalDur) / 60);
                $charge = (int) round($rate * $sessions);
            }
        }

        if ($startDate && $sessions > 0 && !empty($slots)) {
            $sessionsPreview = ContractSessionSchedule::buildSessionsForCount((int) $studentClass->ID, $startDate, $sessions, $slots, $globalDur);
            $schedule['created_sessions'] = count($sessionsPreview);
            $schedule['first_session_date'] = isset($sessionsPreview[0])
                ? ContractSessionSchedule::normalizeDateString($sessionsPreview[0]['SessionDate'])
                : null;
            $lastSession = !empty($sessionsPreview) ? $sessionsPreview[count($sessionsPreview) - 1] : null;
            $schedule['last_session_date'] = $lastSession
                ? ContractSessionSchedule::normalizeDateString($lastSession['SessionDate'])
                : null;
        }

        $duplicate = ($startDate && $sessions > 0)
            ? $this->findDuplicatePurchaseBatch($studentClass, $startDate, $sessions)
            : null;
        if ($duplicate !== null) {
            $blockers[] = [
                'code' => 'possible_duplicate_batch',
                'message' => '系統偵測到相同學生、科目、開課日與堂數的既有批次，請先確認是否已續報過。',
                'duplicate_course_id' => (int) $duplicate->ID,
            ];
        }

        $proposedCourse = [
            'schedule_mode' => 'count',
            'sessions' => $sessions,
            'start_date' => $startDate,
            'end_date' => $schedule['last_session_date'],
            'charge' => ($discountSnapshot = app(TransactionDiscountCalculator::class)->calculate(
                max(0, $charge), $data['discount'] ?? null,
                $actorId, $role
            ))['final_amount'],
            'paid' => 0,
            'total_hours' => $totalHours,
        ];
        $billing = [
            'payment_status_after_confirm' => 'unpaid',
            'amount_due' => $discountSnapshot['final_amount'],
            'discount' => $discountSnapshot,
        ];

        return [
            'blockers' => $blockers,
            'proposed_course' => $proposedCourse,
            'billing' => $billing,
            'schedule' => $schedule,
        ];
    }
}
