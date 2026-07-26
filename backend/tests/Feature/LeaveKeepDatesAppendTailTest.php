<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §R82 / Founder Decision — KEEP leave + repair:leave-vacated-weeks.
 */
class LeaveKeepDatesAppendTailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_repair_leave_vacated_weeks_dry_run_does_not_write(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00', 'Asia/Taipei'));
        $courseId = $this->seedLegacyShiftCourse();
        $before = $this->sessionFingerprint($courseId);

        $exit = Artisan::call('repair:leave-vacated-weeks', [
            '--dry-run' => true,
            '--course-id' => $courseId,
            '--limit' => 50,
        ]);
        $this->assertSame(0, $exit);
        $this->assertSame($before, $this->sessionFingerprint($courseId));
        $this->assertStringContainsString('future_safe=', Artisan::output());
    }

    public function test_repair_leave_vacated_weeks_apply_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00', 'Asia/Taipei'));
        $courseId = $this->seedLegacyShiftCourse();

        $exit1 = Artisan::call('repair:leave-vacated-weeks', [
            '--apply' => true,
            '--course-id' => $courseId,
            '--limit' => 50,
        ]);
        $this->assertSame(0, $exit1);
        $afterFirst = $this->sessionFingerprint($courseId);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-08-11',
            'Status' => 'scheduled',
        ]);
        $this->assertSame(
            0,
            ClassSession::query()
                ->where('StudentClassID', $courseId)
                ->where('Note', 'like', '%auto-extended-after-leave%')
                ->count()
        );

        $exit2 = Artisan::call('repair:leave-vacated-weeks', [
            '--apply' => true,
            '--course-id' => $courseId,
            '--limit' => 50,
        ]);
        $this->assertSame(0, $exit2);
        $this->assertSame($afterFirst, $this->sessionFingerprint($courseId));
    }

    public function test_detect_legacy_vacated_week_pattern_excludes_keep_dates_shape(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00', 'Asia/Taipei'));
        $courseId = $this->seedLegacyShiftCourse();
        $course = \App\Models\StudentClass::query()->where('ID', $courseId)->firstOrFail();
        $sessions = ClassSession::query()->where('StudentClassID', $courseId)->orderBy('SessionDate')->get();
        $this->assertTrue(
            CourseLeaveCascadeService::detectLegacyVacatedWeekPattern($sessions, '2026-08-04', $course)
        );

        // Restore natural next occupancy → pattern must clear.
        ClassSession::query()->where('StudentClassID', $courseId)->whereDate('SessionDate', '2026-08-18')
            ->update(['SessionDate' => '2026-08-11']);
        $sessions = ClassSession::query()->where('StudentClassID', $courseId)->orderBy('SessionDate')->get();
        $this->assertFalse(
            CourseLeaveCascadeService::detectLegacyVacatedWeekPattern($sessions, '2026-08-04', $course)
        );
    }

    /** @return list<string> */
    private function sessionFingerprint(int $courseId): array
    {
        return ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString() . ':' . $s->Status)
            ->all();
    }

    private function seedLegacyShiftCourse(): int
    {
        DB::table('Student')->insert([
            'id' => 88901,
            'name' => 'Vacated Week Fixture',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => 88901,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-09-12',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 2,
            'time' => '19:00:00',
        ]);

        DB::table('ClassSession')->insert([
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-04',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'leave',
                'Note' => 'leave; leave-policy-shift',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-18',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-25',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-09-01',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-09-08',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => 'auto-extended-after-leave',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $leaveId = (int) DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-08-04')
            ->value('id');
        DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-09-08')
            ->update([
                'Note' => CourseLeaveCascadeService::buildAutoExtendedNote('2026-08-04', $leaveId)
                    . '; leave-policy-shift',
            ]);

        return $courseId;
    }
}
