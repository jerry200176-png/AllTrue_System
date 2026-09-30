<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_type_filter_returns_only_matching_courses(): void
    {
        $token = $this->directorToken(1);
        $tutoring = $this->course('tutoring');
        $this->course('one_on_one');

        $res = $this->getJson('/api/v1/student-classes?class_type=tutoring', $this->headers($token));

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([(int) $tutoring->ID], $ids);
    }

    public function test_unfiltered_lists_all_types(): void
    {
        $token = $this->directorToken(1);
        $this->course('one_on_one');
        $this->course('tutoring');

        $all = $this->getJson('/api/v1/student-classes', $this->headers($token));
        $this->assertCount(2, $all->json('data'));
    }

    public function test_unknown_class_type_is_rejected(): void
    {
        $token = $this->directorToken(1);
        $this->getJson('/api/v1/student-classes?class_type=bogus', $this->headers($token))->assertStatus(422);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function directorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'dir-ctf-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function course(string $classType): StudentClass
    {
        $student = Student::create([
            'name' => '類型篩選測試生-' . uniqid(),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => $classType,
            'UsedSessions' => 0,
        ]);
    }
}
