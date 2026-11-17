<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for Sentry PHP-LARAVEL-1Z: rescheduling a session onto a slot that
 * already holds a live session must return a clear 422 (slot_occupied), not a
 * raw 500 from the active-only unique index (#957/#1118). The DB index is the
 * backstop; this app-layer guard gives the director an actionable message.
 */
class RescheduleOccupiedSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_inapp565_real_reschedule_endpoint_allows_staggered_third_seat_and_refuses_fourth(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-01 10:00:00', 'Asia/Taipei'));
        try {
            $campus = \App\Models\Campus::factory()->create();
            [$token, $id] = $this->seedCourse((int) $campus->id);
            $course = StudentClass::findOrFail($id);
            $course->forceFill(['ClassType' => 'one_on_three', 'Charge' => 8000, 'Pay' => 0])->save();
            $peer = function () use ($course, $campus): StudentClass {
                $student = Student::create(['name' => 'isolated-peak-' . uniqid(), 'CampusID' => $campus->id,
                    'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
                $other = $course->replicate(); $other->StudentID = $student->id; $other->save();
                return $other;
            };
            $occupants = [];
            foreach ([['18:00', '20:00'], ['18:00', '20:00'], ['20:00', '22:00']] as [$start, $end]) {
                $occupants[] = ClassSession::create(['StudentClassID' => $peer()->ID, 'SessionDate' => '2026-09-03',
                    'StartTime' => $start, 'EndTime' => $end, 'Status' => 'scheduled']);
            }
            $before = array_map(fn ($s) => $s->refresh()->getAttributes(), $occupants);
            $moving = ClassSession::create(['StudentClassID' => $id, 'SessionDate' => '2026-09-02',
                'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);
            $payload = ['student_class_id' => $id, 'old_date' => '2026-09-02', 'old_start_time' => '14:00',
                'new_date' => '2026-09-03', 'start_time' => '19:00', 'end_time' => '21:00', 'ensure_schedule_exception' => true];
            $this->postReschedule($token, $payload)->assertOk();
            $moving->refresh();
            $this->assertSame('2026-09-03', substr((string) $moving->SessionDate, 0, 10));
            $this->assertSame('19:00', substr((string) $moving->StartTime, 0, 5));
            $this->assertSame($before, array_map(fn ($s) => $s->refresh()->getAttributes(), $occupants));
            $fourth = $peer();
            $blocked = ClassSession::create(['StudentClassID' => $fourth->ID, 'SessionDate' => '2026-09-02',
                'StartTime' => '12:00', 'EndTime' => '14:00', 'Status' => 'scheduled']);
            $this->postReschedule($token, array_replace($payload, ['student_class_id' => $fourth->ID, 'old_start_time' => '12:00']))
                ->assertStatus(409)->assertJsonPath('conflicts.0.type', 'teacher_capacity');
            $this->assertSame('2026-09-02', substr((string) $blocked->fresh()->SessionDate, 0, 10));
            $this->assertDatabaseCount('session_deduction_ledger', 0);
            $this->assertDatabaseCount('Payment', 0);
        } finally { \Carbon\Carbon::setTestNow(); }
    }

    public function test_reschedule_onto_occupied_live_slot_returns_422_not_500(): void
    {
        [$token, $courseId] = $this->seedCourse();

        // The session we will try to move.
        $moving = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);
        // An existing LIVE session already occupying the target slot.
        $occupant = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-05',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'attended',
        ]);

        $res = $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-09-01',
            'old_start_time' => '10:00',
            'new_date' => '2026-09-05',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'ensure_schedule_exception' => true,
        ]);

        $res->assertStatus(422);
        $res->assertJson(['code' => 'slot_occupied']);

        // Neither session moved / was mutated.
        $moving->refresh();
        $occupant->refresh();
        $this->assertSame('2026-09-01', substr((string) $moving->SessionDate, 0, 10));
        $this->assertSame('attended', $occupant->Status);
        $this->assertSame(2, ClassSession::where('StudentClassID', $courseId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")->count());
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_reschedule_onto_free_slot_still_succeeds(): void
    {
        [$token, $courseId] = $this->seedCourse();
        $moving = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-09-01',
            'old_start_time' => '10:00',
            'new_date' => '2026-09-08',
            'start_time' => '13:00',
            'end_time' => '15:00',
        ])->assertOk();

        $moving->refresh();
        $this->assertSame('2026-09-08', substr((string) $moving->SessionDate, 0, 10));
        $this->assertSame('13:00', substr((string) $moving->StartTime, 0, 5));
    }

    private function postReschedule(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', $payload);
    }

    private function seedCourse(int $campusId = 1): array
    {
        $director = User::create([
            'LoginName' => 'dir-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '090' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => 't-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '調課測試生-' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-01-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        return [$token, (int) $sc->ID];
    }
}
