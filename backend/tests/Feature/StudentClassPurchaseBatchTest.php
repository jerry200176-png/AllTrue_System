<?php

namespace Tests\Feature;

use App\Models\AuthToken;
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
            'SessionDuration' => 60,
            'Charge' => 8000,
            'StartDate' => '2026-03-01',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 6,
            'start_date' => '2026-04-01',
            'mode' => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'new_purchase')
            ->assertJsonPath('new_course.session_count', 6)
            ->assertJsonPath('new_course.remaining_sessions', 6);

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
        $this->assertStringStartsWith('2026-04-01', (string) $newCourse->StartDate);
        $this->assertSame(6000, (int) $newCourse->Charge);
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
