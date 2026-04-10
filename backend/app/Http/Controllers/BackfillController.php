<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackfillController extends Controller
{
    /**
     * POST /api/v1/backfill/register-subject-units
     *
     * For each given student class (by ID or by course descriptor), create ClassSession + LearningRecord
     * (approved, content 「補登／納入科目數」) for every date in [start_date, end_date] that matches
     * the class's day_of_week. Does NOT deduct 堂數 (remaining sessions).
     */
    public function registerSubjectUnits(Request $request)
    {
        return response()->json([
            'message' => 'Legacy subject-units backfill endpoint retired. Use POST /api/v1/class-sessions/batch.',
            'code' => 'legacy_subject_units_backfill_retired',
        ], 410);

        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'student_class_ids' => 'nullable|array',
            'student_class_ids.*' => 'integer',
            'courses' => 'nullable|array',
            'courses.*.student_id' => 'required_with:courses|integer',
            'courses.*.subject' => 'required_with:courses|string',
            'courses.*.teacher_id' => 'required_with:courses|integer',
            'courses.*.day_of_week' => 'nullable|integer|min:1|max:7',
            'courses.*.days_of_week' => 'nullable|array',
            'courses.*.days_of_week.*' => 'integer|min:1|max:7',
            'courses.*.start_time' => 'required_with:courses|string',
            'courses.*.end_time' => 'required_with:courses|string',
            'courses.*.class_type' => 'nullable|string|in:one_on_one,one_on_two,one_on_three,tutoring',
        ]);

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->endOfDay();
        if ($end->diffInDays($start) > 366) {
            return response()->json(['message' => '日期區間不得超過一年'], 422);
        }

        $pairs = []; // [StudentClass, day_of_week, start_time, end_time][]

        if (!empty($data['student_class_ids'])) {
            foreach ($data['student_class_ids'] as $id) {
                $sc = StudentClass::find($id);
                if (!$sc) {
                    continue;
                }
                $slots = $this->getClassScheduleSlots($sc);
                foreach ($slots as $dow => $time) {
                    $startTime = $time['start'] ?? '00:00';
                    $endTime = $time['end'] ?? '02:00';
                    $pairs[] = [$sc, (int) $dow, $startTime, $endTime];
                }
            }
        } elseif (!empty($data['courses'])) {
            $subjectMap = [
                'Chinese' => '國文', 'English' => '英文', 'Math' => '數學',
                'Physics' => '物理', 'Chemistry' => '化學', 'Science' => '理化', 'Biology' => '生物', 'Social' => '社會',
            ];
            foreach ($data['courses'] as $c) {
                $daysToUse = isset($c['days_of_week']) && is_array($c['days_of_week']) && count($c['days_of_week']) > 0
                    ? array_values(array_unique(array_map('intval', array_filter($c['days_of_week'], fn ($d) => $d >= 1 && $d <= 7))))
                    : (isset($c['day_of_week']) && (int) $c['day_of_week'] >= 1 && (int) $c['day_of_week'] <= 7 ? [(int) $c['day_of_week']] : []);
                if (empty($daysToUse)) {
                    continue;
                }
                $subjectName = $subjectMap[$c['subject'] ?? ''] ?? $c['subject'];
                $subjectId = DB::table('Subject')->where('Subject_Name', 'like', "%{$subjectName}%")->value('id')
                    ?? DB::table('BaseData')->where('Name', '課程')->where('Val', 'like', "%{$subjectName}%")->value('id')
                    ?? 1;
                $startTime = $this->normalizeTime($c['start_time'] ?? '00:00');
                $endTime = $this->normalizeTime($c['end_time'] ?? '02:00');
                foreach ($daysToUse as $dow) {
                    $sc = $this->findStudentClass(
                        (int) $c['student_id'],
                        (int) $c['teacher_id'],
                        $subjectId,
                        $dow
                    );
                    if (!$sc) {
                        continue;
                    }
                    if (!empty($c['class_type'])) {
                        $this->applyClassTypeToStudentClass($sc, $c['class_type']);
                    }
                    $pairs[] = [$sc, $dow, $startTime, $endTime];
                }
            }
        }

        if (empty($pairs)) {
            return response()->json(['message' => '沒有可處理的課程（請提供 student_class_ids 或 courses）', 'created' => 0], 422);
        }

        $created = 0;
        $subjectNames = DB::table('Subject')->pluck('Subject_Name', 'id')->toArray();
        $courseNames = DB::table('BaseData')->where('Name', '課程')->pluck('Val', 'id')->toArray();
        $authUser = $request->attributes->get('auth_user');
        $approvedBy = $authUser ? (int) $authUser->id : null;

        return DB::transaction(function () use ($pairs, $start, $end, &$created, $subjectNames, $courseNames, $approvedBy) {
            foreach ($pairs as [$studentClass, $dayOfWeek, $startTime, $endTime]) {
                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    if ((int) $cursor->format('N') === $dayOfWeek) {
                        $sessionDate = $cursor->toDateString();
                        $exists = ClassSession::where('StudentClassID', $studentClass->ID)
                            ->where('SessionDate', $sessionDate)
                            ->exists();
                        if (!$exists) {
                            $session = ClassSession::create([
                                'StudentClassID' => $studentClass->ID,
                                'SessionDate' => $sessionDate,
                                'StartTime' => $startTime,
                                'EndTime' => $endTime,
                                'Status' => 'completed',
                                'Note' => '',
                            ]);
                            $subjectName = $courseNames[$studentClass->SubjectID] ?? $subjectNames[$studentClass->SubjectID] ?? '補登';
                            LearningRecord::create([
                                'StudentClassID' => $studentClass->ID,
                                'ClassSessionID' => $session->id,
                                'TeacherID' => $studentClass->TeacherID,
                                'CreatedByUserID' => $approvedBy,
                                'Content' => '補登／納入科目數',
                                'Subject' => $subjectName,
                                'SessionDate' => $session->SessionDate,
                                'StartTime' => $session->StartTime,
                                'EndTime' => $session->EndTime,
                                'Status' => 'approved',
                                'ApprovedBy' => $approvedBy,
                                'ApprovedAt' => now(),
                            ]);
                            User::where('id', $studentClass->TeacherID)->increment('TeachingSessionCount');
                            $created++;
                        }
                    }
                    $cursor->addDay();
                }
            }
            return response()->json(['message' => '已建立科目數紀錄', 'created' => $created], 201);
        });
    }

    private function getClassScheduleSlots(StudentClass $sc): array
    {
        $slots = [];
        $weekFields = ['week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time1', 'time2', 'time3', 'time4', 'time5', 'time6'];
        foreach ($weekFields as $i => $wf) {
            $dow = (int) $sc->{$wf};
            if ($dow < 1 || $dow > 7) {
                continue;
            }
            $t = $sc->{$timeFields[$i]} ?? $sc->time ?? null;
            $start = $t ? substr($t, 0, 5) . ':00' : '00:00';
            $minutes = !empty($sc->LearnTimeID) ? (int) $sc->LearnTimeID * 60 : 120;
            $endDt = Carbon::createFromFormat('H:i', substr($start, 0, 5))->addMinutes($minutes);
            $end = $endDt->format('H:i') . ':00';
            $slots[$dow] = ['start' => $start, 'end' => $end];
        }
        if (empty($slots) && (int) $sc->week >= 1 && (int) $sc->week <= 7) {
            $t = $sc->time ?? null;
            $start = $t ? substr($t, 0, 5) . ':00' : '00:00';
            $minutes = !empty($sc->LearnTimeID) ? (int) $sc->LearnTimeID * 60 : 120;
            $endDt = Carbon::createFromFormat('H:i', substr($start, 0, 5))->addMinutes($minutes);
            $slots[(int) $sc->week] = ['start' => $start, 'end' => $endDt->format('H:i') . ':00'];
        }
        return $slots;
    }

    private function findStudentClass(int $studentId, int $teacherId, int $subjectId, int $dayOfWeek): ?StudentClass
    {
        $q = StudentClass::where('StudentID', $studentId)
            ->where('TeacherID', $teacherId)
            ->where('SubjectID', $subjectId);
        $q->where(function ($w) use ($dayOfWeek) {
            foreach (['week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $f) {
                $w->orWhere($f, $dayOfWeek);
            }
            $w->orWhere('week', $dayOfWeek);
        });
        return $q->first();
    }

    private function normalizeTime(string $t): string
    {
        $t = trim($t);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) {
            $parts = explode(':', $t);
            return sprintf('%02d:%02d:00', (int) $parts[0], (int) ($parts[1] ?? 0));
        }
        return '00:00:00';
    }

    /**
     * Map frontend class_type to Laravel StudentClass by1 and ClassType.
     * FinanceController subjectUnits: by1=1 → 一對一, by1=2 → 一對二, else → 一對三; ClassType/subject → tutoring.
     */
    private function applyClassTypeToStudentClass(StudentClass $sc, string $classType): void
    {
        $by1 = match ($classType) {
            'one_on_one' => 1,
            'one_on_two' => 2,
            'one_on_three' => 3,
            'tutoring' => 0,
            default => 1,
        };
        $sc->by1 = $by1;
        $sc->ClassType = $classType;
        $sc->save();
    }
}
