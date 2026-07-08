<?php

namespace App\Imports;

use App\Models\ClassSession;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentClassesImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['student_id'])) {
                continue;
            }

            $slots = $this->extractSlots($row);
            $data = [
                'StudentID' => (int) $row['student_id'],
                'GradeID' => (int) ($row['grade_id'] ?? 0),
                'SubjectID' => (int) ($row['subject_id'] ?? 0),
                'ClassType' => $row['class_type'] ?? 'regular',
                'TeacherID' => (int) ($row['teacher_id'] ?? 0),
                'by1' => (int) ($row['by1'] ?? 1),
                'Period' => (int) ($row['period'] ?? 4),
                'StartDate' => $row['start_date'] ?? Carbon::now()->toDateString(),
                'EndDate' => $row['end_date'] ?? null,
                'TotalHours' => (int) ($row['total_hours'] ?? 0),
                'Memo' => $row['memo'] ?? null,
                'Charge' => (int) ($row['charge'] ?? 0),
                'Pay' => (int) ($row['pay'] ?? 0),
                'PayDate' => $row['pay_date'] ?? null,
                'Paid' => (int) ($row['paid'] ?? 0),
                'Disconunt' => (int) ($row['discount'] ?? 0),
                'Rate' => $row['rate'] ?? null,
                'LearnTimeID' => (int) ($row['learn_time_id'] ?? 0),
                'room_id' => !empty($row['room_id']) ? (int) $row['room_id'] : null,
                'ScheduleMode' => $row['schedule_mode'] ?? 'date',
                'SessionCount' => isset($row['session_count']) ? (int) $row['session_count'] : null,
                'RemainingSessions' => isset($row['remaining_sessions']) ? (int) $row['remaining_sessions'] : null,
                'MDate' => Carbon::now(),
                'Stop' => 0,
            ];

            if ($data['ScheduleMode'] === 'count' && $data['RemainingSessions'] === null) {
                $data['RemainingSessions'] = $data['SessionCount'];
            }

            $data = $this->mapScheduleSlots($data, $slots);
            $studentClass = StudentClass::create($data);

            if (!empty($slots)) {
                $duration = 120;
                if ($data['ScheduleMode'] === 'date' && !empty($data['EndDate'])) {
                    ClassSession::insert(
                        $this->buildSessionsFromWeeklySchedule(
                            $studentClass->ID,
                            $data['StartDate'],
                            $data['EndDate'],
                            $slots,
                            $duration
                        )
                    );
                }

                if ($data['ScheduleMode'] === 'count' && !empty($data['SessionCount'])) {
                    ClassSession::insert(
                        $this->buildSessionsForCount(
                            $studentClass->ID,
                            $data['StartDate'],
                            (int) $data['SessionCount'],
                            $slots,
                            $duration
                        )
                    );
                }
            }
        }
    }

    private function extractSlots($row): array
    {
        $slots = [];
        for ($i = 1; $i <= 3; $i++) {
            $weekday = $row['slot' . $i . '_weekday'] ?? null;
            $time = $row['slot' . $i . '_time'] ?? null;
            if ($weekday === null || $time === null) {
                continue;
            }
            $slots[] = [
                'weekday' => \App\Http\Controllers\StudentClassController::isoWeekday($weekday),
                'time' => $time,
            ];
        }

        return $slots;
    }

    private function mapScheduleSlots(array $data, array $slots): array
    {
        $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];

        foreach ($weekFields as $index => $weekField) {
            $data[$weekField] = $slots[$index]['weekday'] ?? null;
            $data[$timeFields[$index]] = $slots[$index]['time'] ?? null;
        }

        return $data;
    }

    private function buildSessionsFromWeeklySchedule(
        int $studentClassId,
        string $startDate,
        string $endDate,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            foreach ($slots as $slot) {
                // ISO compare (7=Sunday); raw dayOfWeek (0=Sunday) dropped Sunday slots (GitHub #1096)
                if ((int) $date->dayOfWeekIso === \App\Http\Controllers\StudentClassController::isoWeekday($slot['weekday'])) {
                    $startTime = Carbon::parse($date->toDateString() . ' ' . $slot['time']);
                    $endTime = $startTime->copy()->addMinutes($durationMinutes);
                    $sessions[] = [
                        'StudentClassID' => $studentClassId,
                        'SessionDate' => $startTime->toDateString(),
                        'StartTime' => $startTime->format('H:i:s'),
                        'EndTime' => $endTime->format('H:i:s'),
                        'Status' => 'scheduled',
                        'Note' => '',
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
            }
        }

        return $sessions;
    }

    private function buildSessionsForCount(
        int $studentClassId,
        string $startDate,
        int $sessionCount,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        $date = Carbon::parse($startDate)->startOfDay();
        $slotIndex = 0;

        while (count($sessions) < $sessionCount) {
            $slot = $slots[$slotIndex % count($slots)];
            if ((int) $date->dayOfWeekIso === \App\Http\Controllers\StudentClassController::isoWeekday($slot['weekday'])) {
                $startTime = Carbon::parse($date->toDateString() . ' ' . $slot['time']);
                $endTime = $startTime->copy()->addMinutes($durationMinutes);
                $sessions[] = [
                    'StudentClassID' => $studentClassId,
                    'SessionDate' => $startTime->toDateString(),
                    'StartTime' => $startTime->format('H:i:s'),
                    'EndTime' => $endTime->format('H:i:s'),
                    'Status' => 'scheduled',
                    'Note' => '',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
                $slotIndex++;
            }
            $date->addDay();
        }

        return $sessions;
    }
}
