<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\StudentClass;
use Carbon\Carbon;

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

        $durationMinutes = $this->durationMinutes($sourceSession, $course);
        $durationSlots = max(1, (int) ceil($durationMinutes / 30));
        $teacherId = (int) ($course->TeacherID ?? 0);
        $capacity = $this->capacityForClassType((string) ($course->ClassType ?? 'one_on_one'));
        $occupancy = $this->buildOccupancy($workflow, $teacherId, $start, $end);
        $candidates = [];
        $rank = 1;

        for ($cursor = $start->copy(); $cursor->lte($end) && count($candidates) < $limit; $cursor->addDay()) {
            $date = $cursor->toDateString();
            $dayOccupancy = $occupancy[$date] ?? [];
            for ($slot = $this->slotIndex('09:00'); $slot <= $this->slotIndex('21:00') - $durationSlots && count($candidates) < $limit; $slot += 1) {
                $maxCount = 0;
                $available = true;
                $names = [];

                for ($offset = 0; $offset < $durationSlots; $offset += 1) {
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
                $candidates[] = [
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
            ->whereNotIn('ClassSession.Status', ['cancelled', 'leave', 'leave_requested'])
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
