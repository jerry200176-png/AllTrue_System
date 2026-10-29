<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\PendingSwipe;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #2809 Founder 2026-10-29: only directors may enrol a student's card. Every route that writes
 * Student.RFID sits in the role:director group of routes/api.php; a teacher gets 403 and nothing changes.
 * (Swipe with no matching session = logged, not deducted: SwipeRfidHardeningTest::test_weekly_schedule_without_class_session_is_not_deducted.)
 */
class RfidEnrolmentDirectorOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $type, int $campusId): string
    {
        $user = User::create([
            'LoginName' => "rfid-{$type}-" . uniqid() . '@example.com', 'Name' => "Rfid {$type}",
            'PSW' => 'secret', 'type' => $type, 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function student(int $campusId, ?string $rfid = null): Student
    {
        return Student::create(['name' => 'RfidRole ' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'RFID' => $rfid, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function as(string $token): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    public function test_teacher_cannot_enrol_a_card_by_any_route(): void
    {
        $teacher = $this->token('T', 1);
        $student = $this->student(1);
        $swipe = PendingSwipe::create(['RFID' => 'ROLE-CARD-1', 'StudentID' => null, 'CampusID' => 1, 'SwipeAt' => now(), 'Reason' => 'student_not_found']);

        $this->as($teacher)->postJson("/api/v1/students/{$student->id}/bind-card", ['rfid' => 'ROLE-CARD-1'])->assertForbidden();
        $this->as($teacher)->putJson("/api/v1/students/{$student->id}", ['rfid' => 'ROLE-CARD-1'])->assertForbidden();
        $this->as($teacher)->postJson('/api/v1/students', ['name' => 'Teacher Made', 'rfid' => 'ROLE-CARD-2', 'branch_id' => 1])->assertForbidden();
        $this->as($teacher)->postJson("/api/v1/pending-swipes/{$swipe->id}/assign-student", ['StudentID' => $student->id])->assertForbidden();

        $this->assertNull($student->fresh()->RFID);
        $this->assertNull($swipe->fresh()->StudentID);
        $this->assertSame(0, Student::where('RFID', 'ROLE-CARD-2')->count());
    }

    public function test_director_can_still_enrol_a_card(): void
    {
        $director = $this->token('A', 1);
        $student = $this->student(1);
        $swipe = PendingSwipe::create(['RFID' => 'ROLE-CARD-3', 'StudentID' => null, 'CampusID' => 1, 'SwipeAt' => now(), 'Reason' => 'student_not_found']);

        $this->as($director)->postJson("/api/v1/students/{$student->id}/bind-card", ['rfid' => 'ROLE-CARD-4'])->assertOk();
        $this->assertSame('ROLE-CARD-4', $student->fresh()->RFID);

        $other = $this->student(1);
        $this->as($director)->postJson("/api/v1/pending-swipes/{$swipe->id}/assign-student", ['StudentID' => $other->id])->assertOk();
        $this->assertSame('ROLE-CARD-3', $other->fresh()->RFID);
    }
}
