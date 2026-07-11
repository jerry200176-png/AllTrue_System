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
 * #1161: editing a session's time via PATCH /class-sessions/{id} onto a slot
 * already held by another live session must return the shared 422 slot_occupied
 * (via ClassSessionMaterializationService::assertSlotAvailable) instead of a raw
 * 1062 → 500. The guard fires before any write, so the source session is untouched.
 */
class ClassSessionEditTimeSlotOccupiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_time_onto_occupied_slot_returns_422_and_does_not_move(): void
    {
        [$token, $courseId] = $this->seedCourse();

        $occupant = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-05',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        $moving = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-05',
            'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
        ]);

        $res = $this->patchSession($token, $moving->id, [
            'status' => 'scheduled',
            'start_time' => '13:00',
            'end_time' => '15:00',
        ]);

        $res->assertStatus(422);
        $res->assertJson(['code' => 'slot_occupied']);

        $moving->refresh();
        $this->assertSame('15:00', substr((string) $moving->StartTime, 0, 5), 'source session must not move');
        $this->assertSame(2, ClassSession::where('StudentClassID', $courseId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")->count());
    }

    public function test_edit_time_onto_free_slot_succeeds(): void
    {
        [$token, $courseId] = $this->seedCourse();

        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-05',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        $moving = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-05',
            'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
        ]);

        $this->patchSession($token, $moving->id, [
            'status' => 'scheduled',
            'start_time' => '17:00',
            'end_time' => '19:00',
        ])->assertOk();

        $moving->refresh();
        $this->assertSame('17:00', substr((string) $moving->StartTime, 0, 5));
    }

    private function patchSession(string $token, int $id, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$id}", $payload);
    }

    private function seedCourse(int $campusId = 1): array
    {
        $director = User::create([
            'LoginName' => 'dir-edit-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '090' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => 't-edit-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '改時測試生-' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
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
