<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: multi-weekday courses with different slot times must keep
 * per-weekday StartTime/EndTime after leave cascade date shifts.
 *
 * Teacher report: Wed 17:00–19:00 + Sat 10:00–12:00; after Wed leave,
 * Sat sessions incorrectly became 17:00–19:00 because cascade moved dates
 * while preserving each row's original clock.
 */
class LeaveCascadeMultiWeekdaySlotTimesTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT_ID = 77611;
    private const TEACHER_ID = 77612;

    protected function tearDown(): void
    {
        $courseId = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($courseId) {
            DB::table('ClassSession')->where('StudentClassID', $courseId)->delete();
            DB::table('StudentClass')->where('ID', $courseId)->delete();
        }
        DB::table('Student')->where('id', self::STUDENT_ID)->delete();
        parent::tearDown();
    }

    public function test_leave_on_wednesday_preserves_saturday_contract_times(): void
    {
        $courseId = $this->createWedSatCourse();

        // Fixed calendar: Wed 2026-07-01 17:00, Sat 2026-07-04 10:00, …
        $slots = [
            ['2026-07-01', '17:00:00', '19:00:00'], // Wed leave target
            ['2026-07-04', '10:00:00', '12:00:00'], // Sat
            ['2026-07-08', '17:00:00', '19:00:00'], // Wed
            ['2026-07-11', '10:00:00', '12:00:00'], // Sat
            ['2026-07-15', '17:00:00', '19:00:00'], // Wed
            ['2026-07-18', '10:00:00', '12:00:00'], // Sat
        ];
        foreach ($slots as [$date, $start, $end]) {
            $this->insertSession($courseId, $date, $start, $end);
        }

        DB::transaction(function () use ($courseId) {
            CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01');
        });

        $active = ClassSession::where('StudentClassID', $courseId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled', 'voided')")
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();

        $this->assertSame(7, $active->count(), '6 originals (1 leave) + 1 append');

        $leave = $active->first(fn ($s) => strtolower((string) $s->Status) === 'leave');
        $this->assertNotNull($leave);
        $this->assertSame('2026-07-01', substr((string) $leave->SessionDate, 0, 10));
        $this->assertSame('17:00', substr((string) $leave->StartTime, 0, 5));
        $this->assertSame('19:00', substr((string) $leave->EndTime, 0, 5));

        foreach ($active as $session) {
            $date = substr((string) $session->SessionDate, 0, 10);
            $iso = (int) Carbon::parse($date)->dayOfWeekIso;
            $start = substr((string) $session->StartTime, 0, 5);
            $end = substr((string) $session->EndTime, 0, 5);

            if ($iso === 3) {
                $this->assertSame('17:00', $start, "Wed {$date} must stay 17:00");
                $this->assertSame('19:00', $end, "Wed {$date} must stay 19:00");
            } elseif ($iso === 6) {
                $this->assertSame('10:00', $start, "Sat {$date} must stay 10:00 (not Wed 17:00)");
                $this->assertSame('12:00', $end, "Sat {$date} must stay 12:00 (not Wed 19:00)");
            } else {
                $this->fail("Unexpected weekday {$iso} on {$date}");
            }
        }
    }

    public function test_undo_leave_restores_per_weekday_contract_times(): void
    {
        $courseId = $this->createWedSatCourse();
        foreach ([
            ['2026-07-01', '17:00:00', '19:00:00'],
            ['2026-07-04', '10:00:00', '12:00:00'],
            ['2026-07-08', '17:00:00', '19:00:00'],
            ['2026-07-11', '10:00:00', '12:00:00'],
        ] as [$date, $start, $end]) {
            $this->insertSession($courseId, $date, $start, $end);
        }

        DB::transaction(function () use ($courseId) {
            CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01');
        });
        DB::transaction(function () use ($courseId) {
            CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01');
        });

        $active = ClassSession::where('StudentClassID', $courseId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled', 'voided')")
            ->orderBy('SessionDate')
            ->get();

        $this->assertSame(4, $active->count());
        foreach ($active as $session) {
            $date = substr((string) $session->SessionDate, 0, 10);
            $iso = (int) Carbon::parse($date)->dayOfWeekIso;
            if ($iso === 3) {
                $this->assertSame('17:00', substr((string) $session->StartTime, 0, 5));
            } else {
                $this->assertSame(6, $iso);
                $this->assertSame('10:00', substr((string) $session->StartTime, 0, 5));
            }
        }
    }

    public function test_contract_exception_session_keeps_custom_times_after_cascade(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            $this->markTestSkipped('IsContractException column unavailable');
        }

        $courseId = $this->createWedSatCourse();
        $this->insertSession($courseId, '2026-07-01', '17:00:00', '19:00:00');
        $this->insertSession($courseId, '2026-07-04', '10:00:00', '12:00:00');
        // Intentional makeup-like exception on a future Wed with custom times
        $exceptionId = $this->insertSession($courseId, '2026-07-08', '14:00:00', '16:00:00', true);
        $this->insertSession($courseId, '2026-07-11', '10:00:00', '12:00:00');

        DB::transaction(function () use ($courseId) {
            CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01');
        });

        $exception = ClassSession::findOrFail($exceptionId);
        $this->assertSame('14:00', substr((string) $exception->StartTime, 0, 5));
        $this->assertSame('16:00', substr((string) $exception->EndTime, 0, 5));
    }

    private function createWedSatCourse(): int
    {
        DB::table('Student')->insert([
            'id' => self::STUDENT_ID,
            'name' => 'Leave Cascade Multi-Weekday',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        $payload = [
            'StudentID' => self::STUDENT_ID,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => self::TEACHER_ID,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => '2026-07-01',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 6,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 3,
            'time' => '17:00:00',
            'week1' => 6,
            'time1' => '10:00:00',
        ];
        if (Schema::hasColumn('StudentClass', 'duration1')) {
            $payload['duration1'] = 120;
        }

        return (int) DB::table('StudentClass')->insertGetId($payload);
    }

    private function insertSession(
        int $courseId,
        string $date,
        string $start,
        string $end,
        bool $isException = false
    ): int {
        $row = [
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => $start,
            'EndTime' => $end,
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($isException && Schema::hasColumn('ClassSession', 'IsContractException')) {
            $row['IsContractException'] = 1;
        }

        return (int) DB::table('ClassSession')->insertGetId($row);
    }
}
