<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Support\SessionStatus;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExceptionWorkflowCandidateGenerator
{
    public function __construct(private ExceptionWorkflowService $workflowService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function generate(ExceptionWorkflow $workflow, string $startDate, string $endDate, int $limit = 10): array
    {
        $workflow->loadMissing(['studentClass', 'classSession']);
        $course = $workflow->studentClass;
        $sourceSession = $workflow->classSession;
        if (!$course || !$sourceSession) {
            throw new \InvalidArgumentException('Workflow is missing course or session context.');
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('end_date must be after start_date.');
        }

        // A makeup lesson replaces the missed lesson; it cannot be offered
        // before that lesson happened. Enforce this server-side as well as in
        // the dashboard so old clients cannot reintroduce an earlier date.
        $sourceDate = Carbon::parse($sourceSession->SessionDate)->startOfDay();
        $start = $start->max($sourceDate->copy()->addDay());

        $durationMinutes = $this->durationMinutes($sourceSession, $course);
        $durationSlots = max(1, (int) ceil($durationMinutes / 30));
        $teacherId = (int) ($course->TeacherID ?? 0);
        $capacity = $this->capacityForClassType((string) ($course->ClassType ?? 'one_on_one'));
        $occupancy = $this->buildOccupancy($workflow, $teacherId, $start, $end);
        // Candidate generation is only a recommendation, but it must not offer
        // a slot that the final ClassSession guard will reject for this student
        // under another contract. Keep this read-side snapshot aligned with
        // the write boundary while the confirmation path remains authoritative.
        $studentBusySlots = $this->buildStudentBusySlots($workflow, $start, $end);
        // Keep the candidate set flexible across the whole window. A simple
        // date-first limit can fill every result with one morning's slots,
        // which makes a multi-day makeup window look artificially narrow.
        $candidateBuckets = [];
        $rank = 1;

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $dayOccupancy = $occupancy[$date] ?? [];
            for ($slot = $this->slotIndex('09:00'); $slot <= $this->slotIndex('21:00') - $durationSlots; $slot += 1) {
                $maxCount = 0;
                $available = true;
                $names = [];

                for ($offset = 0; $offset < $durationSlots; $offset += 1) {
                    if (!empty($studentBusySlots[$date][$slot + $offset])) {
                        $available = false;
                        break;
                    }
                    $cell = $dayOccupancy[$slot + $offset] ?? ['count' => 0, 'students' => []];
                    $count = (int) ($cell['count'] ?? 0);
                    if ($count >= $capacity) {
                        $available = false;
                        break;
                    }
                    $maxCount = max($maxCount, $count);
                    foreach (($cell['students'] ?? []) as $studentName) {
                        if ($studentName !== '') {
                            $names[$studentName] = true;
                        }
                    }
                }

                if (!$available) {
                    continue;
                }

                $startTime = $this->slotTime($slot);
                $endTime = $this->slotTime($slot + $durationSlots);
                $candidateBuckets[$date][] = [
                    'rank' => $rank,
                    'candidate_date' => $date,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'teacher_id' => $teacherId > 0 ? $teacherId : null,
                    'room_id' => $course->room_id ?? null,
                    'status' => $maxCount === 0 ? 'available' : 'warning',
                    'score' => max(0, 100 - ($maxCount * 15) - (($rank - 1) * 2)),
                    'reasons' => array_values(array_filter([
                        $maxCount === 0 ? '老師此時段無其他學生' : '老師此時段尚有容量',
                        !empty($names) ? '同時段學生：' . implode('、', array_keys($names)) : null,
                    ])),
                    'expires_at' => Carbon::parse("{$date} {$startTime}")->subMinutes(10)->toDateTimeString(),
                ];
                $rank += 1;
            }
        }

        // Round-robin by date: show at least one option per available day,
        // then fill each day with additional times until the requested limit.
        $candidates = [];
        $added = true;
        while ($added && count($candidates) < $limit) {
            $added = false;
            foreach ($candidateBuckets as &$dayCandidates) {
                if (count($candidates) >= $limit) {
                    break;
                }
                if ($dayCandidates === []) {
                    continue;
                }
                $candidate = array_shift($dayCandidates);
                $candidate['rank'] = count($candidates) + 1;
                $candidates[] = $candidate;
                $added = true;
            }
            unset($dayCandidates);
        }

        $this->workflowService->replaceCandidates($workflow, $candidates);
        $workflow->status = 'candidate_ready';
        $workflow->save();

        return $candidates;
    }

    /**
     * @return array<string, array<int, array{count:int, students:array<int, string>}>>
     */
    private function buildOccupancy(ExceptionWorkflow $workflow, int $teacherId, Carbon $start, Carbon $end): array
    {
        if ($teacherId <= 0) {
            return [];
        }

        $sessions = ClassSession::query()
            ->join('StudentClass as sc', 'sc.ID', '=', 'ClassSession.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', $teacherId)
            ->where('st.CampusID', (int) $workflow->campus_id)
            ->whereBetween('ClassSession.SessionDate', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('ClassSession.Status', array_merge(['cancelled'], SessionStatus::leaveFamily()))
            ->where('ClassSession.id', '!=', (int) $workflow->class_session_id)
            ->select([
                'ClassSession.SessionDate',
                'ClassSession.StartTime',
                'ClassSession.EndTime',
                'st.name as student_name',
            ])
            ->get();

        $occupancy = [];
        foreach ($sessions as $session) {
            $date = Carbon::parse($session->SessionDate)->toDateString();
            $startSlot = $this->slotIndex((string) $session->StartTime);
            $endSlot = $this->slotIndex((string) $session->EndTime);
            for ($slot = $startSlot; $slot < $endSlot; $slot += 1) {
                if (!isset($occupancy[$date][$slot])) {
                    $occupancy[$date][$slot] = ['count' => 0, 'students' => []];
                }
                $occupancy[$date][$slot]['count'] += 1;
                $occupancy[$date][$slot]['students'][] = (string) ($session->student_name ?? '');
            }
        }

        return $occupancy;
    }

    /**
     * Return 30-minute cells occupied by the same student in another active
     * contract. This mirrors the materializer's future reservation rule so a
     * cross-contract collision is removed during preview, not after confirm.
     *
     * @return array<string, array<int, true>>
     */
    private function buildStudentBusySlots(ExceptionWorkflow $workflow, Carbon $start, Carbon $end): array
    {
        $workflow->loadMissing('studentClass');
        $course = $workflow->relationLoaded('studentClass')
            ? $workflow->getRelation('studentClass')
            : null;
        $studentId = (int) $workflow->getAttribute('student_id');
        $courseId = (int) $workflow->getAttribute('student_class_id');
        if ($course instanceof StudentClass) {
            $studentId = $studentId ?: (int) $course->getAttribute('StudentID');
            $courseId = $courseId ?: (int) $course->getKey();
        }
        $packageId = $course instanceof StudentClass
            ? (int) $course->getAttribute('PackageID')
            : 0;
        if ($studentId <= 0 || $courseId <= 0) {
            return [];
        }

        $busy = [];
        $sessions = ClassSession::query()
            ->from('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.StudentClassID', '!=', $courseId)
            ->where(function ($query) {
                $query->where('sc.Stop', 0)
                    ->orWhereNull('sc.Stop')
                    ->orWhere(function ($stoppedQuery) {
                        $stoppedQuery->where('sc.Stop', 1)
                            ->where('sc.RemainingSessions', '>', 0);
                    });
            })
            ->whereNotIn('cs.Status', SessionStatus::futureReservationExclusionStatuses())
            ->whereRaw("LOWER(COALESCE(sc.ClassType, '')) <> ?", ['trial'])
            ->when($packageId > 0, function ($query) use ($packageId) {
                $query->where(function ($packageQuery) use ($packageId) {
                    $packageQuery->whereNull('sc.PackageID')
                        ->orWhere('sc.PackageID', '!=', $packageId);
                });
            })
            ->whereDate('cs.SessionDate', '>=', $start->toDateString())
            ->whereDate('cs.SessionDate', '<=', $end->toDateString())
            ->where('cs.id', '!=', (int) $workflow->getAttribute('class_session_id'))
            ->get(['cs.SessionDate', 'cs.StartTime', 'cs.EndTime']);

        foreach ($sessions as $session) {
            $this->markBusySlots(
                $busy,
                Carbon::parse($session->SessionDate)->toDateString(),
                (string) $session->StartTime,
                (string) $session->EndTime,
            );
        }

        // A scheduled row can exist before ClassSession materialization. It is
        // still a reservation and must not be offered as a makeup candidate.
        $schedules = DB::table('schedules as s')
            ->leftJoin('StudentClass as sc', 'sc.ID', '=', 's.student_course_id')
            ->where('s.student_id', $studentId)
            ->where('s.status', 'scheduled')
            ->whereNull('s.original_schedule_id')
            ->where(function ($query) use ($courseId) {
                $query->whereNull('s.student_course_id')
                    ->orWhere('s.student_course_id', '!=', $courseId);
            })
            ->where(function ($query) {
                $query->whereNull('sc.ID')
                    ->orWhere('sc.Stop', 0)
                    ->orWhereNull('sc.Stop')
                    ->orWhere(function ($stoppedQuery) {
                        $stoppedQuery->where('sc.Stop', 1)
                            ->where('sc.RemainingSessions', '>', 0);
                    });
            })
            ->where(function ($query) {
                $query->whereNull('sc.ClassType')
                    ->orWhereRaw("LOWER(sc.ClassType) <> ?", ['trial']);
            })
            ->when($packageId > 0, function ($query) use ($packageId) {
                $query->where(function ($packageQuery) use ($packageId) {
                    $packageQuery->whereNull('sc.PackageID')
                        ->orWhere('sc.PackageID', '!=', $packageId);
                });
            })
            ->whereDate('s.schedule_date', '>=', $start->toDateString())
            ->whereDate('s.schedule_date', '<=', $end->toDateString())
            ->get(['s.schedule_date', 's.start_time', 's.end_time']);

        foreach ($schedules as $schedule) {
            $this->markBusySlots(
                $busy,
                Carbon::parse($schedule->schedule_date)->toDateString(),
                (string) $schedule->start_time,
                (string) $schedule->end_time,
            );
        }

        return $busy;
    }

    /** @param array<string, array<int, true>> $busy */
    private function markBusySlots(array &$busy, string $date, string $startTime, string $endTime): void
    {
        $startSlot = $this->slotIndex($startTime);
        $endSlot = $this->slotIndex($endTime);
        for ($slot = $startSlot; $slot < $endSlot; $slot += 1) {
            $busy[$date][$slot] = true;
        }
    }

    private function durationMinutes(ClassSession $session, StudentClass $course): int
    {
        $start = Carbon::parse($session->StartTime ?: '00:00');
        $end = Carbon::parse($session->EndTime ?: '00:00');
        if ($end->gt($start)) {
            return max(30, $start->diffInMinutes($end));
        }

        return max(30, (int) ($course->SessionDuration ?? 120));
    }

    private function capacityForClassType(string $classType): int
    {
        return match ($classType) {
            'one_on_two' => 2,
            'one_on_three' => 3,
            'tutoring' => 4,
            default => 1,
        };
    }

    private function slotIndex(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time ?: '00:00', 0, 5)));
        return ($hour * 2) + intdiv($minute, 30);
    }

    private function slotTime(int $slot): string
    {
        $hour = intdiv($slot, 2);
        $minute = ($slot % 2) * 30;
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
