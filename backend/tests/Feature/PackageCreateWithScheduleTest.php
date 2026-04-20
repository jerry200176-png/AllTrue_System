<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for CoursePackageController::createMultiSubject schedule wiring (PRD §FR-001~FR-003).
 *
 * Key assertions:
 *  - days_of_week (ISO 1=Mon … 7=Sun) is written VERBATIM to StudentClass.week / week1..week6
 *    (no ((int)$dow + 6) % 7 bug regression — GAP-01).
 *  - extendSessionsIfNeeded is invoked so ClassSession rows are auto-generated.
 *  - Members without schedule data produce 0 ClassSession and still return 201.
 *  - Monthly (date-billed) packages never trigger schedule extension.
 */
class PackageCreateWithScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-sched-' . uniqid() . '@test.com',
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
            'name'         => '測試學生' . uniqid(),
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
            'LoginName' => 'teacher-sched-' . uniqid() . '@test.com',
            'Name'      => '老師' . uniqid(),
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
    }

    private function seedSubjects(): void
    {
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 3, 'Subject_Name' => 'Physics', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    private function postCreate(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', $payload);
    }

    // ─── FR-001 / FR-003：含時段，自動補排 ───

    public function test_create_with_days_of_week_generates_class_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '排課方案',
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

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertArrayHasKey('members_scheduled', $json);
        $this->assertCount(2, $json['members_scheduled']);

        $pkgId = $json['package_id'];
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $this->assertCount(2, $members);

        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)
                ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                ->count();
            $this->assertEquals(8, $count, "Member {$m->ID} should have 8 ClassSession rows");
        }

        foreach ($json['members_scheduled'] as $ms) {
            $this->assertEquals(8, $ms['scheduled_count']);
            $this->assertNotNull($ms['first_session_date']);
            $this->assertTrue((bool) $ms['has_schedule']);
        }
    }

    // ─── NFR-002：選填排課時段（降級）───

    public function test_create_without_schedule_no_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '純建立無排課',
            'total_sessions' => 12,
            'rate'           => 500,
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $pkgId = $res->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)->count();
            $this->assertEquals(0, $count);
        }

        foreach ($res->json('members_scheduled') as $ms) {
            $this->assertFalse((bool) $ms['has_schedule']);
            $this->assertEquals(0, $ms['scheduled_count']);
        }
    }

    // ─── 混合：部分科有時段、部分無 ───

    public function test_create_partial_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '部分排課',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t1->id,
                    'days_of_week' => [2],
                    'start_time'   => '15:00',
                ],
                [
                    'subject_id' => 2,
                    'teacher_id' => $t2->id,
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $members = StudentClass::where('PackageID', $json['package_id'])->orderBy('ID')->get();
        $this->assertCount(2, $members);

        $withSchedule = $members->firstWhere('SubjectID', 1);
        $withoutSchedule = $members->firstWhere('SubjectID', 2);

        $this->assertEquals(6, ClassSession::where('StudentClassID', $withSchedule->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])->count());
        $this->assertEquals(0, ClassSession::where('StudentClassID', $withoutSchedule->ID)->count());
    }

    // ─── confirmed_dates + 時段：補排數 = total - confirmed ───

    public function test_create_with_confirmed_dates_plus_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $this->seedSubjects();

        $confirmed = ['2026-05-04', '2026-05-05']; // 2 已上堂
        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '含已上堂',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'     => 1,
                    'teacher_id'     => $t1->id,
                    'confirmed_dates'=> $confirmed,
                    'days_of_week'   => [1],
                    'start_time'     => '16:00',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $pkgId = $res->json('package_id');
        $member = StudentClass::where('PackageID', $pkgId)->first();

        $total = ClassSession::where('StudentClassID', $member->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])->count();
        $this->assertEquals(6, $total);

        $attended = ClassSession::where('StudentClassID', $member->ID)
            ->where('Status', 'attended')->count();
        $this->assertEquals(2, $attended);
    }

    // ─── GAP-01 迴歸：days_of_week 值原樣寫入 week 欄位（不做 mod 轉換） ───

    public function test_create_schedule_writes_week_fields(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $tMon = $this->makeTeacher();
        $tSun = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => 'GAP-01 驗證',
            'total_sessions' => 3,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $tMon->id,
                    'days_of_week' => [1],
                    'start_time'   => '16:00',
                ],
                [
                    'subject_id'   => 2,
                    'teacher_id'   => $tSun->id,
                    'days_of_week' => [7],
                    'start_time'   => '10:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $mon = StudentClass::where('PackageID', $pkgId)->where('SubjectID', 1)->first();
        $sun = StudentClass::where('PackageID', $pkgId)->where('SubjectID', 2)->first();

        // 關鍵：原本 bug 會把 1 寫成 0、7 寫成 6。
        $this->assertEquals(1, (int) $mon->week, 'Monday (1) must stay 1, not the buggy 0');
        $this->assertEquals(7, (int) $sun->week, 'Sunday (7) must stay 7, not the buggy 6');
        $this->assertStringStartsWith('16:00', (string) $mon->time);
        $this->assertStringStartsWith('10:00', (string) $sun->time);
    }

    // ─── 多日：寫入 week1 / week2 ───

    public function test_create_multi_days_writes_week1_week2(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '週一三五',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t->id,
                    'days_of_week' => [1, 3],
                    'start_time'   => '17:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $member = StudentClass::where('PackageID', $pkgId)->first();
        $this->assertEquals(1, (int) $member->week1);
        $this->assertEquals(3, (int) $member->week2);
        $this->assertStringStartsWith('17:00', (string) $member->time1);
        $this->assertStringStartsWith('17:00', (string) $member->time2);
    }

    // ─── FR-008：月結方案不補排 ───

    public function test_monthly_package_ignores_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '月結方案',
            'payment_type'   => 'monthly',
            'settlement_day' => 1,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t1->id,
                    'days_of_week' => [1],
                    'start_time'   => '16:00',
                ],
                [
                    'subject_id'   => 2,
                    'teacher_id'   => $t2->id,
                    'days_of_week' => [3],
                    'start_time'   => '14:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $pkg = CoursePackage::find($pkgId);
        $this->assertEquals('date', $pkg->billing_mode);
        $members = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)->count();
            $this->assertEquals(0, $count, '月結方案不應補排任何 ClassSession');
        }
    }
}
