<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 多科共用方案月結制 + end_date 自動預排 ClassSession。
 *
 * FR-006: createMultiSubject 月結路徑補排課欄位 + buildSessionsFromWeeklySchedule。
 */
class CoursePackageMonthlyScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-23 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-pkg-ms-' . uniqid() . '@test.com',
            'Name'      => '主任',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000000,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function makeStudent(int $campusId = 1): Student
    {
        return Student::create([
            'name'         => '方案月結生' . uniqid(),
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-ms-' . uniqid() . '@test.com',
            'Name'      => '老師' . uniqid(),
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
    }

    private function ensureSubjects(): void
    {
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    /**
     * FR-006: 多科月結 + end_date → 各科 ClassSession 自動生成。
     * 數學週二, 英文週四, 2026/05/01 ~ 2026/06/30.
     */
    public function test_monthly_package_with_end_date_generates_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent();
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '月結排課方案',
            'payment_type'   => 'monthly',
            'rate'           => 600,
            'settlement_day' => 5,
            'class_type'     => 'one_on_one',
            'end_date'       => '2026-06-30',
            'subjects'       => [
                [
                    'subject_id'     => 1,
                    'teacher_id'     => $t1->id,
                    'duration_hours' => 2,
                    'start_date'     => '2026-05-01',
                    'days_of_week'   => [2],
                    'start_time'     => '16:00',
                ],
                [
                    'subject_id'     => 2,
                    'teacher_id'     => $t2->id,
                    'duration_hours' => 2,
                    'start_date'     => '2026-05-01',
                    'days_of_week'   => [4],
                    'start_time'     => '14:00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $pkgId = $response->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $this->assertCount(2, $members);

        $membersScheduled = $response->json('members_scheduled');
        $this->assertNotNull($membersScheduled);
        $this->assertCount(2, $membersScheduled);

        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            $this->assertGreaterThan(0, $count, "Member {$m->ID} (SubjectID={$m->SubjectID}) should have ClassSessions");
        }

        foreach ($membersScheduled as $ms) {
            $this->assertGreaterThan(0, $ms['scheduled_count']);
            $this->assertNotNull($ms['first_session_date']);
            $this->assertTrue((bool) $ms['has_schedule']);
        }
    }

    /**
     * 回歸：月結方案無 end_date 時行為不變（不生成 ClassSession）。
     */
    public function test_monthly_package_without_end_date_no_auto_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent();
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '月結無排課方案',
            'payment_type'   => 'monthly',
            'rate'           => 600,
            'settlement_day' => 5,
            'class_type'     => 'one_on_one',
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
            ],
        ]);

        $response->assertStatus(201);

        $pkgId = $response->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled'])
                ->count();
            $this->assertEquals(0, $count, "No end_date should mean 0 auto-sessions for monthly");
        }
    }

    /**
     * NFR-004: end_date 超過 2 年 → 422。
     */
    public function test_monthly_package_end_date_exceeding_2_years_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent();
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '月結過長方案',
            'payment_type'   => 'monthly',
            'rate'           => 600,
            'settlement_day' => 5,
            'class_type'     => 'one_on_one',
            'end_date'       => '2029-01-01',
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2, 'start_date' => '2026-05-01', 'days_of_week' => [2], 'start_time' => '16:00'],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2, 'start_date' => '2026-05-01', 'days_of_week' => [4], 'start_time' => '14:00'],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * 回歸：堂數制方案行為不受影響。
     */
    public function test_count_mode_package_unchanged(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent();
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '堂數制排課方案',
            'total_sessions' => 8,
            'rate'           => 500,
            'class_type'     => 'one_on_one',
            'subjects'       => [
                [
                    'subject_id'     => 1,
                    'teacher_id'     => $t1->id,
                    'duration_hours' => 2,
                    'days_of_week'   => [1],
                    'start_time'     => '16:00',
                ],
                [
                    'subject_id'     => 2,
                    'teacher_id'     => $t2->id,
                    'duration_hours' => 2,
                    'days_of_week'   => [3],
                    'start_time'     => '14:00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $pkgId = $response->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $this->assertCount(2, $members);

        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            $this->assertEquals(8, $count, 'Count mode: each member should have 8 sessions');
        }
    }
}
