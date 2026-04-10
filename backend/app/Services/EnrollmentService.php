<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use App\Services\TeacherScopeService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnrollmentService
{
    private const GRADE_TO_CLASS = [
        'P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5, 'P6' => 6,
        'J1' => 7, 'J2' => 8, 'J3' => 9, 'H1' => 10, 'H2' => 11, 'H3' => 12,
    ];

    public function store(Request $request, array $data): JsonResponse
    {
        $confirmedDates = $this->normalizeDateArray($data['confirmed_dates'] ?? []);
        $futureDates = $this->normalizeDateArray($data['future_dates'] ?? []);
        $totalDates = count($confirmedDates) + count($futureDates);
        if ($totalDates <= 0) {
            return response()->json([
                'message' => '請至少提供 1 筆已上課或未來預排日期',
                'errors' => [
                    'confirmed_dates' => ['請至少選擇 1 個日期'],
                ],
            ], 422);
        }

        $isSessionMode = ($data['payment_type'] ?? 'session') === 'session';
        $plannedSessions = $this->resolvePlannedSessions($data, $totalDates);
        if ($plannedSessions <= 0) {
            return response()->json([
                'message' => $isSessionMode ? '堂數制必須提供購買總堂數' : '月結課程必須提供本月預排堂數',
                'errors' => [
                    $isSessionMode ? 'total_classes' : 'monthly_sessions' => [
                        $isSessionMode ? '堂數制的 total_classes 為必填，且須大於 0。' : '月結課程的 monthly_sessions 為必填，且須大於 0。',
                    ],
                ],
            ], 422);
        }

        if ($plannedSessions !== $totalDates) {
            $field = $isSessionMode ? 'total_classes' : 'monthly_sessions';
            $message = $isSessionMode
                ? 'confirmed_dates + future_dates 總數需與購買總堂數一致'
                : 'confirmed_dates + future_dates 總數需與本月預排堂數一致';

            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], 422);
        }

        $overlapDates = array_values(array_intersect($confirmedDates, $futureDates));
        if (!empty($overlapDates)) {
            return response()->json([
                'message' => 'confirmed_dates 與 future_dates 不可重複',
                'errors' => [
                    'future_dates' => ['future_dates 含有已在 confirmed_dates 的日期'],
                ],
                'overlap_dates' => $overlapDates,
            ], 422);
        }

        if (!$isSessionMode) {
            $settlementDay = (int) ($data['settlement_day'] ?? 0);
            if ($settlementDay < 1 || $settlementDay > 31) {
                return response()->json([
                    'message' => '月結時結算日為必填，且須為 1–31',
                    'errors' => [
                        'settlement_day' => ['月結時結算日為必填，且須為 1–31。'],
                    ],
                ], 422);
            }

            $allDatesForMonthly = array_values(array_merge($confirmedDates, $futureDates));
            if (!empty($allDatesForMonthly)) {
                sort($allDatesForMonthly);
                $anchorYm = substr($allDatesForMonthly[0], 0, 7);
                $crossMonth = array_values(array_filter($allDatesForMonthly, fn ($d) => substr((string) $d, 0, 7) !== $anchorYm));
                if (!empty($crossMonth)) {
                    return response()->json([
                        'message' => '月結課程僅可建立在同一月份，請調整日期',
                        'errors' => [
                            'future_dates' => ['月結課程的日期不可跨月份。'],
                        ],
                    ], 422);
                }
            }
        }

        $dayTimeSlotMap = $this->normalizeDayTimeSlots((array) ($data['day_time_slots'] ?? []));
        if (empty($dayTimeSlotMap) && !empty($data['days_of_week']) && !empty($data['start_time'])) {
            foreach ($this->normalizeWeekdayArray((array) $data['days_of_week']) as $day) {
                $dayTimeSlotMap[$day] = [
                    'start_time' => $this->normalizeTime((string) $data['start_time']),
                    'duration_minutes' => null,
                ];
            }
        }
        $globalDuration = (int) $data['duration_minutes'];
        $startTime = !empty($dayTimeSlotMap)
            ? reset($dayTimeSlotMap)['start_time']
            : $this->normalizeTime((string) ($data['start_time'] ?? '16:00'));
        $endTime = $this->computeEndTime($startTime, $globalDuration);
        $now = Carbon::now();
        $today = $now->toDateString();

        foreach ($confirmedDates as $date) {
            $normalizedDate = Carbon::parse($date)->toDateString();
            if ($normalizedDate > $today) {
                return response()->json([
                    'message' => 'confirmed_dates 僅能填寫今天或過去日期',
                    'errors' => [
                        'confirmed_dates' => ['手動確認日期不可晚於今天'],
                    ],
                ], 422);
            }
            $weekday = (int) Carbon::parse($normalizedDate)->dayOfWeekIso;
            $slotStartTime = $this->slotStartTime($dayTimeSlotMap, $weekday, $startTime);
            $slotDur = $this->slotDuration($dayTimeSlotMap, $weekday, $globalDuration);
            $slotEndTime = $this->computeEndTime($slotStartTime, $slotDur);
            if ($normalizedDate === $today && !$this->sessionEndedByEndTime($normalizedDate, $slotEndTime, $now)) {
                return response()->json([
                    'message' => '今天課程尚未結束，不能標記為已上課',
                    'errors' => [
                        'confirmed_dates' => ['今天課程尚未下課，請先排為未上課或待下課後再勾選已上課'],
                    ],
                ], 422);
            }
        }

        foreach ($futureDates as $date) {
            $normalizedDate = Carbon::parse($date)->toDateString();
            if ($normalizedDate < $today) {
                return response()->json([
                    'message' => 'future_dates 不可早於今天',
                    'errors' => [
                        'future_dates' => ['系統預排日期不可早於今天'],
                    ],
                ], 422);
            }
        }

        if (!empty($futureDates) && !empty($confirmedDates)) {
            $anchorDate = $confirmedDates[count($confirmedDates) - 1];
            foreach ($futureDates as $date) {
                if ($date <= $anchorDate) {
                    return response()->json([
                        'message' => 'future_dates 必須晚於最後手動確認日期',
                        'errors' => [
                            'future_dates' => ['系統預排日期不可早於或等於最後手動確認日期'],
                        ],
                    ], 422);
                }
            }
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $authTeacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);

        if ($role === 'teacher' && (!empty($data['student']) || !empty($data['create_student']))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'teacher' && ($authTeacherId <= 0 || (int) $data['teacher_id'] !== $authTeacherId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentId = !empty($data['student_id']) ? (int) $data['student_id'] : 0;
        $studentCampusId = 0;
        if ($studentId > 0) {
            $studentCampusId = (int) (Student::where('id', $studentId)->value('CampusID') ?? 0);
            if ($studentCampusId <= 0) {
                return response()->json(['message' => 'Student campus not found'], 422);
            }
        }

        $targetCampusId = $studentCampusId > 0
            ? (int) ($data['branch_id'] ?? $studentCampusId)
            : (int) ($data['branch_id'] ?? ($campusIds[0] ?? 0));

        if ($targetCampusId <= 0) {
            return response()->json([
                'message' => 'branch_id 為必填',
                'errors' => [
                    'branch_id' => ['請指定分校。'],
                ],
            ], 422);
        }

        if ($studentCampusId > 0 && $targetCampusId !== $studentCampusId) {
            return response()->json(['message' => 'branch_id 與學生所屬分校不一致'], 422);
        }

        if (!empty($campusIds) && !in_array($targetCampusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $teacherHasCampus = UserCampus::where('UserID', (int) $data['teacher_id'])
            ->where('CampusID', $targetCampusId)
            ->exists();
        if (!$teacherHasCampus) {
            return response()->json([
                'message' => 'Teacher is not assigned to the target campus.',
                'errors' => [
                    'teacher_id' => ['所選老師未綁定此分校。'],
                ],
            ], 422);
        }

        if (!empty($data['room_id'])) {
            $roomCampusId = DB::table('rooms')->where('id', (int) $data['room_id'])->value('campus_id');
            if ($roomCampusId !== null && (int) $roomCampusId !== $targetCampusId) {
                return response()->json(['message' => '所選教室不屬於學生所在分校'], 422);
            }
        }

        $scopeWarnings = [];
        $subjectIdForScope = $this->resolveSubjectId((string) $data['subject']);
        $gradeForScope = null;
        if ($studentId > 0) {
            $gradeForScope = (int) (Student::where('id', $studentId)->value('ClassID') ?? 0);
        } elseif (!empty($data['student']['grade'])) {
            $gradeForScope = (string) $data['student']['grade'];
        }
        $scopeResult = TeacherScopeService::check(
            (int) $data['teacher_id'],
            $subjectIdForScope,
            $gradeForScope ?: null
        );
        if (!empty($scopeResult['warnings'])) {
            $scopeWarnings = $scopeResult['warnings'];
        }

        return DB::transaction(function () use (
            $request,
            $data,
            $confirmedDates,
            $futureDates,
            $plannedSessions,
            $targetCampusId,
            $startTime,
            $isSessionMode,
            $studentId,
            $scopeWarnings,
            $dayTimeSlotMap,
            $globalDuration
        ) {
            $student = $studentId > 0
                ? Student::find($studentId)
                : $this->createStudentInline((array) ($data['student'] ?? []), $targetCampusId);

            if (!$student) {
                return response()->json(['message' => '無法建立學生'], 422);
            }

            $allDates = array_values(array_merge($confirmedDates, $futureDates));
            sort($allDates);

            $subjectId = $this->resolveSubjectId((string) $data['subject']);
            $subjectName = $this->resolveSubjectName($subjectId, (string) $data['subject']);
            $weekdays = !empty($dayTimeSlotMap)
                ? array_keys($dayTimeSlotMap)
                : $this->normalizeWeekdayArray($data['days_of_week'] ?? []);
            $primaryWeekday = !empty($weekdays)
                ? (int) $weekdays[0]
                : (int) Carbon::parse($allDates[0])->dayOfWeekIso;

            $weekFields = [];
            for ($idx = 0; $idx < 6; $idx++) {
                $weekday = $weekdays[$idx] ?? null;
                $weekFields['week' . ($idx + 1)] = $weekday;
                $weekFields['time' . ($idx + 1)] = $weekday
                    ? $this->slotStartTime($dayTimeSlotMap, $weekday, $startTime)
                    : null;
                $weekFields['duration' . ($idx + 1)] = $weekday
                    ? ($dayTimeSlotMap[$weekday]['duration_minutes'] ?? null)
                    : null;
            }

            $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4];
            $sessionCount = $isSessionMode ? $plannedSessions : 0;
            $monthlySessions = !$isSessionMode ? (int) ($data['monthly_sessions'] ?? $plannedSessions) : null;
            $chargeUnits = $isSessionMode ? $sessionCount : max(1, $monthlySessions ?: $plannedSessions);

            $hasPerDayDuration = false;
            foreach ($dayTimeSlotMap as $slot) {
                if (!empty($slot['duration_minutes'])) {
                    $hasPerDayDuration = true;
                    break;
                }
            }
            $rateUnit = (!empty($data['rate_unit']) && $data['rate_unit'] === 'hour')
                ? 'hour'
                : ($hasPerDayDuration ? 'hour' : 'session');

            $totalHours = 0;
            $charge = 0;
            $price = (float) $data['price_per_session'];
            if ($rateUnit === 'hour' && !empty($dayTimeSlotMap)) {
                $allDatesList = array_values(array_merge($confirmedDates, $futureDates));
                foreach ($allDatesList as $d) {
                    $wd = (int) Carbon::parse($d)->dayOfWeekIso;
                    $dur = $this->slotDuration($dayTimeSlotMap, $wd, $globalDuration);
                    $totalHours += $dur / 60.0;
                    $charge += $price * ($dur / 60.0);
                }
                $totalHours = (int) round($totalHours);
                $charge = (int) round($charge);
            } else {
                $totalHours = (int) round(($chargeUnits * $globalDuration) / 60);
                $charge = (int) round($price * $chargeUnits);
            }

            $studentClassPayload = array_merge([
                'StudentID' => (int) $student->id,
                'TeacherID' => (int) $data['teacher_id'],
                'SubjectID' => $subjectId,
                'ClassType' => (string) $data['class_type'],
                'by1' => $by1Map[$data['class_type']] ?? 1,
                'Rate' => $price,
                'rate_unit' => $rateUnit,
                'Charge' => $charge,
                'Pay' => 0,
                'Paid' => 0,
                'Period' => 4,
                'ScheduleMode' => $isSessionMode ? 'count' : 'date',
                'SessionCount' => $sessionCount,
                'RemainingSessions' => $isSessionMode ? $sessionCount : 0,
                'UsedSessions' => 0,
                'settlement_day' => !$isSessionMode ? (int) ($data['settlement_day'] ?? 0) : null,
                'monthly_sessions' => !$isSessionMode ? $monthlySessions : null,
                'SessionDuration' => $globalDuration,
                'TotalHours' => $totalHours,
                'StartDate' => $allDates[0],
                'EndDate' => $allDates[count($allDates) - 1],
                'week' => $primaryWeekday,
                'time' => $startTime,
                'Memo' => $data['memo'] ?? null,
                'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
                'RoomID' => !empty($data['room_id']) ? (string) ((int) $data['room_id']) : '1',
                'GradeID' => $this->resolveStudentGradeId((int) $student->id),
                'Stop' => 0,
                'MDate' => now(),
            ], $weekFields);
            $studentClass = $this->createStudentClassResilient($studentClassPayload);

            $existingSiblings = StudentClass::where('StudentID', (int) $student->id)
                ->where('SubjectID', $subjectId)
                ->where('Stop', 0)
                ->where('ID', '!=', $studentClass->ID)
                ->get(['ID', 'TeacherID', 'ClassType']);
            $dualTeacherWarning = null;
            if ($existingSiblings->isNotEmpty()) {
                $teacherIds = $existingSiblings->pluck('TeacherID')->unique()->values()->all();
                $teacherNames = DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id')->all();
                $others = $existingSiblings->map(function ($s) use ($teacherNames) {
                    return ($teacherNames[(int) $s->TeacherID] ?? '老師#' . $s->TeacherID) . '（課程#' . $s->ID . '）';
                })->implode('、');
                $dualTeacherWarning = "此學生同科目已有其他課程：{$others}。若為雙師排課屬正常情況。";
            }

            $createdConfirmedSessions = 0;
            $createdFutureSessions = 0;
            $createdLearningRecords = 0;
            $approvedLearningRecords = 0;
            $deductedSessions = 0;
            $autoApprovedFromFuture = 0;
            $skippedConfirmedDates = [];
            $skippedFutureDates = [];
            $hasSessionDeductedColumn = Schema::hasColumn('LearningRecord', 'SessionDeducted');
            $authUser = $request->attributes->get('auth_user');
            $approvedByUserId = (int) ($authUser->id ?? 0);
            if ($approvedByUserId <= 0) {
                $approvedByUserId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            }
            $decisionNow = Carbon::now();

            foreach ($confirmedDates as $date) {
                $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
                $slotStartTime = $this->slotStartTime($dayTimeSlotMap, $weekday, $startTime);
                $slotDur = $this->slotDuration($dayTimeSlotMap, $weekday, $globalDuration);
                $slotEndTime = $this->computeEndTime($slotStartTime, $slotDur);
                $existing = ClassSession::query()
                    ->where('StudentClassID', $studentClass->ID)
                    ->whereDate('SessionDate', $date)
                    ->where('StartTime', $slotStartTime)
                    ->first();

                $classSession = $existing;
                if ($existing) {
                    if ((string) $existing->Status !== 'completed') {
                        $existing->Status = 'completed';
                        $existing->save();
                    }
                    $skippedConfirmedDates[] = $date;
                } else {
                    $classSession = ClassSession::create([
                        'StudentClassID' => $studentClass->ID,
                        'SessionDate' => $date,
                        'StartTime' => $slotStartTime,
                        'EndTime' => $slotEndTime,
                        'Status' => 'completed',
                        'Note' => '',
                    ]);
                    $createdConfirmedSessions++;
                }

                $syncResult = $this->syncApprovedLearningRecord(
                    $studentClass,
                    $classSession,
                    (int) $data['teacher_id'],
                    $subjectName,
                    $approvedByUserId > 0 ? $approvedByUserId : null,
                    $hasSessionDeductedColumn
                );
                if ($syncResult['created']) {
                    $createdLearningRecords++;
                }
                if ($syncResult['approved']) {
                    $approvedLearningRecords++;
                }
                if ($syncResult['deducted']) {
                    $deductedSessions++;
                }
            }

            foreach ($futureDates as $date) {
                $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
                $slotStartTime = $this->slotStartTime($dayTimeSlotMap, $weekday, $startTime);
                $slotDur = $this->slotDuration($dayTimeSlotMap, $weekday, $globalDuration);
                $slotEndTime = $this->computeEndTime($slotStartTime, $slotDur);
                $existing = ClassSession::query()
                    ->where('StudentClassID', $studentClass->ID)
                    ->whereDate('SessionDate', $date)
                    ->where('StartTime', $slotStartTime)
                    ->first();
                if ($existing) {
                    $skippedFutureDates[] = $date;
                    continue;
                }

                $isEndedAtCreateTime = $this->sessionEndedByEndTime($date, $slotEndTime, $decisionNow);
                $classSession = ClassSession::create([
                    'StudentClassID' => $studentClass->ID,
                    'SessionDate' => $date,
                    'StartTime' => $slotStartTime,
                    'EndTime' => $slotEndTime,
                    'Status' => $isEndedAtCreateTime ? 'completed' : 'scheduled',
                    'Note' => $isEndedAtCreateTime ? '系統判定補登（新增時已過下課時間）' : '',
                ]);
                if ($isEndedAtCreateTime) {
                    $autoApprovedFromFuture++;
                    $createdConfirmedSessions++;
                    $syncResult = $this->syncApprovedLearningRecord(
                        $studentClass,
                        $classSession,
                        (int) $data['teacher_id'],
                        $subjectName,
                        $approvedByUserId > 0 ? $approvedByUserId : null,
                        $hasSessionDeductedColumn
                    );
                    if ($syncResult['created']) {
                        $createdLearningRecords++;
                    }
                    if ($syncResult['approved']) {
                        $approvedLearningRecords++;
                    }
                    if ($syncResult['deducted']) {
                        $deductedSessions++;
                    }
                } else {
                    $createdFutureSessions++;
                }
            }

            SessionDeductionService::syncCounters($studentClass);
            $studentClass->refresh();

            $createdSessions = $createdConfirmedSessions + $createdFutureSessions;
            $payload = [
                'message' => 'Batch schedule created',
                'student_id' => (int) $student->id,
                'student_class_id' => (int) $studentClass->ID,
                'created_sessions' => $createdSessions,
                'created_confirmed_sessions' => $createdConfirmedSessions,
                'created_future_sessions' => $createdFutureSessions,
                'created_learning_records' => $createdLearningRecords,
                'approved_learning_records' => $approvedLearningRecords,
                'deducted_sessions' => $deductedSessions,
                'auto_backfilled_sessions' => $autoApprovedFromFuture,
                'skipped_confirmed_dates' => $skippedConfirmedDates,
                'skipped_future_dates' => $skippedFutureDates,
                'skipped_dates' => array_values(array_merge($skippedConfirmedDates, $skippedFutureDates)),
            ];
            if ($dualTeacherWarning) {
                $payload['dual_teacher_warning'] = $dualTeacherWarning;
            }
            if (!empty($scopeWarnings)) {
                $payload['scope_warning'] = implode(' ', $scopeWarnings);
            }
            return response()->json($payload, 201);
        });
    }

    private function resolvePlannedSessions(array $data, int $totalDates): int
    {
        $paymentType = (string) ($data['payment_type'] ?? 'session');
        if ($paymentType === 'session') {
            return (int) ($data['total_classes'] ?? 0);
        }

        $monthlySessions = (int) ($data['monthly_sessions'] ?? 0);
        if ($monthlySessions > 0) {
            return $monthlySessions;
        }

        $legacyTotal = (int) ($data['total_classes'] ?? 0);
        if ($legacyTotal > 0) {
            return $legacyTotal;
        }

        return $totalDates;
    }

    private function createStudentInline(array $studentData, int $campusId): Student
    {
        $gradeCode = (string) ($studentData['grade'] ?? 'J1');
        $classId = self::GRADE_TO_CLASS[$gradeCode] ?? 7;

        return Student::create([
            'name' => $studentData['name'],
            'CampusID' => $campusId,
            'ClassID' => $classId,
            'SchoolName' => $studentData['school'] ?? $studentData['SchoolName'] ?? null,
            'Phone' => $studentData['phone'] ?? $studentData['Phone'] ?? null,
            'parent_name' => $studentData['parent_name'] ?? null,
            'parent_phone' => $studentData['parent_phone'] ?? null,
            'notes' => $studentData['notes'] ?? null,
            'status' => $studentData['status'] ?? 'active',
            'RFID' => $studentData['rfid'] ?? null,
            'enable' => 1,
            'MDT' => now(),
            'TelegramID' => '',
        ]);
    }

    private function normalizeDateArray(array $dates): array
    {
        $normalized = [];
        foreach ($dates as $date) {
            try {
                $normalized[] = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                // validation already catches date format
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $days
     * @return array<int, int>
     */
    private function normalizeWeekdayArray(array $days): array
    {
        $normalized = array_values(array_unique(array_map('intval', $days)));
        $normalized = array_values(array_filter($normalized, fn ($d) => $d >= 1 && $d <= 7));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array{start_time: string, duration_minutes: int|null}> weekday => slot info
     */
    private function normalizeDayTimeSlots(array $slots): array
    {
        $result = [];
        foreach ($slots as $slot) {
            $day = (int) ($slot['day'] ?? 0);
            $time = isset($slot['start_time']) ? trim((string) $slot['start_time']) : '';
            if ($day < 1 || $day > 7 || $time === '') {
                continue;
            }
            $durMin = isset($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30
                ? (int) $slot['duration_minutes']
                : null;
            $result[$day] = [
                'start_time' => $this->normalizeTime(substr($time, 0, 5)),
                'duration_minutes' => $durMin,
            ];
        }
        ksort($result);
        return $result;
    }

    private function slotStartTime(array $dayTimeSlotMap, int $weekday, string $fallback): string
    {
        return $dayTimeSlotMap[$weekday]['start_time'] ?? $fallback;
    }

    private function slotDuration(array $dayTimeSlotMap, int $weekday, int $globalDuration): int
    {
        return $dayTimeSlotMap[$weekday]['duration_minutes'] ?? $globalDuration;
    }

    private function normalizeTime(string $time): string
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        return $parsed->format('H:i:s');
    }

    private function computeEndTime(string $startTime, int $durationMinutes): string
    {
        return Carbon::createFromFormat('H:i:s', $startTime)
            ->addMinutes($durationMinutes)
            ->format('H:i:s');
    }

    private function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }

    private function resolveSubjectId(string $frontendSubject): int
    {
        $subjectMap = [
            'Chinese' => '國文',
            'English' => '英文',
            'Math' => '數學',
            'Physics' => '物理',
            'Chemistry' => '化學',
            'Science' => '理化',
            'Biology' => '生物',
            'Social' => '社會',
        ];
        $subjectName = $subjectMap[$frontendSubject] ?? $frontendSubject;

        return (int) (
            DB::table('Subject')->where('Subject_Name', 'like', '%' . $subjectName . '%')->value('id')
            ?? DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', '%' . $subjectName . '%')->value('id')
            ?? 1
        );
    }

    private function resolveSubjectName(int $subjectId, string $fallback): string
    {
        $name = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $subjectId)->value('Val');
        return (string) ($name ?: $fallback);
    }

    /**
     * Retry StudentClass::create by removing unknown columns for mixed schemas.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createStudentClassResilient(array $payload): StudentClass
    {
        $attempts = 0;
        while ($attempts < 8) {
            try {
                return StudentClass::create($payload);
            } catch (QueryException $e) {
                if (!str_contains($e->getMessage(), 'Unknown column')) {
                    throw $e;
                }
                if (!preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m)) {
                    throw $e;
                }
                $badColumn = $m[1] ?? null;
                if (!$badColumn || !array_key_exists($badColumn, $payload)) {
                    throw $e;
                }
                unset($payload[$badColumn]);
                $attempts++;
            }
        }
        return StudentClass::create($payload);
    }

    /**
     * @return array{created: bool, approved: bool, deducted: bool}
     */
    private function syncApprovedLearningRecord(
        StudentClass $studentClass,
        ClassSession $classSession,
        int $teacherId,
        string $subjectName,
        ?int $approvedByUserId,
        bool $hasSessionDeductedColumn
    ): array {
        $created = false;
        $approved = false;
        $deducted = false;

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->first();
        if (!$record) {
            $payload = [
                'StudentClassID' => $studentClass->ID,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'CreatedByUserID' => $approvedByUserId ?: null,
                'Content' => '（系統自動核准）',
                'Subject' => $subjectName,
                'SessionDate' => $classSession->SessionDate,
                'StartTime' => $classSession->StartTime,
                'EndTime' => $classSession->EndTime,
                'Status' => 'approved',
                'ApprovedBy' => $approvedByUserId ?: null,
                'ApprovedAt' => now(),
            ];
            if ($hasSessionDeductedColumn) {
                $payload['SessionDeducted'] = false;
            }
            $record = LearningRecord::create($payload);
            $created = true;
            $approved = true;
        } else {
            if ((string) $record->Status !== 'approved') {
                $approved = true;
            }
            $record->StudentClassID = $studentClass->ID;
            $record->TeacherID = $teacherId;
            $record->Subject = $subjectName;
            $record->SessionDate = $classSession->SessionDate;
            $record->StartTime = $classSession->StartTime;
            $record->EndTime = $classSession->EndTime;
            $record->Status = 'approved';
            if (empty((string) $record->Content)) {
                $record->Content = '（系統自動核准）';
            }
            if ($approvedByUserId) {
                $record->ApprovedBy = $approvedByUserId;
                if (empty($record->CreatedByUserID)) {
                    $record->CreatedByUserID = $approvedByUserId;
                }
            }
            $record->ApprovedAt = now();
            $record->save();
        }

        $alreadyDeducted = $hasSessionDeductedColumn && (bool) $record->SessionDeducted;
        if (!$alreadyDeducted && $this->deductSessionForApprovedRecord($studentClass, $classSession->id)) {
            $deducted = true;
            if ($hasSessionDeductedColumn) {
                $record->SessionDeducted = true;
                $record->save();
            }
        }

        return [
            'created' => $created,
            'approved' => $approved,
            'deducted' => $deducted,
        ];
    }

    private function deductSessionForApprovedRecord(StudentClass $studentClass, int $classSessionId): bool
    {
        return SessionDeductionService::deductOnAttendance(
            $studentClass,
            null,
            $classSessionId > 0 ? $classSessionId : null
        );
    }

    private function resolveStudentGradeId(int $studentId): int
    {
        try {
            $student = Student::find($studentId);
            if (!$student) {
                return 1;
            }
            $gradeId = $student->GradeID ?? null;
            if ($gradeId) {
                return (int) $gradeId;
            }
            return (int) ($student->ClassID ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }
}
