<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\ClassSessionMaterializationService;
use App\Services\MonthlyRenewalPeriodService;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\SessionDeductionService;
use App\Services\TransactionDiscountCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

    // Course lifecycle helpers shared with StudentClassController (togglePause, store, split, trial conversion).

    public function createCourseRecord(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return $this->saveNewCourse($payload);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!str_contains($e->getMessage(), 'Unknown column')) {
                    throw $e;
                }
                if (!preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m)) {
                    throw $e;
                }
                $badColumn = $m[1];
                if (!$badColumn || !array_key_exists($badColumn, $payload)) {
                    throw $e;
                }
                unset($payload[$badColumn]);
                $attempts++;
            }
        }

        return $this->saveNewCourse($payload);
    }

    public function courseNeedsPaymentReconciliation(StudentClass $studentClass): bool
    {
        // F7 S6 (B8): the resolver decides. Fail closed (resolver failure = needs reconciliation).
        try {
            $id = (int) $studentClass->getAttribute('ID');
            $row = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id], [$studentClass])[$id] ?? null;
        } catch (\Throwable) {
            return true;
        }
        $status = $row['status'] ?? null;
        if ($status === null) {
            return true;
        }
        if ($status === 'free') {
            return false;
        }
        if (($row['source'] ?? '') !== 'invoice') {
            // No non-void invoice: the legacy Paid flag / paid package decides (also for an unattributed month).
            return $status === 'review_required'
                ? (int) ($studentClass->getAttribute('Charge') ?? 0) > 0 && !$studentClass->isEffectivelyPaid()
                : $status !== 'paid';
        }
        // Invoiced: any unattributed period, or any open invoice not covered by legacy stored PaidAmount
        // (an invoice with PaidAmount but no Payment rows counts as received, as before S6), is open debt.
        $periods = collect($row['periods'] ?? []);
        if ($periods->contains(fn ($period) => ($period['status'] ?? '') === 'review_required')) {
            return true;
        }
        $openIds = $periods->flatMap(fn ($period) => $period['open_invoice_ids'] ?? [])->all();
        if ($openIds === []) {
            return false;
        }
        $legacyCovered = Invoice::query()->with('payments')->whereIn('id', $openIds)->get()
            ->filter(fn (Invoice $invoice) => $invoice->getRelationValue('payments')->isEmpty()
                && (int) $invoice->getAttribute('PaidAmount') >= (int) $invoice->getAttribute('TotalAmount'))
            ->count();

        return count($openIds) > $legacyCovered;
    }

    public function cancelFutureScheduledSessions(StudentClass $studentClass, ?string $reason): int
    {
        $today = Carbon::today()->toDateString();
        $noteTag = match ($reason) {
            'settled' => '[結案取消]',
            'trial_conversion' => '[試聽轉正式]',
            default => '[暫停取消]',
        };

        return ClassSession::query()->where('StudentClassID', $studentClass->getAttribute('ID'))
            ->where('SessionDate', '>=', $today)
            ->where('Status', 'scheduled')
            ->update([
                'Status' => 'cancelled',
                'Note' => DB::raw("CONCAT(COALESCE(Note,''), ' {$noteTag}')"),
                'updated_at' => now(),
            ]);
    }

    /**
     * purchase_batch money transaction: lock the source, create the separate unpaid batch course and its sessions.
     * Returns the HTTP status and JSON body for the controller to send.
     *
     * @param array<string, mixed>|null $discountInput
     * @return array{status: int, body: array<string, mixed>}
     */
    public function purchaseBatch(StudentClass $studentClass, int $sessions, string $startDate, string $mode, string $newClassType, ?array $discountInput, int $actorId, string $actorRole): array
    {
        return DB::transaction(function () use ($studentClass, $sessions, $startDate, $mode, $newClassType, $discountInput, $actorId, $actorRole) {
            $studentClass = $this->lockCourse((int) $studentClass->ID);

            $duplicate = $this->findDuplicatePurchaseBatch($studentClass, $startDate, $sessions);
            if ($duplicate !== null) {
                return $this->reply([
                    'message' => '偵測到相同學生、科目、開課日與堂數的既有批次，請先確認是否已續報過。',
                    'errors' => [
                        'duplicate' => ['已存在相同條件的續報批次。'],
                    ],
                    'duplicate_course' => [
                        'id' => (int) $duplicate->ID,
                        'start_date' => ContractSessionSchedule::normalizeDateString($duplicate->StartDate),
                        'end_date' => ContractSessionSchedule::normalizeDateString($duplicate->EndDate),
                        'session_count' => (int) ($duplicate->SessionCount ?? 0),
                        'remaining_sessions' => (int) ($duplicate->RemainingSessions ?? 0),
                        'paid' => (int) ($duplicate->Paid ?? 0),
                    ],
                ], 409);
            }

            $rate = (float) ($studentClass->Rate ?? 0);
            $rateUnit = (string) ($studentClass->rate_unit ?? 'session');
            $globalDur = (int) ($studentClass->SessionDuration ?? 120);

            $totalHours = 0;
            $charge = 0;
            if ($rateUnit === 'hour') {
                $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($studentClass);
                $durSum = 0;
                $slotCount = max(1, count($slots));
                foreach ($slots as $slot) {
                    $durSum += !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $globalDur;
                }
                $avgDur = $durSum / $slotCount;
                $totalHours = (int) round(($sessions * $avgDur) / 60);
                $charge = (int) round($rate * $totalHours);
            } else {
                $totalHours = (int) ($studentClass->SessionDuration ? round(($sessions * $globalDur) / 60) : ($studentClass->TotalHours ?? 0));
                $charge = (int) round($rate * $sessions);
            }

            $discountSnapshot = app(TransactionDiscountCalculator::class)->calculate(
                max(0, $charge), $discountInput, $actorId, $actorRole
            );
            $newPayload = [
                'StudentID' => (int) $studentClass->StudentID,
                'GradeID' => (int) ($studentClass->GradeID ?? 1),
                'SubjectID' => (int) ($studentClass->SubjectID ?? 1),
                'TeacherID' => (int) ($studentClass->TeacherID ?? 0),
                'by1' => (int) ($studentClass->by1 ?? 1),
                'Period' => (int) ($studentClass->Period ?? 4),
                'StartDate' => $startDate,
                'EndDate' => null,
                'week' => $studentClass->week,
                'time' => $studentClass->time,
                'week1' => $studentClass->week1,
                'time1' => $studentClass->time1,
                'week2' => $studentClass->week2,
                'time2' => $studentClass->time2,
                'week3' => $studentClass->week3,
                'time3' => $studentClass->time3,
                'week4' => $studentClass->week4,
                'time4' => $studentClass->time4,
                'week5' => $studentClass->week5,
                'time5' => $studentClass->time5,
                'week6' => $studentClass->week6,
                'time6' => $studentClass->time6,
                'duration1' => $studentClass->duration1,
                'duration2' => $studentClass->duration2,
                'duration3' => $studentClass->duration3,
                'duration4' => $studentClass->duration4,
                'duration5' => $studentClass->duration5,
                'duration6' => $studentClass->duration6,
                'TotalHours' => $totalHours,
                'Memo' => $studentClass->Memo,
                'Charge' => $discountSnapshot['final_amount'],
                'Pay' => 0,
                'PayDate' => null,
                'Paid' => 0,
                'Disconunt' => null,
                'Rate' => $rate,
                'rate_unit' => $rateUnit,
                'LearnTimeID' => $studentClass->LearnTimeID,
                'room_id' => $studentClass->room_id,
                'settlement_day' => $studentClass->settlement_day,
                'monthly_sessions' => $studentClass->monthly_sessions,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => $sessions,
                'SessionDuration' => $globalDur,
                'RemainingSessions' => $sessions,
                'ClassType' => $newClassType,
                'UsedSessions' => 0,
                'pricing_snapshot' => $discountSnapshot,
            ];

            $newCourse = $this->createCourseRecord($newPayload);
            $newCourse->initializePricingSnapshot($discountSnapshot);

            // ── Build ClassSession rows for the new course ──
            $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($newCourse);
            if (empty($slots)) {
                $isoDow = (int) Carbon::parse($startDate)->dayOfWeekIso;
                $fallbackTime = ContractSessionSchedule::normalizeSessionTime($newCourse->time ?? null, '16:00');
                $slots = [['weekday' => $isoDow, 'time' => substr($fallbackTime, 0, 5)]];
                if ($globalDur >= 30) {
                    $slots[0]['duration_minutes'] = $globalDur;
                }
            }
            $builtSessions = ContractSessionSchedule::buildSessionsForCount(
                (int) $newCourse->ID, $startDate, $sessions, $slots, $globalDur
            );

            $createdSessions = 0;
            $lastSessionDate = null;
            foreach ($builtSessions as $sess) {
                $upsert = app(ClassSessionMaterializationService::class)->upsertSlot($sess);
                if ($upsert['created']) {
                    $createdSessions++;
                }
                $d = $sess['SessionDate'] ?? null;
                if ($d !== null && ($lastSessionDate === null || $d > $lastSessionDate)) {
                    $lastSessionDate = $d;
                }
            }

            if ($lastSessionDate) {
                $newCourse->EndDate = $lastSessionDate;
                $newCourse->save();
            }

            $firstSessionDate = null;
            if (!empty($builtSessions)) {
                $firstSessionDate = $builtSessions[0]['SessionDate'] ?? null;
            }

            SessionDeductionService::syncCounters($newCourse);
            $newCourse->refresh();

            $sourceClosed = false;
            if (
                (string) ($studentClass->ScheduleMode ?? '') === 'count'
                && (int) ($studentClass->Paid ?? 0) === 1
                && (int) ($studentClass->RemainingSessions ?? 0) <= 0
            ) {
                $studentClass->setAttribute('Stop', 1);
                $studentClass->closed_reason = 'settled';
                $studentClass->EndDate = Carbon::today()->toDateString();
                $studentClass->save();
                $sourceClosed = true;
            }

            return $this->reply([
                'message' => $sourceClosed
                    ? '已新增購買批次，舊批次已自動結案'
                    : '已新增購買批次',
                'mode' => $mode,
                'source_closed' => $sourceClosed,
                'created_sessions' => $createdSessions,
                'source_course' => [
                    'id' => (int) $studentClass->ID,
                    'session_count' => (int) ($studentClass->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($studentClass->RemainingSessions ?? 0),
                    'paid' => (int) ($studentClass->Paid ?? 0),
                    'stop' => (int) ($studentClass->Stop ?? 0),
                    'closed_reason' => $studentClass->closed_reason,
                    'end_date' => ContractSessionSchedule::normalizeDateString($studentClass->EndDate),
                ],
                'new_course' => [
                    'id' => (int) $newCourse->ID,
                    'session_count' => (int) ($newCourse->SessionCount ?? 0),
                    'remaining_sessions' => (int) ($newCourse->RemainingSessions ?? 0),
                    'created_sessions' => $createdSessions,
                    'paid' => (int) ($newCourse->Paid ?? 0),
                    'start_date' => ContractSessionSchedule::normalizeDateString($newCourse->StartDate),
                    'end_date' => ContractSessionSchedule::normalizeDateString($newCourse->EndDate),
                    'first_session_date' => ContractSessionSchedule::normalizeDateString($firstSessionDate),
                    'last_session_date' => ContractSessionSchedule::normalizeDateString($lastSessionDate),
                ],
            ], 201);
        });
    }

    /**
     * Trial -> regular conversion transaction. Every error exit after the source mutation throws
     * HttpResponseException so the transaction rolls back. $detectTeacherConflicts is the controller's
     * shared conflict probe (same arguments as StudentClassController::detectTeacherConflicts).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function convertTrial(StudentClass $studentClass, int $sessions, string $startDate, string $newClassType, int $actorId, string $actorRole, \Closure $detectTeacherConflicts): array
    {
        return DB::transaction(function () use ($studentClass, $sessions, $startDate, $newClassType, $actorId, $actorRole, $detectTeacherConflicts) {
            $source = $this->lockCourse((int) $studentClass->getAttribute('ID'));

            if (strtolower((string) ($source->getAttribute('ClassType') ?? '')) !== 'trial') {
                return $this->reply(['message' => '來源課程已不是試聽課程，請重新整理後再試。'], 409);
            }
            if (!empty($source->getAttribute('trial_converted_to_id'))) {
                return $this->reply([
                    'message' => '這筆試聽已轉為正式課程，請直接開啟既有正式課程。',
                    'code' => 'trial_already_converted',
                    'new_course_id' => (int) $source->getAttribute('trial_converted_to_id'),
                ], 409);
            }

            $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($source);
            if (empty($slots)) {
                $isoDow = (int) Carbon::parse($startDate)->dayOfWeekIso;
                $fallbackTime = ContractSessionSchedule::normalizeSessionTime($source->getAttribute('time') ?? null, '16:00');
                $slots = [['weekday' => $isoDow, 'time' => substr($fallbackTime, 0, 5)]];
            }
            $duration = max(30, (int) ($source->getAttribute('SessionDuration') ?? 120));
            $previewSessions = ContractSessionSchedule::buildSessionsForCount(0, $startDate, $sessions, $slots, $duration);
            if (count($previewSessions) !== $sessions) {
                return $this->reply([
                    'message' => '無法依目前固定時段排出完整正式課程，請先補齊試聽課程的星期與時段。',
                    'code' => 'trial_schedule_incomplete',
                ], 422);
            }

            // Remove the source trial from occupancy before checking the new
            // contract. Every error exit below throws HttpResponseException (a plain
            // return would COMMIT these mutations) so the transaction rolls back.
            $cancelledTrialSessions = $this->cancelFutureScheduledSessions($source, 'trial_conversion');
            $source->setAttribute('Stop', 1);
            $source->setAttribute('closed_reason', 'converted_trial');
            $source->save();

            $studentCampusId = (int) (optional($source->student)->getAttribute('CampusID') ?: 0);
            $conflicts = $detectTeacherConflicts(
                (int) ($source->getAttribute('TeacherID') ?? 0),
                $previewSessions,
                $newClassType,
                $source->getAttribute('room_id') ? (int) $source->getAttribute('room_id') : null,
                $studentCampusId,
                (int) ($source->getAttribute('ID') ?? 0) ?: null,
                (int) ($source->getAttribute('StudentID') ?? 0) ?: null
            );
            if (!empty($conflicts)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => $conflicts[0]['message'] ?? '正式課程的固定時段與其他課程衝堂，試聽紀錄未變更。',
                    'code' => 'trial_conversion_schedule_conflict',
                    'conflicts' => $conflicts,
                    'suggested_actions' => $conflicts[0]['suggested_actions'] ?? [],
                ], 409));
            }

            // Same guard the controller's purchaseBatch applied when convertTrial used to call it through the request.
            if ((string) ($source->ScheduleMode ?? 'count') !== 'count') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => '月結制課程請使用「月結續約」功能延長課程，不支援加購堂數。',
                    'errors'  => ['mode' => ['月結制課程不支援此操作，請使用 renew-monthly 端點。']],
                ], 422));
            }
            $response = $this->purchaseBatch($source, $sessions, $startDate, 'new_purchase', $newClassType, null, $actorId, $actorRole);
            if ($response['status'] >= 400) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json($response['body'], $response['status']));
            }

            $result = $response['body'];
            $newCourseId = (int) ($result['new_course']['id'] ?? 0);
            if ($newCourseId <= 0) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => '正式課程建立失敗，試聽紀錄未完成轉換。'], 500)
                );
            }

            $source->setAttribute('trial_converted_to_id', $newCourseId);
            $source->save();

            return $this->reply([
                'message' => '試聽已保留為歷史紀錄，正式課程已建立；未來試聽排課已取消，不需再轉移堂次。',
                'source_course' => [
                    'id' => (int) $source->getAttribute('ID'),
                    'closed_reason' => $source->getAttribute('closed_reason'),
                    'cancelled_future_sessions' => $cancelledTrialSessions,
                    'preserved_attended_sessions' => (int) ClassSession::query()->where('StudentClassID', $source->getAttribute('ID'))
                        ->whereIn('Status', ['attended', 'completed', 'late', 'absent'])
                        ->count(),
                ],
                'new_course' => $result['new_course'],
                'next_actions' => ['record_payment', 'open_new_course'],
            ], 201);
        });
    }

    /** SELECT ... FOR UPDATE on one course; 404 (ModelNotFound) when missing. */
    private function lockCourse(int $id): StudentClass
    {
        $query = StudentClass::query()->where('ID', $id);
        $query->lockForUpdate();
        $course = $query->firstOrFail();
        if (!$course instanceof StudentClass) {
            throw new \LogicException('StudentClass query returned a foreign model');
        }

        return $course;
    }

    private function saveNewCourse(array $payload): StudentClass
    {
        $course = new StudentClass($payload);
        $course->save();

        return $course;
    }

    /**
     * renew_monthly money transaction: lock the source, create the next period course, close the old one,
     * issue the unpaid invoice. Returns the HTTP status and JSON body for the controller to send.
     *
     * @param array<string, mixed>|null $discountInput
     * @return array{status: int, body: array<string, mixed>}
     */
    public function renewMonthly(StudentClass $studentClass, string $newEndDate, ?array $discountInput, int $actorId, string $actorRole): array
    {
        return DB::transaction(function () use ($studentClass, $newEndDate, $discountInput, $actorId, $actorRole) {
            $studentClass = $this->lockCourse((int) $studentClass->ID);

            $periodReview = app(MonthlyRenewalPeriodService::class)->inspect($studentClass, $newEndDate);
            if ($periodReview['blockers']) return $this->reply(array_merge(['message' => $periodReview['blockers'][0]['message']], $periodReview['blockers'][0]), 422);
            $newStartDate = $periodReview['start_date'];

            if ($newEndDate < $newStartDate) {
                return $this->reply([
                    'message' => '新到期日必須晚於新一期開始日。',
                    'errors' => ['end_date' => ['新到期日不可早於舊課程結束後的下一天。']],
                ], 422);
            }

            $duplicate = $this->findDuplicateMonthlyRenewal($studentClass, $newStartDate, $newEndDate);
            if ($duplicate !== null) {
                return $this->reply([
                    'message' => '偵測到相同學生、科目與期間的月結續報課程，請先確認是否已續報過。',
                    'errors' => ['duplicate' => ['已存在相同期間的月結續報課程。']],
                    'duplicate_course' => [
                        'id' => (int) $duplicate->ID,
                        'start_date' => ContractSessionSchedule::normalizeDateString($duplicate->StartDate),
                        'end_date' => ContractSessionSchedule::normalizeDateString($duplicate->EndDate),
                        'paid' => (int) ($duplicate->Paid ?? 0),
                    ],
                ], 409);
            }

            $preview = app(MonthlyRenewalPeriodService::class)->previewPeriod($studentClass, $newStartDate, $newEndDate);
            $rate = $preview['rate'];
            $rateUnit = $preview['rate_unit'];
            $globalDur = $preview['session_duration'];
            $periodSessionCount = $preview['sessions'];
            $periodTotalHours = $preview['hours'];
            $periodCharge = $preview['charge'];

            $discountSnapshot = app(TransactionDiscountCalculator::class)->calculate(
                max(0, $periodCharge), $discountInput, $actorId, $actorRole
            );
            $newPayload = [
                'StudentID' => (int) $studentClass->StudentID,
                'GradeID' => (int) ($studentClass->GradeID ?? 1),
                'SubjectID' => (int) ($studentClass->SubjectID ?? 1),
                'TeacherID' => (int) ($studentClass->TeacherID ?? 0),
                'by1' => (int) ($studentClass->by1 ?? 1),
                'Period' => (int) ($studentClass->Period ?? 4),
                'StartDate' => $newStartDate,
                'EndDate' => $newEndDate,
                'week' => $studentClass->week,
                'time' => $studentClass->time,
                'week1' => $studentClass->week1,
                'time1' => $studentClass->time1,
                'week2' => $studentClass->week2,
                'time2' => $studentClass->time2,
                'week3' => $studentClass->week3,
                'time3' => $studentClass->time3,
                'week4' => $studentClass->week4,
                'time4' => $studentClass->time4,
                'week5' => $studentClass->week5,
                'time5' => $studentClass->time5,
                'week6' => $studentClass->week6,
                'time6' => $studentClass->time6,
                'duration1' => $studentClass->duration1,
                'duration2' => $studentClass->duration2,
                'duration3' => $studentClass->duration3,
                'duration4' => $studentClass->duration4,
                'duration5' => $studentClass->duration5,
                'duration6' => $studentClass->duration6,
                'TotalHours' => $periodTotalHours,
                'Memo' => $studentClass->Memo,
                'Charge' => $discountSnapshot['final_amount'],
                'Pay' => 0,
                'PayDate' => null,
                'Paid' => 0,
                'Disconunt' => null,
                'Rate' => $rate,
                'rate_unit' => $rateUnit,
                'LearnTimeID' => $studentClass->LearnTimeID,
                'room_id' => $studentClass->room_id,
                'settlement_day' => $studentClass->settlement_day,
                'monthly_sessions' => $studentClass->monthly_sessions,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'date',
                'SessionCount' => $periodSessionCount,
                'SessionDuration' => $globalDur,
                'RemainingSessions' => $periodSessionCount,
                'ClassType' => $studentClass->ClassType ?: 'one_on_one',
                'UsedSessions' => 0,
                'pricing_snapshot' => $discountSnapshot,
            ];

            $newCourse = $this->createCourseRecord($newPayload);
            $newCourse->initializePricingSnapshot($discountSnapshot);
            $newCourse->refresh();

            // Close and cancel the old period before materializing the new
            // period. A legacy source course may contain future rows beyond
            // EndDate; leaving it active during generation makes the new
            // renewal look like a real student overlap.
            $studentClass->setAttribute('Stop', 1);
            // An unpaid old period must stay in the accounting queue, not vanish as settled.
            $studentClass->closed_reason = $this->courseNeedsPaymentReconciliation($studentClass) ? 'settled_pending' : 'settled';
            $studentClass->save();
            $cancelled = $this->cancelFutureScheduledSessions($studentClass, 'settled');
            $studentClass->refresh();

            $sessionSync = app(ContractSessionSchedule::class)->ensureMonthlyFutureScheduledSessions($newCourse);

            $billingPeriod = $periodReview['billing_period'];
            $totalAmount = max(0, (int) ($newCourse->Charge ?? 0));
            $dueDate = $periodReview['due_date'];

            $periodLabel = Carbon::parse($newStartDate)->locale('zh_TW')->isoFormat('YYYY年M月');
            $invoice = app(InvoiceIssuer::class)->issue([
                'StudentID'      => (int) $newCourse->StudentID,
                'StudentClassID' => (int) $newCourse->ID,
                'IssueDate'      => Carbon::today()->toDateString(),
                'DueDate'        => $dueDate,
                'TotalAmount'    => $totalAmount,
                'ScheduleModeAtIssue' => $newCourse->ScheduleMode,
                'billing_period' => $billingPeriod,
            ], [[
                'StudentClassID' => (int) $newCourse->ID,
                'Description' => '月結費用 ' . $periodLabel,
                'Amount'      => $totalAmount,
                'PeriodStart' => $newStartDate,
                'PeriodEnd'   => $newEndDate,
            ]]);

            return $this->reply([
                'message' => '已建立月結新一期課程，舊期已結算',
                'mode' => 'renew_monthly',
                'source_closed' => true,
                'cancelled_source_sessions' => $cancelled,
                'session_sync' => $sessionSync,
                'source_course' => [
                    'id' => (int) $studentClass->ID,
                    'start_date' => ContractSessionSchedule::normalizeDateString($studentClass->StartDate),
                    'end_date' => ContractSessionSchedule::normalizeDateString($studentClass->EndDate),
                    'paid' => (int) ($studentClass->Paid ?? 0),
                    'stop' => (int) ($studentClass->Stop ?? 0),
                    'closed_reason' => $studentClass->closed_reason,
                ],
                'new_course' => [
                    'id' => (int) $newCourse->ID,
                    'start_date' => ContractSessionSchedule::normalizeDateString($newCourse->StartDate),
                    'end_date' => ContractSessionSchedule::normalizeDateString($newCourse->EndDate),
                    'settlement_day' => $newCourse->settlement_day,
                    'monthly_sessions' => $newCourse->monthly_sessions,
                    'schedule_mode' => $newCourse->ScheduleMode,
                    'paid' => (int) ($newCourse->Paid ?? 0),
                ],
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'billing_period' => $invoice->billing_period,
                    'status' => $invoice->Status,
                    'total_amount' => (int) $invoice->TotalAmount,
                    'due_date' => ContractSessionSchedule::normalizeDateString($invoice->DueDate),
                ],
            ], 201);
        });
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function reply(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
