<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Founder 2026-11-01 (choice A): check-in past a shared pool still works and says to renew. */
class SharedPoolCheckInNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_says_when_the_shared_pool_is_used_up_and_then_over(): void
    {
        $user = User::create(['LoginName' => 'dir-pool-' . uniqid() . '@test.com', 'Name' => '主任', 'PSW' => 'x', 'type' => 'A', 'phone' => 900000000]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $teacher = User::create(['LoginName' => 't-pool-' . uniqid() . '@test.com', 'Name' => '老師', 'PSW' => 'x', 'type' => 'T', 'phone' => 911000000]);
        $student = Student::create(['name' => '共用學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
        ]);
        $api = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        $pkgId = $api->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '共用方案', 'total_sessions' => 2, 'rate' => 500,
            'subjects' => [
                ['subject_id' => 1, 'teacher_id' => $teacher->id, 'duration_hours' => 1],
                ['subject_id' => 2, 'teacher_id' => $teacher->id, 'duration_hours' => 1],
            ],
        ])->assertStatus(201)->json('package_id');
        [$math, $english] = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get()->all();

        $checkIn = function (StudentClass $member, string $start) use ($api, $student, $teacher) {
            $session = ClassSession::create([
                'StudentClassID' => $member->ID, 'SessionDate' => now()->subDay()->toDateString(),
                'StartTime' => $start, 'EndTime' => substr($start, 0, 2) + 1 . ':00', 'Status' => 'scheduled',
            ]);

            return $api->postJson('/api/v1/attendance', [
                'StudentID' => $student->id, 'StudentClassID' => $member->ID, 'TeacherID' => $teacher->id,
                'ClassSessionID' => $session->id, 'Status' => 'present',
            ])->assertCreated()->json('package_notice');
        };

        $this->assertNull($checkIn($math, '10:00'));
        $this->assertSame('共用方案堂數已用完，請安排續課。', $checkIn($english, '12:00'));
        $this->assertSame('共用方案已超出 1 堂，請先續課。', $checkIn($math, '14:00'));
    }
}
