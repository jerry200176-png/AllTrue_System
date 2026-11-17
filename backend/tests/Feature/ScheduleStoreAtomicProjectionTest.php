<?php

namespace Tests\Feature;

use App\Models\{ClassSession, Schedule, Student, UserCampus};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** SourceRef563: a failed booking write/projection must leave neither a new nor a partially updated marker. */
class ScheduleStoreAtomicProjectionTest extends TestCase
{
    use RefreshDatabase, SubjectCreditFixtures;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow('2026-09-30 10:00:00'); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    public static function paths(): array { return [[false], [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function test_failed_new_projection_or_reused_update_rolls_back_the_whole_booking(bool $reuse): void
    {
        [$token, $source] = $this->fixture();
        $campus = (int) Student::findOrFail($source->StudentID)->CampusID;
        UserCampus::firstOrCreate(['UserID' => $source->TeacherID, 'CampusID' => $campus], ['Admin' => 0, 'Approved' => 1]);
        $input = ['student_id' => $source->StudentID, 'student_course_id' => $source->ID,
            'teacher_id' => $source->TeacherID, 'branch_id' => $campus, 'day_of_week' => 3,
            'schedule_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '20:00',
            'duration_hours' => 2, 'status' => 'scheduled', 'type' => 'normal'];
        $active = false;
        if ($reuse) {
            ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => '2026-10-14', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled']);
            $anchor = Schedule::create(array_replace($input, ['status' => 'rescheduled']));
            $existing = Schedule::create($input + ['original_schedule_id' => $anchor->id]);
            $input['original_schedule_id'] = $anchor->id;
            $input['end_time'] = '19:00'; $input['duration_hours'] = 1;
            Schedule::updated(function (Schedule $row) use (&$active, $existing): void {
                if ($active && (int) $row->id === (int) $existing->id) throw new \RuntimeException('isolated booking update failure');
            });
        } else {
            ClassSession::creating(function (ClassSession $row) use (&$active): void {
                if ($active && $row->Note === 'auto-materialized-from-schedule') throw new \RuntimeException('isolated projection failure');
            });
        }
        $before = $this->businessSnapshot(); $active = true;
        try { $this->withToken($token)->postJson('/api/v1/schedules', $input)->assertStatus(500); }
        finally { $active = false; }
        $this->assertSame($before, $this->businessSnapshot(), 'A failed request cannot leave its Schedule committed or partially updated.');
    }
}
