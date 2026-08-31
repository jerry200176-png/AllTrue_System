<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\UserCampus;
use App\Services\FrontendSubjectIdResolver;
use App\Services\Scheduling\DeductionBasis;
use App\Services\Scheduling\LessonEntitlementCoverageCalculator;
use App\Services\TeacherScopeService;
use App\Support\MysqlCharsetRejection;
use App\Services\StudentIdentityService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $isMonthlyRecurring = false;
        $endDateOverride = null;
        $paymentType = (string) ($data['payment_type'] ?? 'session');
        $endDateRaw = $data['end_date'] ?? null;
        $daysOfWeekRaw = $data['days_of_week'] ?? [];
        $sessionPlanRaw = $data['session_plan'] ?? null;
        $hasExplicitSessionPlan = !empty($sessionPlanRaw) && is_array($sessionPlanRaw);
        $isManualOccurrence = (string) ($data['scheduling_policy'] ?? 'auto_recurrence') === 'manual_occurrence';

        if ($isManualOccurrence && $paymentType !== 'session') {
            return response()->json([
                'message' => 'Manual occurrence scheduling requires an independent session course.',
                'errors' => ['scheduling_policy' => ['Only session-based independent courses support manual occurrences.']],
            ], 422);
        }

        if ($paymentType === 'monthly' && !empty($endDateRaw)) {
            $courseStart = $data['course_start_date'] ?? Carbon::today()->toDateString();
            $endDate = $endDateRaw;

            if ($endDate < $courseStart) {
                return response()->json([
                    'message' => '結束日不可早於開課日',
                    'errors' => ['end_date' => ['結束日不可早於開課日。']],
                ], 422);
            }

            $maxEnd = Carbon::today()->addDays(730)->toDateString();
            if ($endDate > $maxEnd) {
                return response()->json([
                    'message' => '結束日不可超過 2 年（730 天）',
                    'errors' => ['end_date' => ['結束日距今不可超過 730 天。']],
                ], 422);
            }

            $endDateOverride = $endDate;
        }

        if ($paymentType === 'monthly' && !$hasExplicitSessionPlan && $endDateOverride !== null && !empty($daysOfWeekRaw)) {
            $courseStart = $data['course_start_date'] ?? Carbon::today()->toDateString();
            $endDate = $endDateOverride;
            $isMonthlyRecurring = true;

            $weekdays = $this->normalizeWeekdayArray((array) $daysOfWeekRaw);
            $startTimeStr = $this->normalizeTime((string) ($data['start_time'] ?? '16:00'));
            $durationMinutes = (int) $data['duration_minutes'];

            $slots = [];
            foreach ($weekdays as $wd) {
                $slots[] = ['weekday' => $wd, 'time' => $startTimeStr];
            }

            $scController = app()->make(\App\Http\Controllers\StudentClassController::class);
            $generatedSessions = $scController->buildSessionsFromWeeklySchedule(
                0,
                $courseStart,
                $endDate,
                $slots,
                $durationMinutes,
                true
            );

            $futureDates = array_map(fn ($s) => $s['SessionDate'], $generatedSessions);
            $futureDates = array_values(array_unique($futureDates));
            sort($futureDates);

            if (empty($futureDates) && empty($confirmedDates)) {
                return response()->json([
                    'message' => '此期間無符合的課堂日期，請調整固定星期或日期範圍',
                    'errors' => ['end_date' => ['指定期間內無任何排課日。']],
                ], 422);
            }

            $data['monthly_sessions'] = count($confirmedDates) + count($futureDates);

            Log::info('monthly_recurring: auto-generated sessions', [
                'course_start' => $courseStart,
                'end_date' => $endDate,
                'days_of_week' => $weekdays,
                'generated_count' => count($futureDates),
            ]);
        }

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

        $sessionRows = [];
        if ($isManualOccurrence) {
            // Manual courses start with no projected/recurring rows. Each future
            // occurrence is materialized later by ManualSessionBookingService.
            $sessionRows = [];
        } elseif (!$isMonthlyRecurring && !empty($sessionPlanRaw) && is_array($sessionPlanRaw)) {
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

        if (!$isManualOccurrence && count($sessionRows) <= 0) {
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
        if (!$isManualOccurrence && !empty($allowedWeekdays) && !($paymentType === 'monthly' && $hasExplicitSessionPlan)) {
            $allowedSet = array_fill_keys($allowedWeekdays, true);
            $weekCn = ['', '一', '二', '三', '四', '五', '六', '日'];
            $today = Carbon::today()->toDateString();
            $monthlyOpeningDate = $isMonthlyRecurring && !empty($data['course_start_date'])
                ? Carbon::parse($data['course_start_date'])->toDateString()
                : null;
            foreach ($sessionRows as $row) {
                // 過去日期為歷史補登，不限固定上課星期
                if (($row['date'] ?? '') < $today) {
                    continue;
                }
                $wd = (int) Carbon::parse($row['date'])->dayOfWeekIso;
                $isMonthlyOpeningDate = $monthlyOpeningDate !== null
                    && ($row['date'] ?? '') === $monthlyOpeningDate;
                if (!isset($allowedSet[$wd]) && !$isMonthlyOpeningDate) {
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
        $plannedSessions = $isManualOccurrence
            ? (int) ($data['total_classes'] ?? 0)
            : $this->resolvePlannedSessions($data, count($sessionRows));
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

        // RFC non-standard duration D6 — entitlement and occurrence count are two
        // different numbers for actual-duration courses. `session_plan` decides how many
        // occurrences exist; `total_classes` decides how much entitlement was bought.
        // Forcing them equal is exactly what makes "buy 8 standard units, schedule six
        // 3-hour sessions" impossible to express. Every other course keeps the existing
        // equality rule byte-identical.
        $actualDurationOptIn = $this->wantsActualDuration($data);

        if (!$isManualOccurrence && !$actualDurationOptIn && $plannedSessions !== count($sessionRows)) {
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

        if ($actualDurationOptIn) {
            if ($contractError = $this->validateActualDurationContract($data, $isSessionMode)) {
                return $contractError;
            }
            // D5 — the backend computes coverage itself and refuses an unconfirmed
            // overage. It must never trust the frontend preview for this.
            if ($overageError = $this->guardOverageConfirmation($data, $plannedSessions, $sessionRows)) {
                return $overageError;
            }
        }

        $rowKeys = [];
        foreach ($sessionRows as $row) {
            $rowKeys[] = $row['date'] . '|' . $row['start_time'];
        }
        if (!$isManualOccurrence && count($rowKeys) !== count(array_unique($rowKeys))) {
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
        if (!$isManualOccurrence && !empty($overlapDates)) {
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

            if (!$isMonthlyRecurring) {
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

        $courseStartDate = !empty($data['course_start_date'])
            ? Carbon::parse($data['course_start_date'])->toDateString()
            : null;

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
            if ($courseStartDate && $normalizedDate < $courseStartDate) {
                return response()->json([
                    'message' => "預排日期 {$normalizedDate} 早於開課日 {$courseStartDate}",
                    'errors' => [
                        'session_plan' => ['預排堂次不可早於開課日'],
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
        $identityGroupId = !empty($data['identity_group_id']) ? (int) $data['identity_group_id'] : 0;
        $identitySourceStudentId = $studentId;
        $studentCampusId = 0;
        if ($studentId > 0) {
            $studentCampusId = (int) (Student::where('id', $studentId)->value('CampusID') ?? 0);
            if ($studentCampusId <= 0) {
                return response()->json(['message' => 'Student campus not found'], 422);
            }
        }

        $targetCampusId = $identityGroupId > 0
            ? (int) ($data['branch_id'] ?? 0)
            : ($studentCampusId > 0
            ? (int) ($data['branch_id'] ?? $studentCampusId)
            : (int) ($data['branch_id'] ?? ($campusIds[0] ?? 0)));

        if ($targetCampusId <= 0) {
            return response()->json([
                'message' => 'branch_id 為必填',
                'errors' => [
                    'branch_id' => ['請指定分校。'],
                ],
            ], 422);
        }

        if ($studentCampusId > 0 && $targetCampusId !== $studentCampusId && $identityGroupId <= 0) {
            return response()->json(['message' => 'branch_id 與學生所屬分校不一致'], 422);
        }

        if ($identityGroupId > 0) {
            if (!in_array($role, ['director', 'super_admin'], true)) {
                return response()->json(['message' => '只有主任或 super_admin 可使用跨分校身份報名'], 403);
            }
            $group = app(StudentIdentityService::class)->groupForStudent($identitySourceStudentId);
            if (!$group || (int) $group->id !== $identityGroupId) {
                return response()->json(['message' => 'identity_group_id 與來源學生不一致'], 422);
            }
            $member = app(StudentIdentityService::class)->activeMembers($identityGroupId)
                ->firstWhere('campus_id', $targetCampusId);
            if ($member) {
                $studentId = (int) $member->student_id;
                $studentCampusId = $targetCampusId;
            }
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

        $globalTeacherId = (int) $data['teacher_id'];
        $allowMultiTeacher = !empty($data['allow_multi_teacher']);
        $subjectGroups = $this->groupSessionRowsBySubjectAndTeacher($sessionRows, $globalTeacherId);
        if ($isManualOccurrence && empty($subjectGroups)) {
            $manualSubject = trim((string) ($data['subject'] ?? 'Math')) ?: 'Math';
            $manualSubjectId = FrontendSubjectIdResolver::resolve($manualSubject);
            if ($manualSubjectId === null) {
                return response()->json([
                    'message' => 'Subject not found for manual occurrence course.',
                    'errors' => ['subject' => ['Please select a valid subject.']],
                ], 422);
            }
            $manualGroupKey = $manualSubject . '::' . $globalTeacherId;
            $subjectGroups[$manualGroupKey] = [];
            $subjectMeta[$manualGroupKey] = [
                'id' => $manualSubjectId,
                'name' => FrontendSubjectIdResolver::resolveName($manualSubjectId, $manualSubject),
                'subject' => $manualSubject,
            ];
        }
        // subjectMeta keyed by group key (subject::teacherId); subject extracted for resolver
        $subjectMeta = [];
        foreach (array_keys($subjectGroups) as $groupKey) {
            $subjectOnly = $this->subjectFromGroupKey($groupKey);
            $rid = FrontendSubjectIdResolver::resolve($subjectOnly);
            if ($rid === null) {
                return response()->json([
                    'message' => "無法將科目「{$subjectOnly}」對應到 Subject 主檔，請檢查科目設定。",
                    'errors' => ['subject' => ["找不到對應的科目 ID：{$subjectOnly}"]],
                ], 422);
            }
            $subjectMeta[$groupKey] = [
                'id'      => $rid,
                'name'    => FrontendSubjectIdResolver::resolveName($rid, $subjectOnly),
                'subject' => $subjectOnly,
            ];
        }

        $scopeWarnings = [];
        $gradeForScope = null;
        if ($studentId > 0) {
            $gradeForScope = (int) (Student::where('id', $studentId)->value('ClassID') ?? 0);
        } elseif (!empty($data['student']['grade'])) {
            $gradeForScope = (string) $data['student']['grade'];
        }
        foreach ($subjectGroups as $groupKey => $groupRows) {
            $groupTeacherId = $this->teacherFromGroupKey($groupKey, $globalTeacherId);
            $scopeResult = TeacherScopeService::check(
                $groupTeacherId,
                (int) $subjectMeta[$groupKey]['id'],
                $gradeForScope ?: null
            );
            if (!empty($scopeResult['warnings'])) {
                $scopeWarnings = array_merge($scopeWarnings, $scopeResult['warnings']);
            }
        }
        $scopeWarnings = array_values(array_unique($scopeWarnings));

        if ($studentId > 0 && empty($data['force'])) {
            $existingActive = StudentClass::where('StudentID', $studentId)
                ->where('Stop', 0)
                ->get();

            if ($existingActive->isNotEmpty()) {
                // #805 防再犯：新課程的整體日期範圍（用於偵測「續報新期起始早於舊期結束」的重疊）。
                // 日期可能來自 confirmed_dates / future_dates，或 session_plan[].session_date。
                $planDates = array_map(
                    fn ($p) => is_array($p) ? ($p['session_date'] ?? null) : null,
                    $data['session_plan'] ?? []
                );
                $newDates = array_values(array_filter(array_merge(
                    $data['confirmed_dates'] ?? [],
                    $data['future_dates'] ?? [],
                    $planDates
                )));
                sort($newDates);
                $newStart = $newDates[0] ?? ($data['course_start_date'] ?? null);
                $newEnd = (!empty($newDates) ? $newDates[count($newDates) - 1] : null) ?? ($data['end_date'] ?? null);
                $newTeacherId = (int) ($data['teacher_id'] ?? 0);
                $toDay = fn ($d) => $d ? substr((string) $d, 0, 10) : null;
                $newStartD = $toDay($newStart);
                $newEndD = $toDay($newEnd);

                $conflicts = [];
                $overlaps = [];
                $newClassType = $data['class_type'] ?? '';
                foreach ($subjectGroups as $groupKey => $rows) {
                    $sId = (int) $subjectMeta[$groupKey]['id'];
                    $subjectOnly = $subjectMeta[$groupKey]['subject'];
                    foreach ($existingActive as $sc) {
                        if ((int) $sc->SubjectID !== $sId) {
                            continue;
                        }
                        if (($sc->ClassType ?? '') === $newClassType) {
                            $conflicts[] = [
                                'existing_course_id' => $sc->ID,
                                'subject' => $subjectOnly,
                                'class_type' => $sc->ClassType ?? '',
                                'remaining_sessions' => (int) ($sc->RemainingSessions ?? 0),
                            ];
                        }

                        // #805：同科目 + 同老師 + 日期區間重疊 → 重疊續報/重複建課。
                        // 重疊判定：newStart <= existing.EndDate 且 newEnd >= existing.StartDate。
                        // 試聽是旁聽正式課堂的加掛（ScheduleGuardService），不套用續報重疊守衛；
                        // 同型試聽重複仍由上方 duplicate_active_course 處理。
                        if ($newClassType === 'trial') {
                            continue;
                        }
                        $exStart = $toDay($sc->StartDate);
                        $exEnd = $toDay($sc->EndDate);
                        $sameTeacher = $newTeacherId > 0 && (int) $sc->TeacherID === $newTeacherId;
                        $datesOverlap = $newStartD && $newEndD && $exStart && $exEnd
                            && $newStartD <= $exEnd && $newEndD >= $exStart;
                        if ($sameTeacher && $datesOverlap) {
                            $overlaps[] = [
                                'existing_course_id' => $sc->ID,
                                'subject' => $subjectOnly,
                                'teacher_id' => (int) $sc->TeacherID,
                                'existing_range' => [$exStart, $exEnd],
                                'new_range' => [$newStartD, $newEndD],
                                'remaining_sessions' => (int) ($sc->RemainingSessions ?? 0),
                            ];
                        }
                    }
                }

                if (!empty($overlaps)) {
                    return response()->json([
                        'message' => '該學生已有「同科目、同老師、日期區間重疊」的進行中課程。若為續報，請將新課起始日改到舊課結束之後，或改用「加購堂數」延續原課程；重疊會造成同時段重複排課與點名重複。如確定要建立，請勾選強制建立。',
                        'code' => 'overlapping_active_course',
                        'conflicts' => $overlaps,
                    ], 409);
                }

                if (!empty($conflicts)) {
                    return response()->json([
                        'message' => '該學生已有相同科目的進行中課程，建議使用「加購堂數」延續原課程。如確定要建立新課程，請勾選強制建立。',
                        'code' => 'duplicate_active_course',
                        'conflicts' => $conflicts,
                    ], 409);
                }
            }
        }

        if (!empty($data['force'])) {
            $forceGate = $this->validateForceOverride($request, $data);
            if ($forceGate !== null) {
                return $forceGate;
            }
        }

        // #1378: wrap transaction so utf8mb3 Memo rejects become a clean 422 (no half-baked rows).
        // Canonical fix is migration 2026_07_22_130000 (utf8mb4); this mapping does NOT strip emoji.
        // MySQL 8 may abort the transaction on 3988 and surface PDO SAVEPOINT noise — treat as charset.
        try {
            return DB::transaction(function () use (
            $request,
            $data,
            $targetCampusId,
            $startTime,
            $isSessionMode,
            $isManualOccurrence,
            $plannedSessions,
            $studentId,
            $scopeWarnings,
            $globalDuration,
            $dayTimeSlotGroups,
            $subjectGroups,
            $subjectMeta,
            $globalTeacherId,
            $allowMultiTeacher,
            $endDateOverride,
            $identityGroupId,
            $identitySourceStudentId,
            $role,
            $campusIds
        ) {
            $student = $studentId > 0
                ? Student::find($studentId)
                : $this->createStudentInline((array) ($data['student'] ?? []), $targetCampusId);

            if ($identityGroupId > 0 && $student && (int) $student->CampusID !== $targetCampusId) {
                $student = app(StudentIdentityService::class)->createBranchMember(
                    $identityGroupId,
                    $identitySourceStudentId,
                    $targetCampusId,
                    (int) ($request->attributes->get('auth_user')->id ?? 0) ?: null,
                    (string) $role,
                    (array) $campusIds
                );
            }

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

            foreach ($subjectGroups as $groupKey => $rowsForSubject) {
                $meta = $subjectMeta[$groupKey];
                $subjectId = (int) $meta['id'];
                $subjectName = (string) $meta['name'];
                $subjectKey = $meta['subject'];
                $effectiveTeacherId = $this->teacherFromGroupKey($groupKey, $globalTeacherId);

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
                if ($isManualOccurrence) {
                    $allDates = [Carbon::parse($data['course_start_date'] ?? Carbon::today())->toDateString()];
                }
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
                $primaryWeekday = $isManualOccurrence ? null : (!empty($weekdays)
                    ? (int) $weekdays[0]
                    : (int) Carbon::parse($allDates[0])->dayOfWeekIso);

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
                // RFC non-standard duration D6: for actual-duration courses the purchased
                // entitlement is what the buyer paid for (total_classes), NOT how many
                // occurrences happen to be scheduled — those are now two different
                // numbers. Every other course keeps deriving it from the occurrence
                // count exactly as before.
                $sessionCount = $isManualOccurrence
                    ? $plannedSessions
                    : ($isSessionMode
                    ? ($this->wantsActualDuration($data)
                        ? (int) $this->resolvePlannedSessions($data, $groupSessionCount)
                        : $groupSessionCount)
                    : 0);
                $monthlySessions = !$isSessionMode ? $groupSessionCount : null;
                $chargeUnits = $isSessionMode ? $sessionCount : max(1, $monthlySessions ?: $groupSessionCount);

                $totalHours = 0;
                $charge = 0;
                if ($rateUnit === 'hour') {
                    if ($isManualOccurrence) {
                        $totalHours = (int) round(($plannedSessions * $groupGlobalDur) / 60);
                        $charge = (int) round($price * $totalHours);
                    } else {
                        foreach ($rowsForSubject as $row) {
                            $dur = (int) $row['duration_minutes'];
                            $totalHours += $dur / 60.0;
                            $charge += $price * ($dur / 60.0);
                        }
                        $totalHours = (int) round($totalHours);
                        $charge = (int) round($charge);
                    }
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
                    'TeacherID' => $effectiveTeacherId,
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
                    'SessionCount' => $isManualOccurrence ? $plannedSessions : $sessionCount,
                    'RemainingSessions' => $isSessionMode ? $sessionCount : 0,
                    'UsedSessions' => 0,
                    'settlement_day' => !$isSessionMode ? (int) ($data['settlement_day'] ?? 0) : null,
                    'monthly_sessions' => !$isSessionMode ? $monthlySessions : null,
                    'SessionDuration' => $groupGlobalDur,
                    // RFC non-standard duration D1/A1: the billing standard is persisted
                    // as its own per-course value at create time and never re-resolved
                    // from a mutable default later. Null keeps the course on
                    // fixed_session, which is every existing course's behaviour.
                    'standard_lesson_minutes' => $this->wantsActualDuration($data)
                        ? (int) $data['standard_lesson_minutes']
                        : null,
                    'deduction_basis' => $this->wantsActualDuration($data)
                        ? DeductionBasis::ACTUAL_DURATION
                        : DeductionBasis::FIXED_SESSION,
                    'TotalHours' => $totalHours,
                    'StartDate' => $allDates[0],
                    'EndDate' => $isManualOccurrence ? ($endDateOverride ?? ($data['end_date'] ?? null)) : ($endDateOverride ?? $allDates[count($allDates) - 1]),
                    'scheduling_policy' => $isManualOccurrence ? 'manual_occurrence' : 'auto_recurrence',
                    'week' => $primaryWeekday,
                    'time' => $startTimeForGroup,
                    'Memo' => $data['memo'] ?? null,
                    'PayDate' => !empty($data['paid_at']) ? $data['paid_at'] : null,
                    'Paid' => !empty($data['paid_at']) ? 1 : 0,
                    'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
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
                    if (!$allowMultiTeacher) {
                        $dualTeacherWarnings[] = "「{$subjectKey}」：此學生同科目已有其他課程：{$others}。若為多師排課屬正常情況。";
                    }
                }

                foreach ($rowsForSubject as $row) {
                    $date = $row['date'];
                    $slotStartTime = $row['start_time'];
                    $slotDur = (int) $row['duration_minutes'];
                    $slotEndTime = $this->computeEndTime($slotStartTime, $slotDur);

                    if ($row['kind'] === 'confirmed') {
                        $upsert = app(ClassSessionMaterializationService::class)->upsertSlot([
                            '_student_class' => $studentClass,
                            '_allow_student_overlap' => !empty($data['force']),
                            'StudentClassID' => $studentClass->ID,
                            'SessionDate' => $date,
                            'StartTime' => $slotStartTime,
                            'EndTime' => $slotEndTime,
                            'Status' => 'completed',
                            'Note' => '',
                        ]);
                        $classSession = $upsert['session'];
                        if (!$upsert['created']) {
                            if ((string) $classSession->Status !== 'completed') {
                                $classSession->Status = 'completed';
                                $classSession->save();
                            }
                            $skippedConfirmedDates[] = $date;
                        } else {
                            $createdConfirmedSessions++;
                        }

                        $syncResult = $this->syncApprovedLearningRecord(
                            $studentClass,
                            $classSession,
                            $effectiveTeacherId,
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

                    $isEndedAtCreateTime = $this->sessionEndedByEndTime($date, $slotEndTime, $decisionNow);
                    $upsert = app(ClassSessionMaterializationService::class)->upsertSlot([
                        '_student_class' => $studentClass,
                        '_allow_student_overlap' => !empty($data['force']),
                        'StudentClassID' => $studentClass->ID,
                        'SessionDate' => $date,
                        'StartTime' => $slotStartTime,
                        'EndTime' => $slotEndTime,
                        'Status' => $isEndedAtCreateTime ? 'completed' : 'scheduled',
                        'Note' => $isEndedAtCreateTime ? '系統判定補登（新增時已過下課時間）' : '',
                    ]);
                    $classSession = $upsert['session'];
                    if (!$upsert['created']) {
                        $skippedFutureDates[] = $date;
                        continue;
                    }
                    if ($isEndedAtCreateTime) {
                        $autoApprovedFromFuture++;
                        $createdConfirmedSessions++;
                        $syncResult = $this->syncApprovedLearningRecord(
                            $studentClass,
                            $classSession,
                            $effectiveTeacherId,
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
        } catch (\Throwable $e) {
            if (MysqlCharsetRejection::matches($e)) {
                Log::warning('enrollment_memo_charset_incompatible', [
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 240),
                ]);
                return response()->json([
                    'message' => '備註含有目前資料庫無法儲存的特殊字元（例如 emoji）。請暫時移除後重試；系統正在升級字元集以永久支援。',
                    'code' => 'memo_charset_incompatible',
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * Force-create after duplicate/overlap 409 must carry an audited decision reason.
     * Prevents silent permanent bypass of renewal guards (#1379 follow-up / Course Continuity).
     *
     * @param  array<string, mixed>  $data
     */
    private function validateForceOverride(Request $request, array $data): ?JsonResponse
    {
        $allowed = ['create_trial', 'renewal_next_term', 'independent_parallel'];
        $reason = trim((string) ($data['force_reason'] ?? ''));
        if ($reason === '' || !in_array($reason, $allowed, true)) {
            return response()->json([
                'message' => '強制建立課程時必須選擇明確原因（建立試聽／下一期續報／獨立課程）。',
                'code' => 'force_reason_required',
                'allowed_reasons' => $allowed,
            ], 422);
        }

        $note = trim((string) ($data['force_note'] ?? ''));
        if ($reason === 'independent_parallel' && $note === '') {
            return response()->json([
                'message' => '建立獨立課程時必須填寫原因，系統會留下操作紀錄。',
                'code' => 'force_note_required',
            ], 422);
        }

        $classType = (string) ($data['class_type'] ?? '');
        if ($reason === 'create_trial' && $classType !== 'trial') {
            return response()->json([
                'message' => '「建立試聽」僅適用於試聽課程。',
                'code' => 'force_reason_mismatch',
            ], 422);
        }

        $existingIds = [];
        if (!empty($data['existing_contract_ids']) && is_array($data['existing_contract_ids'])) {
            foreach ($data['existing_contract_ids'] as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $existingIds[] = $n;
                }
            }
        }
        $existingIds = array_values(array_unique($existingIds));

        $actorId = null;
        try {
            $actorId = $request->attributes->get('auth_user_id')
                ?? $request->user()->id
                ?? null;
        } catch (\Throwable $e) {
            $actorId = null;
        }

        Log::info('enrollment_force_override', [
            'actor_user_id' => $actorId ? (int) $actorId : null,
            'force_reason' => $reason,
            'force_note' => $note !== '' ? $note : null,
            'existing_contract_ids' => $existingIds,
            'student_id' => (int) ($data['student_id'] ?? 0) ?: null,
            'class_type' => $classType !== '' ? $classType : null,
            'branch_id' => (int) ($data['branch_id'] ?? 0) ?: null,
        ]);

        return null;
    }

    /** Did this request explicitly ask for actual-duration billing? */
    private function wantsActualDuration(array $data): bool
    {
        return (string) ($data['deduction_basis'] ?? DeductionBasis::FIXED_SESSION)
            === DeductionBasis::ACTUAL_DURATION;
    }

    /**
     * RFC non-standard duration D1/D2/D4 — contract validity at create time.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function validateActualDurationContract(array $data, bool $isSessionMode)
    {
        // The environment flag is the master switch. Without this check the API could
        // mint a course whose contract says "bill by clock time" while the deduction
        // engine is still off — the course would then silently deduct whole lessons,
        // which is worse than refusing to create it.
        if (!config('perfflags.actual_duration_deduction_enabled', false)) {
            return response()->json([
                'message' => '依實際時長扣堂尚未在此環境啟用',
                'errors' => ['deduction_basis' => ['此功能尚未啟用，請改用「每次上課扣一堂」。']],
            ], 422);
        }

        if (!$isSessionMode) {
            return response()->json([
                'message' => '依實際時長扣堂目前僅支援堂數制課程',
                'errors' => ['deduction_basis' => ['月結制課程尚不支援依實際時長扣堂。']],
            ], 422);
        }

        $standard = $data['standard_lesson_minutes'] ?? null;
        if ($standard === null || $standard === '') {
            return response()->json([
                'message' => '依實際時長扣堂的課程必須設定標準一堂時長',
                'errors' => ['standard_lesson_minutes' => ['請設定標準一堂時長（分鐘）。']],
            ], 422);
        }
        $standard = (int) $standard;
        if ($standard < 30 || $standard > 480) {
            return response()->json([
                'message' => '標準一堂時長需介於 30 至 480 分鐘',
                'errors' => ['standard_lesson_minutes' => ['標準一堂時長需介於 30 至 480 分鐘。']],
            ], 422);
        }

        // D4: the shared-pool ledger can only represent whole lessons (TD-059).
        if (!empty($data['package_id']) || !empty($data['PackageID'])) {
            return response()->json([
                'message' => '共用課程包不支援依實際時長扣堂',
                'errors' => ['deduction_basis' => ['共用課程包內的課程不可使用依實際時長扣堂。']],
            ], 422);
        }

        return null;
    }

    /**
     * RFC non-standard duration D5 — recompute coverage server-side and refuse to
     * create an over-scheduled course unless the operator explicitly confirmed it.
     *
     * Deliberately recomputed here rather than trusting `requires_overage_confirmation`
     * from the client: the frontend preview is a convenience, not an authority.
     *
     * @param  list<array<string, mixed>>  $sessionRows
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function guardOverageConfirmation(array $data, int $plannedSessions, array $sessionRows)
    {
        $standard = (int) $data['standard_lesson_minutes'];
        $occurrences = [];
        foreach ($sessionRows as $i => $row) {
            $occurrences[] = [
                'duration_minutes' => (int) $row['duration_minutes'],
                'sequence' => $i + 1,
                'date' => $row['date'] ?? null,
            ];
        }

        $coverage = (new LessonEntitlementCoverageCalculator())
            ->calculate($plannedSessions, $standard, $occurrences);

        if ($coverage['uncovered_minutes'] <= 0) {
            return null;
        }
        if (filter_var($data['overage_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $calc = new LessonEntitlementCoverageCalculator();

        return response()->json([
            'message' => '目前排程超出已購額度，請先確認超額部分需要續約、加購或後續處理',
            'code' => 'overage_confirmation_required',
            'errors' => ['overage_confirmed' => ['請勾選確認後再建立課程。']],
            'coverage' => [
                'standard_lesson_minutes' => $standard,
                'purchased_standard_units' => $plannedSessions,
                'entitlement_minutes' => $coverage['entitlement_minutes'],
                'scheduled_occurrence_count' => $coverage['occurrence_count'],
                'scheduled_minutes' => $coverage['scheduled_minutes'],
                'fully_covered_occurrences' => $coverage['fully_covered_occurrences'],
                'first_partially_covered_occurrence' => $coverage['first_partially_covered_occurrence'],
                'partial_covered_minutes' => $coverage['partial_covered_minutes'],
                'partial_uncovered_minutes' => $coverage['partial_uncovered_minutes'],
                'fully_uncovered_occurrences' => $coverage['fully_uncovered_occurrences'],
                'uncovered_minutes' => $coverage['uncovered_minutes'],
                'uncovered_lesson_equivalent' => $calc->lessonEquivalent($coverage['uncovered_minutes'], $standard),
            ],
        ], 422);
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
            $slotTeacherId = isset($slot['teacher_id']) && (int) $slot['teacher_id'] > 0
                ? (int) $slot['teacher_id']
                : null;
            if (!isset($result[$day])) {
                $result[$day] = [];
            }
            $result[$day][] = [
                'start_time'       => $this->normalizeTime(substr($time, 0, 5)),
                'duration_minutes' => $durMin,
                'subject'          => $slotSubject,
                'teacher_id'       => $slotTeacherId,
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
                'date'             => $date,
                'start_time'       => $slotStartTime,
                'duration_minutes' => $dur,
                'kind'             => $kind,
                'subject'          => $subject,
                'teacher_id'       => $this->inferTeacherIdFromSlotGroups($dayTimeSlotGroups, $weekday, $slotStartTime),
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
                'date'             => $date,
                'start_time'       => $st,
                'duration_minutes' => $dur,
                'kind'             => 'confirmed',
                'subject'          => $this->inferSubjectFromSlotGroups($dayTimeSlotGroups, $weekday, $st, $defaultSubject),
                'teacher_id'       => $this->inferTeacherIdFromSlotGroups($dayTimeSlotGroups, $weekday, $st),
            ];
        }
        foreach ($futureDates as $date) {
            $weekday = (int) Carbon::parse($date)->dayOfWeekIso;
            [$st, $dur] = $this->firstSlotStartAndDuration($weekday, $dayTimeSlotGroups, $dayTimeSlotMap, $globalDuration, $fallbackStart);
            $rows[] = [
                'date'             => $date,
                'start_time'       => $st,
                'duration_minutes' => $dur,
                'kind'             => 'future',
                'subject'          => $this->inferSubjectFromSlotGroups($dayTimeSlotGroups, $weekday, $st, $defaultSubject),
                'teacher_id'       => $this->inferTeacherIdFromSlotGroups($dayTimeSlotGroups, $weekday, $st),
            ];
        }

        return $rows;
    }

    /**
     * Return the slot-specific teacher_id (null = inherit global teacher).
     */
    private function inferTeacherIdFromSlotGroups(array $dayTimeSlotGroups, int $weekday, string $startTimeHms): ?int
    {
        if (!empty($dayTimeSlotGroups[$weekday])) {
            foreach ($dayTimeSlotGroups[$weekday] as $s) {
                if ($s['start_time'] === $startTimeHms) {
                    $tid = isset($s['teacher_id']) ? (int) $s['teacher_id'] : 0;
                    return $tid > 0 ? $tid : null;
                }
            }
            $first = $dayTimeSlotGroups[$weekday][0];
            $tid = isset($first['teacher_id']) ? (int) $first['teacher_id'] : 0;
            return $tid > 0 ? $tid : null;
        }
        return null;
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
     * Group session rows by (subject + effective teacher_id).
     * Key format: "{subject}::{teacherId}" so that same subject with different teachers → separate StudentClass.
     * When all rows share the same teacher (or none specify a teacher), the key is "{subject}::{globalTeacherId}",
     * which is backwards-compatible with the single-teacher flow.
     *
     * @return array<string, list<array{date:string,start_time:string,duration_minutes:int,kind:string,subject:string,teacher_id:int|null}>>
     */
    private function groupSessionRowsBySubjectAndTeacher(array $sessionRows, int $globalTeacherId): array
    {
        $groups = [];
        foreach ($sessionRows as $row) {
            $subject = (string) ($row['subject'] ?? '');
            if ($subject === '') {
                $subject = 'Math';
            }
            $tid = isset($row['teacher_id']) && (int) $row['teacher_id'] > 0
                ? (int) $row['teacher_id']
                : $globalTeacherId;
            $key = $subject . '::' . $tid;
            $groups[$key][] = $row;
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

    /** Extract subject part from a group key of format "subject::teacherId". */
    private function subjectFromGroupKey(string $key): string
    {
        $pos = strrpos($key, '::');
        return $pos !== false ? substr($key, 0, $pos) : $key;
    }

    /** Extract effective teacher_id from a group key; falls back to $globalTeacherId. */
    private function teacherFromGroupKey(string $key, int $globalTeacherId): int
    {
        $pos = strrpos($key, '::');
        if ($pos === false) {
            return $globalTeacherId;
        }
        $tid = (int) substr($key, $pos + 2);
        return $tid > 0 ? $tid : $globalTeacherId;
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
