<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CourseLeaveCascadeService
{
    /**
     * Mark the target session as leave, void related records,
     * shift subsequent scheduled sessions forward, and append one new session
     * to keep the total count intact.
     *
     * Must be called inside a DB::transaction.
     *
     * @return array{0:array,1:?string,2:string}  [session_rows, extended_end_date, leave_session_date]
     * @throws \InvalidArgumentException
     */
    public static function applyLeaveCascade(int $courseId, string $leaveDate): array
    {
        $course = StudentClass::where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) {
            throw new \InvalidArgumentException('找不到課程，無法請假');
        }

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        if ($sessions->isEmpty()) {
            throw new \InvalidArgumentException('課程尚無堂次可請假');
        }

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();

        // Find the target session: accept scheduled/leave (but not cancelled/leave_adjusted)
        $leaveSession = $sessions->first(function ($session) use ($normalizedLeaveDate) {
            $status = strtolower((string) ($session->Status ?? ''));
            return Carbon::parse($session->SessionDate)->toDateString() === $normalizedLeaveDate
                && !in_array($status, ['cancelled', 'leave_adjusted'], true);
        });
        if (!$leaveSession) {
            throw new \InvalidArgumentException('找不到可請假的堂次');
        }

        $leaveStatus = strtolower((string) ($leaveSession->Status ?? ''));

        if (in_array($leaveStatus, ['completed', 'attended'], true)) {
            throw new \InvalidArgumentException('已完成堂次不可請假（如需補請假請使用 retro-leave）');
        }

        // If session is already marked leave, only proceed if cascade hasn't run yet.
        // Detection: compare the actual next scheduled session date with the natural next
        // recurring date. If the actual next session is already LATER than the natural next
        // date, the cascade already ran (sessions were shifted forward). Use the sessions
        // collection already loaded (with lockForUpdate) to avoid extra queries.
        if ($leaveStatus === 'leave') {
            $weekdays = self::resolveCourseWeekdays(
                $course,
                (int) Carbon::parse($normalizedLeaveDate)->dayOfWeekIso
            );
            $naturalNext = self::nextRecurringDate(
                Carbon::parse($normalizedLeaveDate)->startOfDay(),
                $weekdays,
                [$normalizedLeaveDate => true]
            );
            $nextActual = $sessions
                ->filter(function ($s) use ($normalizedLeaveDate) {
                    $st = strtolower((string) ($s->Status ?? ''));
                    return Carbon::parse($s->SessionDate)->toDateString() > $normalizedLeaveDate
                        && !in_array($st, ['cancelled', 'leave', 'leave_adjusted'], true);
                })
                ->sortBy('SessionDate')
                ->first();
            if ($nextActual && Carbon::parse($nextActual->SessionDate)->toDateString() > $naturalNext) {
                throw new \InvalidArgumentException('該堂已完成請假登記與課程順延');
            }
        }

        $hasApprovedRecord = LearningRecord::where('ClassSessionID', $leaveSession->id)
            ->active()
            ->where('Status', 'approved')
            ->exists();
        if ($hasApprovedRecord) {
            throw new \InvalidArgumentException('該堂已有核准評量，無法改為請假');
        }

        $leaveSessionDate = Carbon::parse($leaveSession->SessionDate)->toDateString();

        LearningRecord::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => null,
                'VoidReason'     => '一般請假',
            ]);
        StudentSignIn::where('ClassSessionID', (int) $leaveSession->id)
            ->active()
            ->update([
                'VoidedAt'       => now(),
                'VoidedByUserID' => null,
                'VoidReason'     => '一般請假',
            ]);

        // Only update ClassSession status if not already leave
        if ($leaveStatus !== 'leave') {
            $leaveSession->Status = 'leave';
            $leaveSession->Note = self::appendNote($leaveSession->Note, 'leave');
            $leaveSession->save();
        }

        [$rows, $extendedEndDate] = self::shiftAndAppendAfterLeave($courseId, $leaveSessionDate, $leaveSession);

        return [$rows, $extendedEndDate, $leaveSessionDate];
    }

    /**
     * Shift remaining future scheduled sessions forward and append one new
     * session after the latest date, preserving the course weekday pattern.
     *
     * @return array{0:array,1:?string}
     */
    public static function shiftAndAppendAfterLeave(int $courseId, string $leaveDate, ClassSession $leaveSession): array
    {
        $course = StudentClass::where('ID', $courseId)->first();
        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $normalizedLeaveDate = Carbon::parse($leaveDate)->toDateString();

        $sessionsToShift = $sessions
            ->filter(function ($s) use ($normalizedLeaveDate, $leaveSession) {
                if ((int) $s->id === (int) $leaveSession->id) {
                    return false;
                }
                $d = Carbon::parse($s->SessionDate)->toDateString();
                if ($d <= $normalizedLeaveDate) {
                    return false;
                }
                $st = strtolower((string) ($s->Status ?? ''));
                return !in_array($st, ['completed', 'attended', 'cancelled', 'leave', 'leave_adjusted'], true);
            })
            ->values();

        $shiftIdSet = [];
        foreach ($sessionsToShift as $s) {
            $shiftIdSet[(int) $s->id] = true;
        }

        $occupiedDates = [];
        $occupiedDates[$normalizedLeaveDate] = true;
        foreach ($sessions as $s) {
            if (isset($shiftIdSet[(int) $s->id])) {
                continue;
            }
            $occupiedDates[Carbon::parse($s->SessionDate)->toDateString()] = true;
        }

        $weekdays = self::resolveCourseWeekdays(
            $course,
            Carbon::parse($leaveSession->SessionDate)->dayOfWeekIso
        );

        $templateSession = $sessionsToShift->last() ?: $leaveSession;
        foreach ($sessionsToShift as $s) {
            $currentDate = Carbon::parse($s->SessionDate)->startOfDay();
            $newDate = self::nextRecurringDate($currentDate, $weekdays, $occupiedDates);
            $s->SessionDate = $newDate;
            $s->save();
            self::syncLearningRecordSessionDate($s);
            $occupiedDates[$newDate] = true;
            $templateSession = $s;
        }

        $latestDate = self::maxDateKey($occupiedDates);
        if (!$latestDate) {
            $latestDate = $normalizedLeaveDate;
        }
        $appendDate = self::nextRecurringDate(Carbon::parse($latestDate)->startOfDay(), $weekdays, $occupiedDates);
        $newSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => $appendDate,
            'StartTime'      => $templateSession->StartTime,
            'EndTime'        => $templateSession->EndTime,
            'Status'         => 'scheduled',
            'Note'           => self::appendNote($templateSession->Note, 'auto-extended-after-leave'),
        ]);
        $occupiedDates[$appendDate] = true;
        self::syncLearningRecordSessionDate($newSession);

        $extendedEndDate = self::maxDateKey($occupiedDates);
        if ($extendedEndDate) {
            DB::table('StudentClass')
                ->where('ID', $courseId)
                ->update(['EndDate' => $extendedEndDate]);
        }

        $rows = self::fetchCourseSessionRows($courseId);
        return [$rows, $extendedEndDate];
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array<int> */
    public static function resolveCourseWeekdays(StudentClass $course, int $fallbackIsoDow): array
    {
        $weekdays = [];
        foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
            $dow = (int) ($course->{$field} ?? 0);
            if ($dow >= 1 && $dow <= 7) {
                $weekdays[$dow] = $dow;
            }
        }
        if (empty($weekdays)) {
            $dow = max(1, min(7, $fallbackIsoDow));
            $weekdays[$dow] = $dow;
        }
        ksort($weekdays);
        return array_values($weekdays);
    }

    /**
     * @param  array<int>          $weekdays
     * @param  array<string,bool>  $occupiedDates
     */
    public static function nextRecurringDate(Carbon $afterDate, array $weekdays, array $occupiedDates): string
    {
        $cursor = $afterDate->copy()->addDay()->startOfDay();
        $guard = 0;
        while ($guard < 3660) {
            $guard++;
            $isoDow = (int) $cursor->dayOfWeekIso;
            $date = $cursor->toDateString();
            if (in_array($isoDow, $weekdays, true) && !isset($occupiedDates[$date])) {
                return $date;
            }
            $cursor->addDay();
        }
        throw new \InvalidArgumentException('請假遞延失敗：找不到可用的後續上課日期');
    }

    public static function syncLearningRecordSessionDate(ClassSession $session): void
    {
        LearningRecord::where('ClassSessionID', (int) $session->id)->update([
            'SessionDate' => $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null,
            'StartTime'   => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
            'EndTime'     => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
        ]);
    }

    /** @param  array<string,bool>  $dateMap */
    public static function maxDateKey(array $dateMap): ?string
    {
        if (empty($dateMap)) {
            return null;
        }
        $keys = array_keys($dateMap);
        sort($keys, SORT_STRING);
        return end($keys) ?: null;
    }

    public static function appendNote($existing, string $suffix): string
    {
        $base = trim((string) ($existing ?? ''));
        if ($base === '') {
            return $suffix;
        }
        if (str_contains($base, $suffix)) {
            return $base;
        }
        return $base . '; ' . $suffix;
    }

    public static function fetchCourseSessionRows(int $courseId): array
    {
        return DB::table('ClassSession as cs')
            ->leftJoin('LearningRecord as lr', 'lr.ClassSessionID', '=', 'cs.id')
            ->where('cs.StudentClassID', $courseId)
            ->select([
                'cs.id',
                'cs.StudentClassID',
                'cs.SessionDate',
                'cs.StartTime',
                'cs.EndTime',
                'cs.Status',
                'lr.id as learning_record_id',
                'lr.Status as learning_record_status',
            ])
            ->orderBy('cs.SessionDate', 'asc')
            ->orderBy('cs.StartTime', 'asc')
            ->orderBy('cs.id', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'id'                     => (int) $row->id,
                    'StudentClassID'         => (int) $row->StudentClassID,
                    'SessionDate'            => $row->SessionDate ? substr((string) $row->SessionDate, 0, 10) : null,
                    'StartTime'              => $row->StartTime ? substr((string) $row->StartTime, 0, 5) : null,
                    'EndTime'                => $row->EndTime ? substr((string) $row->EndTime, 0, 5) : null,
                    'Status'                 => (string) ($row->Status ?? ''),
                    'learning_record_id'     => $row->learning_record_id !== null ? (int) $row->learning_record_id : null,
                    'learning_record_status' => $row->learning_record_status ?? 'missing',
                ];
            })
            ->values()
            ->all();
    }
}
