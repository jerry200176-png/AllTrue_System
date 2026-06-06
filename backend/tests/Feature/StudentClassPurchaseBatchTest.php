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

class StudentClassPurchaseBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_batch_creates_separate_unpaid_course_without_merging_source(): void
    {
        $token = $this->createDirectorToken([1], 'director-purchase@example.com');

        $student = Student::create([
            'name' => '加購學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'Paid' => 1,
            'Rate' => 500,
            'SessionDuration' => 120,
            'Charge' => 4000,
            'StartDate' => '2026-03-01',
            'week' => 2,
            'time' => '20:00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 6,
            'start_date' => '2026-04-07',
            'mode' => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'new_purchase')
            ->assertJsonPath('new_course.session_count', 6)
            ->assertJsonPath('new_course.remaining_sessions', 6)
            ->assertJsonPath('new_course.created_sessions', 6)
            ->assertJsonPath('new_course.first_session_date', '2026-04-07')
            ->assertJsonPath('new_course.last_session_date', '2026-05-12');

        $source->refresh();
        $this->assertSame(8, (int) $source->SessionCount);
        $this->assertSame(1, (int) $source->RemainingSessions);
        $this->assertSame(1, (int) $source->Paid);

        $newId = (int) $res->json('new_course.id');
        $newCourse = StudentClass::where('ID', $newId)->first();
        $this->assertNotNull($newCourse);
        $this->assertSame(6, (int) $newCourse->SessionCount);
        $this->assertSame(6, (int) $newCourse->RemainingSessions);
        $this->assertSame(0, (int) $newCourse->Paid);
        $this->assertStringStartsWith('2026-04-07', (string) $newCourse->StartDate);
        $this->assertSame(3000, (int) $newCourse->Charge);

        // ClassSession rows must be created for the new course
        $sessionRows = ClassSession::where('StudentClassID', $newId)
            ->orderBy('SessionDate')
            ->get();
        $this->assertCount(6, $sessionRows);
        // First session on start_date (2026-04-07, a Monday — fallback uses start_date DOW)
        $this->assertStringStartsWith('2026-04-07', (string) $sessionRows[0]->SessionDate);
        $this->assertSame('scheduled', (string) $sessionRows[0]->Status);
        // All sessions should have the inherited time
        $this->assertStringStartsWith('20:00', (string) $sessionRows[0]->StartTime);
    }

    public function test_purchase_batch_rejects_split_mode(): void
    {
        $token = $this->createDirectorToken([1], 'director-repair@example.com');

        $student = Student::create([
            'name' => '修正學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 16,
            'RemainingSessions' => 10,
            'UsedSessions' => 6,
            'Paid' => 0,
            'Rate' => 500,
            'SessionDuration' => 60,
            'Charge' => 16000,
            'StartDate' => '2026-03-01',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 8,
            'start_date' => '2026-04-10',
            'mode' => 'split_existing',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);

        $source->refresh();
        $this->assertSame(16, (int) $source->SessionCount);
        $this->assertSame(10, (int) $source->RemainingSessions);
        $this->assertSame(6, (int) $source->UsedSessions);
        $this->assertSame(0, (int) $source->Paid);
        $this->assertSame(16000, (int) $source->Charge);
    }

    public function test_purchase_batch_builds_full_multi_day_contract_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-purchase-multiday@example.com');

        $student = Student::create([
            'name' => '多時段學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'Paid' => 1,
            'Rate' => 1000,
            'SessionDuration' => 120,
            'Charge' => 8000,
            'StartDate' => '2026-04-01',
            'week' => 1,
            'time' => '18:00:00',
            'week1' => 4,
            'time1' => '18:00:00',
            'duration1' => 120,
            'ClassType' => 'one_on_three',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 12,
            'start_date' => '2026-05-25',
            'mode' => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('new_course.created_sessions', 12)
            ->assertJsonPath('new_course.first_session_date', '2026-05-25')
            ->assertJsonPath('new_course.last_session_date', '2026-07-02');

        $newId = (int) $res->json('new_course.id');
        $sessions = ClassSession::where('StudentClassID', $newId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();
        $this->assertCount(12, $sessions);

        $this->assertTrue(
            $sessions->contains(fn ($s) => substr((string) $s->SessionDate, 0, 10) === '2026-05-28'),
            'multi-day contract must include Thursday slot in generated sessions'
        );
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 0,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 0,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
