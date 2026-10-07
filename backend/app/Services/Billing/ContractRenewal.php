<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\TransactionDiscountCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Renewal / purchase money rules, moved out of StudentClassController (ARCH2-C3).
 * Slices 1-2: duplicate finders, discount redaction and the renewal preview.
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
     * Renewal preview for purchase_batch / renew_monthly: blockers, billing, schedule and the state hash
     * that renewalConfirm re-checks.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(StudentClass $studentClass, array $data, int $actorId, string $role): array
    {
        $mode = (string) ($data['mode'] ?? '');
        $student = $studentClass->relationLoaded('student') ? $studentClass->student : Student::query()->find($studentClass->StudentID);
        $severity = 'ok';
        $warnings = [];
        $blockers = [];
        $proposedCourse = [];
        $billing = [];
        $discountSnapshot = null;
        $schedule = [
            'created_sessions' => 0,
            'first_session_date' => null,
            'last_session_date' => null,
        ];

        if (strtolower(trim((string) ($studentClass->ClassType ?? ''))) === 'tutoring') {
            $blockers[] = [
                'code' => 'tutoring_no_payment_obligation',
                'message' => '輔導課無須繳費，不能建立收費續報。請先檢查課程資料。',
            ];
        }

        if ((int) ($studentClass->Stop ?? 0) === 1) {
            $warnings[] = [
                'code' => 'source_paused',
                'message' => '來源課程目前為暫停狀態，確認前請確認是否仍要續報。',
            ];
        }

        if ($mode === 'purchase_batch') {
            $purchase = $this->previewPurchaseBatch($studentClass, $data, $actorId, $role);
            $blockers = array_merge($blockers, $purchase['blockers']);
            $proposedCourse = $purchase['proposed_course'];
            $billing = $purchase['billing'];
            $schedule = $purchase['schedule'];
            $discountSnapshot = $billing['discount'];
        } elseif ($mode === 'renew_monthly') {
            if ((string) ($studentClass->ScheduleMode ?? 'count') !== 'date') {
                $blockers[] = [
                    'code' => 'non_monthly_course',
                    'message' => '堂數制課程不可月結續報，請使用新增購買批次。',
                ];
            }

            $currentEnd = ContractSessionSchedule::normalizeDateString($studentClass->EndDate ?? null);
            $newEnd = ContractSessionSchedule::normalizeDateString($data['end_date'] ?? null);
            if (!$newEnd && !empty($data['months'])) {
                $base = $currentEnd ?: Carbon::today()->toDateString();
                $newEnd = Carbon::parse($base)->addMonths((int) $data['months'])->toDateString();
            }
            if (!$newEnd) {
                $blockers[] = [
                    'code' => 'end_date_required',
                    'message' => '請選擇新的月結結束日。',
                ];
            } else {
                if ($currentEnd && $newEnd <= $currentEnd) {
                    $blockers[] = [
                        'code' => 'end_date_not_extended',
                        'message' => '新的結束日必須晚於目前結束日，避免誤把課程縮短。',
                    ];
                }
                if ($newEnd <= Carbon::today()->toDateString()) {
                    $blockers[] = [
                        'code' => 'end_date_in_past',
                        'message' => '新的結束日必須晚於今天。',
                    ];
                }
            }

            $periodReview = app(\App\Services\MonthlyRenewalPeriodService::class)->inspect($studentClass, $newEnd);
            $blockers = array_merge($blockers, $periodReview['blockers']);
            $billingPeriod = $periodReview['billing_period'];
            $dueDate = $periodReview['due_date'];
            $previewRate = (float) ($studentClass->Rate ?? 0);
            $previewRateUnit = strtolower(trim((string) ($studentClass->rate_unit ?? 'session')));
            if (!in_array($previewRateUnit, ['session', 'hour'], true)) {
                $previewRateUnit = 'session';
            }
            $previewSessionCount = max(0, (int) ($studentClass->monthly_sessions ?? 0));
            $previewTotalHours = (int) ($studentClass->TotalHours ?? 0);
            if ($newEnd) {
                $newStart = $currentEnd
                    ? Carbon::parse($currentEnd)->addDay()->toDateString()
                    : Carbon::today()->toDateString();
                $previewSlots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($studentClass);
                $previewDur = max(30, (int) ($studentClass->SessionDuration ?? 120));
                $previewSessions = !empty($previewSlots)
                    ? ContractSessionSchedule::buildSessionsFromWeeklySchedule(
                        (int) $studentClass->getKey(),
                        $newStart,
                        $newEnd,
                        $previewSlots,
                        $previewDur
                    )
                    : [];
                if (!empty($previewSessions)) {
                    $previewSessionCount = count($previewSessions);
                    $previewTotalHours = (int) round(array_reduce($previewSessions, function ($carry, $session) {
                        $start = substr((string) ($session['StartTime'] ?? ''), 0, 5);
                        $end = substr((string) ($session['EndTime'] ?? ''), 0, 5);
                        if ($start === '' || $end === '') {
                            return $carry;
                        }
                        $startM = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
                        $endM = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
                        return $carry + max(0, $endM - $startM);
                    }, 0) / 60);
                }
            }
            $amount = \App\Services\MonthlyRenewalPeriodService::chargeFromRate(
                $previewRate,
                $previewRateUnit,
                $previewSessionCount,
                $previewTotalHours
            );
            if ($amount <= 0) {
                $amount = max(0, (int) ($studentClass->Charge ?? 0));
            }
            $discountSnapshot = app(TransactionDiscountCalculator::class)->calculate(
                max(0, $amount), $data['discount'] ?? null,
                $actorId, $role
            );

            $openExceptions = 0;
            if (Schema::hasColumn('ClassSession', 'IsContractException')) {
                $openExceptions = (int) ClassSession::query()
                    ->where('StudentClassID', $studentClass->getKey())
                    ->where('Status', 'scheduled')
                    ->where('IsContractException', 1)
                    ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
                    ->count();
            }
            if ($openExceptions > 0) {
                $warnings[] = [
                    'code' => 'open_contract_exceptions',
                    'message' => "本期尚有 {$openExceptions} 堂調課／例外排課；新期將依契約固定時段展開，這些單堂調整不會帶過去。若要整期改時段，請先編輯固定排課。",
                    'exception_count' => $openExceptions,
                ];
            }

            $proposedCourse = [
                'schedule_mode' => 'date',
                'start_date' => $periodReview['start_date'],
                'current_end_date' => $currentEnd,
                'end_date' => $newEnd,
                'paid' => 0,
            ];
            $billing = [
                'payment_status_after_confirm' => 'unpaid',
                'amount_basis' => 'planned_estimate',
                'confirmed_amount_due' => 0,
                'amount_due' => $discountSnapshot['final_amount'],
                'discount' => $discountSnapshot,
                'invoice' => [
                    'billing_period' => $billingPeriod,
                    'due_date' => $dueDate,
                    'total_amount' => $discountSnapshot['final_amount'],
                    'will_create' => (bool) $billingPeriod,
                ],
            ];
            $schedule = [
                'created_sessions' => null,
                'first_session_date' => null,
                'last_session_date' => $newEnd,
            ];
        } else {
            $blockers[] = [
                'code' => 'mode_required',
                'message' => '請選擇續報類型。',
            ];
        }

        if (!empty($blockers)) {
            $severity = 'blocked';
        } elseif (!empty($warnings)) {
            $severity = 'warning';
        }

        // The state hash describes the requested transaction and current source
        // state, not a newly generated audit timestamp or transaction identity.
        // Both values are intentionally fresh on each calculation; including
        // either would make an unchanged preview fail confirmation.
        $stateBilling = $billing;
        if (isset($stateBilling['discount'])) {
            unset($stateBilling['discount']['created_at'], $stateBilling['discount']['transaction_id']);
        }

        $stateSource = [
            'source' => [
                'id' => (int) $studentClass->ID,
                'student_id' => (int) $studentClass->StudentID,
                'subject_id' => (int) ($studentClass->SubjectID ?? 0),
                'teacher_id' => (int) ($studentClass->TeacherID ?? 0),
                'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
                'start_date' => ContractSessionSchedule::normalizeDateString($studentClass->StartDate ?? null),
                'end_date' => ContractSessionSchedule::normalizeDateString($studentClass->EndDate ?? null),
                'paid' => (int) ($studentClass->Paid ?? 0),
                'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                'session_count' => (int) ($studentClass->SessionCount ?? 0),
                'charge' => (int) ($studentClass->Charge ?? 0),
                'updated_at' => (string) ($studentClass->updated_at ?? ''),
            ],
            'mode' => $mode,
            'payload' => [
                'sessions' => $data['sessions'] ?? null,
                'start_date' => ContractSessionSchedule::normalizeDateString($data['start_date'] ?? null),
                'end_date' => ContractSessionSchedule::normalizeDateString($data['end_date'] ?? null),
                'months' => $data['months'] ?? null,
                // Hash the server-normalized discount snapshot so equivalent
                // inputs such as 12.5 and 12.50 confirm the same preview.
                'discount' => $stateBilling['discount'] ?? null,
            ],
            'billing' => $stateBilling,
            'schedule' => $schedule,
            'blockers' => $blockers,
        ];
        $stateJson = json_encode($stateSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stateHash = hash('sha256', $stateJson ?: '');

        return [
            'preview_id' => substr(hash('sha256', 'renewal-preview|' . $stateHash), 0, 24),
            'state_hash' => $stateHash,
            'mode' => $mode,
            'severity' => $severity,
            'source_course' => [
                'id' => (int) $studentClass->ID,
                'student_id' => (int) $studentClass->StudentID,
                'student_name' => data_get($student, 'name'),
                'subject_id' => (int) ($studentClass->SubjectID ?? 0),
                'teacher_id' => (int) ($studentClass->TeacherID ?? 0),
                'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
                'start_date' => ContractSessionSchedule::normalizeDateString($studentClass->StartDate ?? null),
                'end_date' => ContractSessionSchedule::normalizeDateString($studentClass->EndDate ?? null),
                'paid' => (int) ($studentClass->Paid ?? 0),
                'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
            ],
            'proposed_course' => $proposedCourse,
            'billing' => $billing,
            'schedule' => $schedule,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
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
