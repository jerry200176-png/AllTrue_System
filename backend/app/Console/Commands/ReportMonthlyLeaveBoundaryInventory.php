<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only inventory for the R136 monthly/date-mode boundary invariant.
 *
 * This intentionally reports evidence and a bounded list of internal IDs. It
 * never updates a course, session, attendance row, learning record, invoice,
 * or payment row; historical repair remains a separately reviewed manifest.
 */
class ReportMonthlyLeaveBoundaryInventory extends Command
{
    protected $signature = 'monthly:leave-boundary-inventory
                            {--limit=200 : Maximum anomaly rows to include}
                            {--campus_id= : Optional campus filter for a bounded rehearsal}
                            {--json : Emit JSON (default)}';

    protected $description = 'Read-only all-campus inventory of date-mode sessions outside contract boundaries.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $campusId = $this->option('campus_id');
        $campusId = $campusId === null || $campusId === '' ? null : (int) $campusId;

        $coursesQuery = StudentClass::query()
            ->whereRaw("LOWER(COALESCE(ScheduleMode, 'count')) = 'date'")
            ->when($campusId !== null, function ($query) use ($campusId): void {
                $query->whereExists(function ($sub) use ($campusId): void {
                    $sub->selectRaw('1')
                        ->from('Student')
                        ->whereColumn('Student.id', 'StudentClass.StudentID')
                        ->where('Student.CampusID', $campusId);
                });
            })
            ->orderBy('ID');

        $courseCount = 0;
        $campusCount = [];
        $anomalies = [];
        $aggregate = [
            'sessions_scanned' => 0,
            'anomaly_sessions' => 0,
            'courses_with_anomaly' => 0,
            'boundary_kind' => [],
            'evidence' => [
                'active_attendance' => 0,
                'active_learning_records' => 0,
                'no_evidence' => 0,
            ],
            'paid_state' => ['paid' => 0, 'partial' => 0, 'unpaid' => 0],
            'next_contract_exists' => 0,
            'slot_conflict' => 0,
        ];

        $coursesQuery->with(['student', 'coursePackage'])->chunkById(250, function ($courses) use (&$courseCount, &$campusCount, &$anomalies, &$aggregate, $limit): void {
            $sessionsByCourse = ClassSession::query()
                ->whereIn('StudentClassID', $courses->pluck('ID')->map(fn ($id) => (int) $id)->all())
                ->orderBy('SessionDate')
                ->orderBy('id')
                ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])
                ->groupBy('StudentClassID');

            foreach ($courses as $course) {
                $courseCount++;
                $campus = (int) ($course->student?->CampusID ?? 0);
                $campusCount[$campus] = ($campusCount[$campus] ?? 0) + 1;

                $sessions = $sessionsByCourse->get((int) $course->ID, collect());
                $aggregate['sessions_scanned'] += $sessions->count();
                $courseHadAnomaly = false;

                foreach ($sessions as $session) {
                    $sessionDate = Carbon::parse((string) $session->SessionDate)->toDateString();
                    $startDate = $course->StartDate ? Carbon::parse($course->StartDate)->toDateString() : null;
                    $endDate = $course->EndDate ? Carbon::parse($course->EndDate)->toDateString() : null;
                    $kind = $startDate === null || $endDate === null
                        ? 'contract_boundary_missing'
                        : ($sessionDate < $startDate ? 'before_start' : ($sessionDate > $endDate ? 'after_end' : null));
                    if ($kind === null) {
                        continue;
                    }

                    $courseHadAnomaly = true;
                    $aggregate['anomaly_sessions']++;
                    $aggregate['boundary_kind'][$kind] = ($aggregate['boundary_kind'][$kind] ?? 0) + 1;
                    $status = strtolower(trim((string) ($session->Status ?? '')));
                    $activeAttendance = StudentSignIn::query()
                        ->where('ClassSessionID', (int) $session->id)
                        ->whereNull('VoidedAt')
                        ->count();
                    $activeLearning = LearningRecord::query()
                        ->where('ClassSessionID', (int) $session->id)
                        ->whereNull('VoidedAt')
                        ->count();
                    $aggregate['evidence']['active_attendance'] += $activeAttendance;
                    $aggregate['evidence']['active_learning_records'] += $activeLearning;
                    if ($activeAttendance === 0 && $activeLearning === 0) {
                        $aggregate['evidence']['no_evidence']++;
                    }

                    $paidState = $this->paidState($course);
                    $aggregate['paid_state'][$paidState]++;
                    $nextContract = $this->hasNextContract($course, $sessionDate);
                    $slotConflict = $this->hasSlotConflict($course, $session);
                    if ($nextContract) $aggregate['next_contract_exists']++;
                    if ($slotConflict) $aggregate['slot_conflict']++;

                    if (count($anomalies) < $limit) {
                        $anomalies[] = [
                            'campus_id' => $campus ?: null,
                            'course_id' => (int) $course->ID,
                            'class_session_id' => (int) $session->id,
                            'session_date' => $sessionDate,
                            'boundary_kind' => $kind,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'status' => $status,
                            'active_attendance' => $activeAttendance,
                            'active_learning_records' => $activeLearning,
                            'paid_state' => $paidState,
                            'next_contract_exists' => $nextContract,
                            'slot_conflict' => $slotConflict,
                        ];
                    }
                }

                if ($courseHadAnomaly) {
                    $aggregate['courses_with_anomaly']++;
                }
            }
        }, 'ID');

        $report = [
            'report' => 'monthly_leave_date_boundary_inventory',
            'rule_version' => 'R136',
            'read_only' => true,
            'as_of' => Carbon::now('Asia/Taipei')->toIso8601String(),
            'campus_filter' => $campusId,
            'courses_scanned' => $courseCount,
            'campuses_with_date_mode_courses' => count(array_filter($campusCount, static fn (int $count): bool => $count > 0)),
            'date_mode_courses_by_campus' => $campusCount,
            'aggregate' => $aggregate,
            'bounded_anomalies' => $anomalies,
            'bounded_anomalies_limit' => $limit,
            'anomalies_truncated' => $aggregate['anomaly_sessions'] > count($anomalies),
            'repair_policy' => 'No historical rows changed. Review a controlled Repair Manifest; do not alter paid/settled rows or contract boundaries unless independently proven wrong.',
        ];

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function paidState(StudentClass $course): string
    {
        if ($course->isEffectivelyPaid($course->relationLoaded('coursePackage') ? $course->coursePackage : null)) {
            return 'paid';
        }

        $paidAmount = (int) (DB::table('Invoice')
            ->where('StudentClassID', (int) $course->ID)
            ->where(function ($query): void {
                $query->whereNull('Status')->orWhere('Status', '!=', 'void');
            })
            ->sum('PaidAmount'));
        $charge = (int) ($course->Charge ?? 0);
        if ($paidAmount <= 0) return 'unpaid';
        return $charge > 0 && $paidAmount >= $charge ? 'paid' : 'partial';
    }

    private function hasNextContract(StudentClass $course, string $sessionDate): bool
    {
        return StudentClass::query()
            ->where('StudentID', (int) $course->StudentID)
            ->where('ID', '!=', (int) $course->ID)
            ->whereDate('StartDate', '>', $course->StartDate ?: $sessionDate)
            ->exists();
    }

    private function hasSlotConflict(StudentClass $course, ClassSession $session): bool
    {
        return ClassSession::query()
            ->join('StudentClass as other_sc', 'other_sc.ID', '=', 'ClassSession.StudentClassID')
            ->where('other_sc.StudentID', (int) $course->StudentID)
            ->where('ClassSession.StudentClassID', '!=', (int) $course->ID)
            ->whereDate('ClassSession.SessionDate', Carbon::parse((string) $session->SessionDate)->toDateString())
            ->whereNotIn(DB::raw('LOWER(ClassSession.Status)'), ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->whereRaw('SUBSTRING(ClassSession.StartTime, 1, 5) < ?', [substr((string) $session->EndTime, 0, 5)])
            ->whereRaw('SUBSTRING(ClassSession.EndTime, 1, 5) > ?', [substr((string) $session->StartTime, 0, 5)])
            ->exists();
    }
}
