<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-001: GET returns per-course sessions_purchased + auxiliary package fields.
 * FR-002: PUT strips RemainingSessions for package courses.
 */
class PackageDisplayAndGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function uniq(): string
    {
        return 'pkg-test-' . (++$this->seq) . '-' . uniqid();
    }

    private function makeUser(): User
    {
        $user = User::create([
            'LoginName' => $this->uniq() . '@test.com',
            'Name' => '測試主任',
            'PSW' => 'x',
            'type' => 'D',
            'phone' => 900000000 + $this->seq,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        return $user;
    }

    private function makeToken(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function authGet(User $user, string $url)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->makeToken($user),
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    private function authPut(User $user, string $url, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->makeToken($user),
            'Accept' => 'application/json',
        ])->putJson($url, $payload);
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '測試生' . $this->uniq(),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createPackageSetup(): array
    {
        $user = $this->makeUser();
        $student = $this->makeStudent();

        $pkg = CoursePackage::create([
            'student_id'         => $student->id,
            'campus_id'          => 1,
            'name'               => '共用方案' . $this->uniq(),
            'billing_mode'       => 'count',
            'total_sessions'     => 12,
            'used_sessions'      => 5,
            'remaining_sessions' => 7,
            'rate'               => 500,
            'rate_unit'          => 'session',
            'class_type'         => 'one_on_one',
            'paid'               => false,
            'stop'               => false,
            'enabled'            => true,
        ]);

        $sc = StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 1,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => now(),
            'TotalHours'        => 20,
            'Charge'            => 0,
            'Paid'              => 0,
            'RoomID'            => 'R1',
            'MDate'             => now(),
            'Stop'              => 0,
            'SessionCount'      => 6,
            'RemainingSessions' => 3,
            'UsedSessions'      => 3,
            'PackageID'         => $pkg->id,
            'ScheduleMode'      => 'count',
            'CampusID'          => 1,
        ]);

        return compact('user', 'student', 'pkg', 'sc');
    }

    public function test_get_returns_per_course_sessions_purchased(): void
    {
        $s = $this->createPackageSetup();

        $resp = $this->authGet($s['user'], '/api/v1/student-classes?branch_id=1');

        $resp->assertOk();

        $course = collect($resp->json())
            ->first(fn ($c) => ($c['ID'] ?? $c['id'] ?? null) == $s['sc']->ID);

        $this->assertNotNull($course, 'Package course should appear in list');
        $this->assertEquals(6, $course['sessions_purchased'], 'sessions_purchased = per-course SessionCount');
        $this->assertEquals(12, $course['package_total_sessions'], 'package_total_sessions injected');
        $this->assertArrayHasKey('package_remaining_sessions', $course);
        $this->assertArrayHasKey('package_used_sessions', $course);
    }

    public function test_put_strips_remaining_sessions_for_package_course(): void
    {
        $s = $this->createPackageSetup();
        $before = $s['sc']->RemainingSessions;

        $resp = $this->authPut($s['user'], "/api/v1/student-classes/{$s['sc']->ID}", [
            'remaining_sessions' => 99,
        ]);

        $resp->assertOk();
        $s['sc']->refresh();
        $this->assertEquals($before, $s['sc']->RemainingSessions, 'RemainingSessions unchanged for package course');
    }

    public function test_put_allows_remaining_sessions_for_non_package_course(): void
    {
        $user = $this->makeUser();
        $student = $this->makeStudent();

        $sc = StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 1,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => now(),
            'TotalHours'        => 20,
            'Charge'            => 0,
            'Paid'              => 0,
            'RoomID'            => 'R1',
            'MDate'             => now(),
            'Stop'              => 0,
            'SessionCount'      => 8,
            'RemainingSessions' => 5,
            'UsedSessions'      => 3,
            'PackageID'         => null,
            'ScheduleMode'      => 'count',
            'CampusID'          => 1,
        ]);

        $resp = $this->authPut($user, "/api/v1/student-classes/{$sc->ID}", [
            'remaining_sessions' => 99,
        ]);

        $resp->assertOk();
        $sc->refresh();
        $this->assertEquals(99, $sc->RemainingSessions, 'Non-package course allows RemainingSessions update');
    }
}
