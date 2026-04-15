<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use App\Services\FrontendSubjectIdResolver;
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

        $defaultSubject = trim((string) ($data['subject'] ?? ''));
        if ($defaultSubject === '') {
            $defaultSubject = 'Math';
        }

        $dayTimeSlotGroups = $this->normalizeDayTimeSlotGroups((array) ($data['day_time_slots'] ?? []), $defaultSubject);
        if (empty($dayTimeSlotGroups) && !empty($data['days_of_week']) && !empty($data['start_time'])) {
            foreach ($this->normalizeWeekdayArray((array) $data['days_of_week']) as $day) {
                $dayTimeSlotGroups[$day] = [[
                    'start_time' => $this->normalizeTime((string) $data['start_time']),
                    'duration_minutes' => null,
                    'subject' => $defaultSubject,
                ]];
            }
        }
        $dayTimeSlotMap = $this->collapseSlotGroupsToFirstPerWeekday($dayTimeSlotGroups);
        $globalDuration = (int) $data['duration_minutes'];
        $startTime = !empty($dayTimeSlotMap)
            ? reset($dayTimeSlotMap)['start_time']
            : $this->normalizeTime((string) ($data['start_time'] ?? '16:00'));

        $sessionPlanRaw = $data['session_plan'] ?? null;
        $sessionRows = [];
        if (!empty($sessionPlanRaw) && is_array($sessionPlanRaw)) {
            $sessionRows = $this->buildRowsFromSessionPlan(
                $sessionPlanRaw,
                $dayTimeSlotGroups,
                $dayTimeSlotMap,
                $globalDuration,
                $startTime,
                $defaultSubject
            );
        } else {
            $totalDates = count($confirmedDates) + count($futureDates);
            if ($totalDates <= 0) {
                return response()->json([
                    'message' => '請至少提供 1 筆已上課或未來預排日期',
                    'errors' => [
                        'confirmed_dates' => ['請至少選擇 1 個日期'],
                    ],
                ], 422);
            }
            $sessionRows = $this->buildRowsFromLegacyDateLists(
                $confirmedDates,
                $futureDates,
                $dayTimeSlotMap,
                $dayTimeSlotGroups,
                $globalDuration,
                $startTime,
                $defaultSubject
            );
        }

        if (count($sessionRows) <= 0) {
            return response()->json([
                'message' => '請至少提供 1 筆已上課或未來預排日期',
                'errors' => [
                    'confirmed_dates' => ['請至少選擇 1 個日期'],
                ],
            ], 422);
        }

        $allowedWeekdays = [];
        if (!empty($dayTimeSlotMap)) {
            $allowedWeekdays = array_map('intval', array_keys($dayTimeSlotMap));
        } elseif (!empty($data['days_of_week'])) {
            $allowedWeekdays = $this->normalizeWeekdayArray((array) $data['days_of_week']);
        }
        if (!empty($allowedWeekdays)) {
            $allowedSet = array_fill_keys($allowedWeekdays, true);
            $weekCn = ['', '一', '二', '三', '四', '五', '六', '日'];
            $today = Carbon::today()->toDateString();
            foreach ($sessionRows as $row) {
                // 過去日期為歷史補登，不限固定上課星期
                if (($row['date'] ?? '') < $today) {
                    continue;
                }
                $wd = (int) Carbon::parse($row['date'])->dayOfWeekIso;
                if (!isset($allowedSet[$wd])) {
                    $label = $weekCn[$wd] ?? (string) $wd;

                    return response()->json([
                        'message' => "排課日期 {$row['date']} 為週{$label}，與固定上課星期不符。",
                        'errors' => [
                            'session_plan' => ['堂次日曆日須落在已設定的固定上課星期。'],
                        ],
                    ], 422);
                }
            }
        }

        $isSessionMode = ($data['payment_type'] ?? 'session') === 'session';
        $plannedSessions = $this->resolvePlannedSessions($data, count($sessionRows));
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

        if ($plannedSessions !== count($sessionRows)) {
            $field = $isSessionMode ? 'total_classes' : 'monthly_sessions';
            $message = $isSessionMode
                ? '堂數（session_plan 或日期清單總筆數）需與購買總堂數一致'
                : '堂數（session_plan 或日期清單總筆數）需與本月預排堂數一致';

            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], 422);
        }

        $rowKeys = [];
        foreach ($sessionRows as $row) {
            $rowKeys[] = $row['date'] . '|' . $row['start_time'];
        }
        if (count($rowKeys) !== count(array_unique($rowKeys))) {
            return response()->json([
                'message' => '排課清單含有重複的日期與開始時間',
                'errors' => [
                    'session_plan' => ['同一日、同一開始時間不可重複排課'],
                ],
            ], 422);
        }

        $confirmedDateSet = [];
        $futureDateSet = [];
        foreach ($sessionRows as $row) {
            if ($row['kind'] === 'confirmed') {
                $confirmedDateSet[$row['date']] = true;
            } else {
                $futureDateSet[$row['date']] = true;
            }
        }
        $overlapDates = array_values(array_intersect(array_keys($confirmedDateSet), array_keys($futureDateSet)));
        if (!empty($overlapDates)) {
            return response()->json([
                'message' => '已上課與未來預排不可使用同一日曆日（請拆成不同開始時間或調整種類）',
                'errors' => [
                    'session_plan' => ['同一日期不可同時為已上課與未來預排'],
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

            $allDatesForMonthly = array_column($sessionRows, 'date');
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

        $now = Carbon::now();
        $today = $now->toDateString();

        foreach ($sessionRows as $row) {
            if ($row['kind'] !== 'confirmed') {
                continue;
            }
            $normalizedDate = $row['date'];
            if ($normalizedDate > $today) {
                return response()->json([
                    'message' => '已上課堂次僅能為今天或過去日期',
                    'errors' => [
                        'session_plan' => ['已上課日期不可晚於今天'],
                    ],
                ], 422);
            }
            $slotEndTime = $this->computeEndTime($row['start_time'], $row['duration_minutes']);
            if ($normalizedDate === $today && !$this->sessionEndedByEndTime($normalizedDate, $slotEndTime, $now)) {
                return response()->json([
                    'message' => '今天課程尚未結束，不能標記為已上課',
                    'errors' => [
                        'session_plan' => ['今天該時段尚未下課，請先排為未上課或待下課後再勾選已上課'],
                    ],
                ], 422);
            }
        }

        foreach ($sessionRows as $row) {
            if ($row['kind'] !== 'future') {
                continue;
            }
            $normalizedDate = $row['date'];
            if ($normalizedDate < $today) {
                return response()->json([
                    'message' => '未來預排不可早於今天',
                    'errors' => [
                        'session_plan' => ['系統預排日期不可早於今天'],
                    ],
                ], 422);
            }
        }

        $confirmedDatesSorted = array_keys($confirmedDateSet);
        sort($confirmedDatesSorted);
        $futureDatesSorted = array_keys($futureDateSet);
        sort($futureDatesSorted);
        if (!empty($futureDatesSorted) && !empty($confirmedDatesSorted)) {
            $anchorDate = $confirmedDatesSorted[count($confirmedDatesSorted) - 1];
            foreach ($futureDatesSorted as $date) {
                if ($date <= $anchorDate) {
                    return response()->json([
                        'message' => '未來預排必須晚於最後一個已上課日',
                        'errors' => [
                            'session_plan' => ['系統預排日期不可早於或等於最後已上課日'],
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

        $subjectGroups = $this->groupSessionRowsBySubject($sessionRows);
        $subjectMeta = [];
        foreach (array_keys($subjectGroups) as $subKey) {
            $rid = FrontendSubjectIdResolver::resolve($subKey);
            if ($rid === null) {
                return response()->json([
                    'message' => "無法將科目「{$subKey}」對應到 Subject 主檔，請檢查科目設定。",
                    'errors' => ['subject' => ["找不到對應的科目 ID：{$subKey}"]],
                ], 422);
            }
            $subjectMeta[$subKey] = [
                'id' => $rid,
                'name' => FrontendSubjectIdResolver::resolveName($rid, $subKey),
            ];
        }

        $scopeWarnings = [];
        $gradeForScope = null;
        if ($studentId > 0) {
            $gradeForScope = (int) (Student::where('id', $studentId)->value('ClassID') ?? 0);
        } elseif (!empty($data['student']['grade'])) {
            $gradeForScope = (string) $data['student']['grade'];
        }
        foreach (array_keys($subjectGroups) as $subKey) {
            $scopeResult = TeacherScopeService::check(
                (int) $data['teacher_id'],
                (int) $subjectMeta[$subKey]['id'],
                $gradeForScope ?: null
            );
            if (!empty($scopeResult['warnings'])) {
                $scopeWarnings = array_merge($scopeWarnings, $scopeResult['warnings']);
            }
        }
        $scopeWarnings = array_values(array_unique($scopeWarnings));

        return DB::transaction(function () use (
            $request,
            $data,
            $targetCampusId,
            $startTime,
            $isSessionMode,
            $studentId,
            $scopeWarnings,
            $globalDuration,
            $dayTimeSlotGroups,
            $subjectGroups,
            $subjectMeta
        ) {
            $student = $studentId > 0
                ? Student::find($studentId)
                : $this->createStudentInline((array) ($data['student'] ?? []), $targetCampusId);

            if (!$student) {
                return response()->json(['message' => '無法建立學生'], 422);
            }

            $by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4, 'trial' => 1];
            $rateUnit = (!empty($data['rate_unit']) && $data['rate_unit'] === 'hour')
                ? 'hour'
                : 'session';
            $price = (float) $data['price_per_session'];

            $hasSessionDeductedColumn = Schema::hasColumn('LearningRecord', 'SessionDeducted');
            $authUser = $request->attributes->get('auth_user');
            $approvedByUserId = (int) ($authUser->id ?? 0);
            if ($approvedByUserId <= 0) {
                $approvedByUserId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
            }
            $decisionNow = Carbon::now();

            $createdConfirmedSessions = 0;
            $createdFutureSessions = 0;
            $createdLearningRecords = 0;
            $approvedLearningRecords = 0;
            $deductedSessions = 0;
            $autoApprovedFromFuture = 0;
            $skippedConfirmedDates = [];
            $skippedFutureDates = [];
            $dualTeacherWarnings = [];
            $studentClassIds = [];
            $firstStudentClassId = null;

            foreach ($subjectGroups as $subjectKey => $rowsForSubject) {
                $meta = $subjectMeta[$subjectKey];
                $subjectId = (int) $meta['id'];
                $subjectName = (string) $meta['name'];

                $filteredSlotGroups = $this->filterSlotGroupsBySubject($dayTimeSlotGroups, $subjectKey);
                if (empty($filteredSlotGroups)) {
                    $filteredSlotGroups = $this->slotGroupsFromSessionRows($rowsForSubject);
                }
                $dayTimeSlotMapForGroup = $this->collapseSlotGroupsToFirstPerWeekday($filteredSlotGroups);

                $groupGlobalDur = 0;
                foreach ($rowsForSubject as $rr) {
                    $groupGlobalDur = max($groupGlobalDur, (int) ($rr['duration_minutes'] ?? 0));
                }
                if ($groupGlobalDur <= 0) {
                    $groupGlobalDur = $globalDuration;
                }

                $startTimeForGroup = !empty($dayTimeSlotMapForGroup)
                    ? reset($dayTimeSlotMapForGroup)['start_time']
                    : $startTime;

                $allDates = array_values(array_unique(array_column($rowsForSubject, 'date')));
                sort($allDates);

                if (!empty($dayTimeSlotMapForGroup)) {
                    $weekdays = array_keys($dayTimeSlotMapForGroup);
                    sort($weekdays);
                } elseif (!empty($data['days_of_week'])) {
                    $weekdays = $this->normalizeWeekdayArray($data['days_of_week']);
                } else {
                    $weekdays = [];
                    $seenWeekday = [];
                    foreach ($rowsForSubject as $r) {
                        $wd = (int) Carbon::parse($r['date'])->dayOfWeekIso;
                        if (!isset($seenWeekday[$wd])) {
                            $seenWeekday[$wd] = true;
                            $weekdays[] = $wd;
                        }
                    }
                }
                $primaryWeekday = !empty($weekdays)
                    ? (int) $weekdays[0]
                    : (int) Carbon::parse($allDates[0])->dayOfWeekIso;

                $weekFields = [];
                for ($idx = 0; $idx < 6; $idx++) {
                    $weekday = $weekdays[$idx] ?? null;
                    $weekFields['week' . ($idx + 1)] = $weekday;
                    $weekFields['time' . ($idx + 1)] = $weekday
                        ? $this->slotStartTime($dayTimeSlotMapForGroup, $weekday, $startTimeForGroup)
                        : null;
                    $weekFields['duration' . ($idx + 1)] = $weekday
                        ? ($dayTimeSlotMapForGroup[$weekday]['duration_minutes'] ?? null)
                        : null;
                }

                $groupSessionCount = count($rowsForSubject);
                $sessionCount = $isSessionMode ? $groupSessionCount : 0;
                $monthlySessions = !$isSessionMode ? $groupSessionCount : null;
                $chargeUnits = $isSessionMode ? $sessionCount : max(1, $monthlySessions ?: $groupSessionCount);

                $totalHours = 0;
                $charge = 0;
                if ($rateUnit === 'hour') {
                    foreach ($rowsForSubject as $row) {
                        $dur = (int) $row['duration_minutes'];
                        $totalHours += $dur / 60.0;
                        $charge += $price * ($dur / 60.0);
                    }
                    $totalHours = (int) round($totalHours);
                    $charge = (int) round($charge);
                } else {
                    $sumMinutes = 0;
                    foreach ($rowsForSubject as $row) {
                        $sumMinutes += (int) $row['duration_minutes'];
                    }
                    $totalHours = (int) round($sumMinutes / 60);
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
                    'SessionDuration' => $groupGlobalDur,
                    'TotalHours' => $totalHours,
                    'StartDate' => $allDates[0],
                    'EndDate' => $allDates[count($allDates) - 1],
                    'week' => $primaryWeekday,
                    'time' => $startTimeForGroup,
                    'Memo' => $data['memo'] ?? null,
                    'PayDate' => !empty($data['paid_at']) ? $data['paid_at'] : null,
                    'Paid' => !empty($data['paid_at']) ? 1 : 0,
                    'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
                    'RoomID' => !empty($data['room_id']) ? (string) ((int) $data['room_id']) : '1',
                    'GradeID' => $this->resolveStudentGradeId((int) $student->id),
                    'Stop' => 0,
                    'MDate' => now(),
                ], $weekFields);
                $studentClass = $this->createStudentClassResilient($studentClassPayload);

                if ($firstStudentClassId === null) {
                    $firstStudentClassId = (int) $studentClass->ID;
                }
                $studentClassIds[] = (int) $studentClass->ID;

                $existingSiblings = StudentClass::where('StudentID', (int) $student->id)
                    ->where('SubjectID', $subjectId)
                    ->where('Stop', 0)
                    ->where('ID', '!=', $studentClass->ID)
                    ->get(['ID', 'TeacherID', 'ClassType']);
                if ($existingSiblings->isNotEmpty()) {
                    $teacherIds = $existingSiblings->pluck('TeacherID')->unique()->values()->all();
                    $teacherNames = DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id')->all();
                    $others = $existingSiblings->map(function ($s) use ($teacherNames) {
                        return ($teacherNames[(int) $s->TeacherID] ?? '老師#' . $s->TeacherID) . '（課程#' . $s->ID . '）';
                    })->implode('、');
                    $dualTeacherWarnings[] = "「{$subjectKey}」：此學生同科目已有其他課程：{$others}。若為雙師排課屬正常情況。";
                }

                foreach ($rowsForSubject as $row) {
                    $date = $row['date'];
                    $slotStartTime = $row['start_time'];
                    $slotDur = (int) $row['duration_minutes'];
                    $slotEndTime = $this->computeEndTime($slotStartTime, $slotDur);

                    if ($row['kind'] === 'confirmed') {
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
                        continue;
                    }

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
            }

            $createdSessions = $createdConfirmedSessions + $createdFutureSessions;
            $payload = [
                'message' => 'Batch schedule created',
                'student_id' => (int) $student->id,
                'student_class_id' => $firstStudentClassId,
                'student_class_ids' => $studentClassIds,
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
            if (!empty($dualTeacherWarnings)) {
                $payload['dual_teacher_warning'] = implode("\n", $dualTeacherWarnings);
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
     * @return array<int, list<array{start_time: string, duration_minutes: int|null, subject: string}>>
     */
    private function normalizeDayTimeSlotGroups(array $slots, string $defaultSubject): array
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
            $slotSubject = trim((string) ($slot['subject'] ?? ''));
            if ($slotSubject === '') {
                $slotSubject = $defaultSubject;
            }
            if (!isset($result[$day])) {
                $result[$day] = [];
            }
            $result[$day][] = [
                'start_time' => $this->normalizeTime(substr($time, 0, 5)),
                'duration_minutes' => $durMin,
                'subject' => $slotSubject,
            ];
        }
        foreach ($result as &$list) {
            usort($list, function ($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        ksort($result);

        return $result;
    }

    /**
     * @param  array<int, list<array{start_time: string, duration_minutes: int|null}>>  $groups
     * @return array<int, array{start_time: string, duration_minutes: int|null}>
     */
    private function collapseSlotGroupsToFirstPerWeekday(array $groups): array
    {
        $map = [];
        foreach ($groups as $day => $list) {
            if (empty($list)) {
                continue;
            }
            $first = $list[0];
            $map[$day] = [
                'start_time' => $first['start_time'],
                'duration_minutes' => $first['duration_minutes'],
            ];
        }
        ksort($map);

        return $map;
    }

    /**
     * @return list<array{date: string, start_time: string, duration_minutes: int, kind: 'confirmed'|'future', subject: string}>
     */
    private function buildRowsFromSessionPlan(
        array $sessionPlan,
        array $dayTimeSlotGroups,
        array $dayTimeSlotMap,
        int $globalDuration,
        string $fallbackStart,
        string $defaultSubject
    ): array {
        $rows = [];
        foreach ($sessionPlan as $item) {
            if (!is_array($item)) {
                continue;
            }
            try {
                $date = Carbon::parse($item['session_date'] ?? '')->toDateString();
            } catch (\Throwable $e) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            if (!in_array($kind, ['confirmed', 'future'], true)) {
                continue;
            }
            $stRaw = trim((string) ($item['start_time'] ?? ''));
            if ($stRaw === '') {
                continue;
            }
            $slotStartTime = $this->normalizeTime($stRaw);
            $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
            $dur = $this->resolveSlotDurationMinutes($dayTimeSlotGroups, $dayTimeSlotMap, $weekday, $slotStartTime, $globalDuration);
            $planSub = trim((string) ($item['subject'] ?? ''));
            $subject = $planSub !== ''
                ? $planSub
                : $this->inferSubjectFromSlotGroups($dayTimeSlotGroups, $weekday, $slotStartTime, $defaultSubject);
            $rows[] = [
                'date' => $date,
                'start_time' => $slotStartTime,
                'duration_minutes' => $dur,
                'kind' => $kind,
                'subject' => $subject,
            ];
        }
        usort($rows, function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            if ($c !== 0) {
                return $c;
            }

            return strcmp($a['start_time'], $b['start_time']);
        });

        return $rows;
    }

    /**
     * @return list<array{date: string, start_time: string, duration_minutes: int, kind: 'confirmed'|'future', subject: string}>
     */
    private function buildRowsFromLegacyDateLists(
        array $confirmedDates,
        array $futureDates,
        array $dayTimeSlotMap,
        array $dayTimeSlotGroups,
        int $globalDuration,
        string $fallbackStart,
        string $defaultSubject
    ): array {
        $rows = [];
        foreach ($confirmedDates as $date) {
            $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
            [$st, $dur] = $this->firstSlotStartAndDuration($weekday, $dayTimeSlotGroups, $dayTimeSlotMap, $globalDuration, $fallbackStart);
            $rows[] = [
                'date' => $date,
                'start_time' => $st,
                'duration_minutes' => $dur,
                'kind' => 'confirmed',
                'subject' => $this->inferSubjectFromSlotGroups($dayTimeSlotGroups, $weekday, $st, $defaultSubject),
            ];
        }
        foreach ($futureDates as $date) {
            $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
            [$st, $dur] = $this->firstSlotStartAndDuration($weekday, $dayTimeSlotGroups, $dayTimeSlotMap, $globalDuration, $fallbackStart);
            $rows[] = [
                'date' => $date,
                'start_time' => $st,
                'duration_minutes' => $dur,
                'kind' => 'future',
                'subject' => $this->inferSubjectFromSlotGroups($dayTimeSlotGroups, $weekday, $st, $defaultSubject),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, list<array{start_time: string, duration_minutes: int|null, subject?: string}>>  $dayTimeSlotGroups
     */
    private function inferSubjectFromSlotGroups(
        array $dayTimeSlotGroups,
        int $weekday,
        string $startTimeHms,
        string $defaultSubject
    ): string {
        if (!empty($dayTimeSlotGroups[$weekday])) {
            foreach ($dayTimeSlotGroups[$weekday] as $s) {
                if ($s['start_time'] === $startTimeHms) {
                    $sub = trim((string) ($s['subject'] ?? ''));

                    return $sub !== '' ? $sub : $defaultSubject;
                }
            }
            $first = $dayTimeSlotGroups[$weekday][0];
            $sub = trim((string) ($first['subject'] ?? ''));

            return $sub !== '' ? $sub : $defaultSubject;
        }

        return $defaultSubject;
    }

    /**
     * @param  array<int, list<array{start_time: string, duration_minutes: int|null, subject?: string}>>  $dayTimeSlotGroups
     * @return array<int, list<array{start_time: string, duration_minutes: int|null, subject?: string}>>
     */
    private function filterSlotGroupsBySubject(array $dayTimeSlotGroups, string $subjectKey): array
    {
        $out = [];
        foreach ($dayTimeSlotGroups as $day => $list) {
            $filtered = [];
            foreach ($list as $s) {
                if ((string) ($s['subject'] ?? '') === $subjectKey) {
                    $filtered[] = $s;
                }
            }
            if (!empty($filtered)) {
                $out[$day] = $filtered;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{date: string, start_time: string, duration_minutes: int, kind: string, subject?: string}>  $rows
     * @return array<int, list<array{start_time: string, duration_minutes: int|null, subject: string}>>
     */
    private function slotGroupsFromSessionRows(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            try {
                $wd = (int) Carbon::parse($r['date'])->dayOfWeekIso;
            } catch (\Throwable $e) {
                continue;
            }
            $st = $this->normalizeTime(substr((string) ($r['start_time'] ?? ''), 0, 5));
            if (!isset($g[$wd])) {
                $g[$wd] = [];
            }
            $g[$wd][] = [
                'start_time' => $st,
                'duration_minutes' => (int) ($r['duration_minutes'] ?? 0),
                'subject' => (string) ($r['subject'] ?? ''),
            ];
        }
        foreach ($g as &$list) {
            $seen = [];
            $deduped = [];
            foreach ($list as $item) {
                $k = $item['start_time'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $deduped[] = $item;
            }
            $list = $deduped;
            usort($list, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));
        }
        unset($list);
        ksort($g);

        return $g;
    }

    /**
     * @param  list<array{date: string, start_time: string, duration_minutes: int, kind: string, subject: string}>  $sessionRows
     * @return array<string, list<array{date: string, start_time: string, duration_minutes: int, kind: string, subject: string}>>
     */
    private function groupSessionRowsBySubject(array $sessionRows): array
    {
        $groups = [];
        foreach ($sessionRows as $row) {
            $k = (string) ($row['subject'] ?? '');
            if ($k === '') {
                $k = 'Math';
            }
            $groups[$k][] = $row;
        }
        foreach ($groups as &$gRows) {
            usort($gRows, function ($a, $b) {
                $c = strcmp($a['date'], $b['date']);
                if ($c !== 0) {
                    return $c;
                }

                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        unset($gRows);

        return $groups;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function firstSlotStartAndDuration(
        int $weekday,
        array $dayTimeSlotGroups,
        array $dayTimeSlotMap,
        int $globalDuration,
        string $fallbackStart
    ): array {
        if (!empty($dayTimeSlotGroups[$weekday][0])) {
            $s = $dayTimeSlotGroups[$weekday][0];
            $dur = (int) ($s['duration_minutes'] ?? $globalDuration);

            return [$s['start_time'], $dur];
        }
        $st = $dayTimeSlotMap[$weekday]['start_time'] ?? $fallbackStart;
        $dur = (int) ($dayTimeSlotMap[$weekday]['duration_minutes'] ?? $globalDuration);

        return [$st, $dur];
    }

    private function resolveSlotDurationMinutes(
        array $dayTimeSlotGroups,
        array $dayTimeSlotMap,
        int $weekday,
        string $startTimeHms,
        int $globalDuration
    ): int {
        if (!empty($dayTimeSlotGroups[$weekday])) {
            foreach ($dayTimeSlotGroups[$weekday] as $s) {
                if ($s['start_time'] === $startTimeHms) {
                    return (int) ($s['duration_minutes'] ?? $globalDuration);
                }
            }

            return (int) ($dayTimeSlotGroups[$weekday][0]['duration_minutes'] ?? $globalDuration);
        }

        return (int) ($dayTimeSlotMap[$weekday]['duration_minutes'] ?? $globalDuration);
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

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->active()->first();
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
