<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Tests\TestCase;

class TrialConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_conversion_preserves_trial_history_and_creates_clean_paid_schedule(): void
    {
        $token = $this->directorToken();
        $teacher = User::create([
            'LoginName' => 'trial-conversion-teacher-' . uniqid() . '@example.com',
            'Name' => '試聽轉正式老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0921234567',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $student = Student::create([
            'name' => '試聽轉正式測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $trial = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'trial',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-25',
            'TotalHours' => 2,
            'SessionCount' => 1,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 2,
            'time' => '16:00:00',
            'Memo' => '家長試聽',
        ]);
        $attended = ClassSession::create([
            'StudentClassID' => $trial->ID,
            'SessionDate' => '2026-08-25',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
        ]);
        $futureTrial = ClassSession::create([
            'StudentClassID' => $trial->ID,
            'SessionDate' => '2026-08-29',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$trial->ID}/convert-trial", [
                'sessions' => 8,
                'start_date' => '2026-09-01',
                'class_type' => 'one_on_one',
            ]);

        $response->assertCreated()
            ->assertJsonPath('source_course.id', $trial->ID)
            ->assertJsonPath('source_course.preserved_attended_sessions', 1)
            ->assertJsonPath('new_course.session_count', 8);

        $trial->refresh();
        $this->assertSame(1, (int) $trial->Stop);
        $this->assertSame('converted_trial', $trial->closed_reason);
        $this->assertNotNull($trial->trial_converted_to_id);
        $this->assertSame('attended', strtolower((string) $attended->fresh()->Status));
        $this->assertSame('cancelled', strtolower((string) $futureTrial->fresh()->Status));
        $this->assertSame(1, ClassSession::where('StudentClassID', $trial->ID)
            ->where('Status', 'attended')->count());
        $this->assertSame(8, ClassSession::where('StudentClassID', $trial->trial_converted_to_id)->count());
        $this->assertSame(0, ClassSession::where('StudentClassID', $trial->trial_converted_to_id)
            ->where('Status', 'attended')->count());
    }

    public function test_conversion_is_idempotently_blocked_after_first_success(): void
    {
        $token = $this->directorToken();
        $student = Student::create([
            'name' => '已轉換試聽生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $trial = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'trial', 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-25', 'SessionCount' => 1,
            'SessionDuration' => 120, 'RemainingSessions' => 1, 'UsedSessions' => 0,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 800, 'Stop' => 1,
            'closed_reason' => 'converted_trial', 'trial_converted_to_id' => 999,
            'MDate' => now(), 'ScheduleMode' => 'count', 'week' => 2, 'time' => '16:00:00',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/student-classes/{$trial->ID}/convert-trial", [
                'sessions' => 8, 'start_date' => '2026-09-01',
            ])->assertStatus(409)->assertJsonPath('code', 'trial_already_converted');
    }

    private function directorToken(): string
    {
        $director = User::create([
            'LoginName' => 'trial-conversion-director-' . uniqid() . '@example.com',
            'Name' => '試聽轉正式主任', 'PSW' => 'secret', 'type' => 'A',
            'phone' => '0911234567', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
