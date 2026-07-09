<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoursePackageTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-pkg-' . uniqid() . '@test.com',
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
            'LoginName' => 'teacher-' . uniqid() . '@test.com',
            'Name'      => '老師' . uniqid(),
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
    }

    private function makePackage(int $studentId, int $campusId = 1, int $total = 24): CoursePackage
    {
        return CoursePackage::create([
            'student_id'         => $studentId,
            'campus_id'          => $campusId,
            'name'               => "測試方案{$total}堂",
            'total_sessions'     => $total,
            'remaining_sessions' => $total,
            'used_sessions'      => 0,
            'rate'               => 500,
            'rate_unit'          => 'session',
            'class_type'         => 'one_on_one',
            'paid'               => false,
            'stop'               => false,
            'enabled'            => true,
        ]);
    }

    private function makePackageMember(CoursePackage $pkg, int $studentId, int $teacherId, int $subjectId = 1): StudentClass
    {
        return StudentClass::create([
            'StudentID'            => $studentId,
            'GradeID'              => 1,
            'SubjectID'            => $subjectId,
            'TeacherID'            => $teacherId,
            'by1'                  => 1,
            'Period'               => 4,
            'StartDate'            => Carbon::today()->toDateString(),
            'TotalHours'           => 0,
            'ScheduleMode'         => 'count',
            'SessionCount'         => $pkg->total_sessions,
            'RemainingSessions'    => $pkg->total_sessions,
            'UsedSessions'         => 0,
            'SessionDuration'      => 120,
            'ClassType'            => 'one_on_one',
            'Rate'                 => 500,
            'rate_unit'            => 'session',
            'Charge'               => 0,
            'Pay'                  => 0,
            'Paid'                 => 0,
            'Stop'                 => 0,
            'PackageID'            => $pkg->id,
            'PackageTotalSessions' => $pkg->total_sessions,
            'PackageName'          => $pkg->name,
        ]);
    }

    private function makePlainCourse(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID'         => $studentId,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => $teacherId,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => Carbon::today()->toDateString(),
            'TotalHours'        => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => 10,
            'RemainingSessions' => 8,
            'UsedSessions'      => 2,
            'SessionDuration'   => 120,
            'ClassType'         => 'one_on_one',
            'Rate'              => 500,
            'rate_unit'         => 'session',
            'Charge'            => 0,
            'Pay'               => 0,
            'Paid'              => 0,
            'Stop'              => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }

    private function makeSession(int $scId, string $date, string $status = 'scheduled'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => $date,
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
            'Status'         => $status,
        ]);
    }

    // ─── Test: Create Package via API ─────────────────────

    public function test_create_multi_subject_package(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();

        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '國英24堂',
            'total_sessions' => 24,
            'rate'           => 500,
            'class_type'     => 'one_on_one',
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $teacher1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $teacher2->id, 'duration_hours' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertArrayHasKey('package_id', $json);
        $this->assertCount(2, $json['members']);

        $pkg = CoursePackage::find($json['package_id']);
        $this->assertNotNull($pkg);
        $this->assertEquals(24, $pkg->total_sessions);
        $this->assertEquals(24, $pkg->remaining_sessions);

        $members = StudentClass::where('PackageID', $pkg->id)->get();
        $this->assertCount(2, $members);
        foreach ($members as $m) {
            $this->assertEquals($pkg->id, (int) $m->PackageID);
            $this->assertEquals(24, (int) $m->PackageTotalSessions);
        }
    }

    public function test_count_package_tuition_alert_uses_package_amount_and_single_anchor_row(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();
        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => 1,
            'name' => '數學理化12堂',
            'billing_mode' => CoursePackage::BILLING_MODE_SESSION,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'used_sessions' => 0,
            'rate' => 1000,
            'rate_unit' => 'session',
            'class_type' => 'one_on_three',
            'paid' => false,
            'stop' => false,
            'enabled' => true,
        ]);
        $math = $this->makePackageMember($pkg, $student->id, $teacher1->id, 1);
        $science = $this->makePackageMember($pkg, $student->id, $teacher2->id, 2);
        $math->update(['RemainingSessions' => 2, 'SessionCount' => 12, 'Rate' => 1000, 'Charge' => 2000]);
        $science->update(['RemainingSessions' => 10, 'SessionCount' => 12, 'Rate' => 1000, 'Charge' => 2000]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $rows = collect($res->json());
        $packageRows = $rows->where('package_id', $pkg->id)->values();

        $this->assertCount(1, $packageRows);
        $this->assertSame((int) $math->ID, (int) $packageRows[0]['id']);
        $this->assertSame('數學理化12堂', $packageRows[0]['subject']);
        $this->assertSame(12, (int) $packageRows[0]['sessions_purchased']);
        $this->assertSame(12, (int) $packageRows[0]['remaining_sessions']);
        $this->assertSame(12000, (int) $packageRows[0]['charge']);
        $this->assertSame(12000, (int) $packageRows[0]['outstanding']);
        $this->assertFalse($rows->contains(fn ($row) => (int) ($row['id'] ?? 0) === (int) $science->ID));
    }

    // ─── Test: Package Deduction Cross-Subject ────────────

    public function test_cross_subject_deduction_decrements_package_remaining(): void
    {
        $student = $this->makeStudent();
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 24);
        $sc1 = $this->makePackageMember($pkg, $student->id, $teacher1->id, 1);
        $sc2 = $this->makePackageMember($pkg, $student->id, $teacher2->id, 2);

        $session1 = $this->makeSession($sc1->ID, '2026-04-16', 'attended');
        $session2 = $this->makeSession($sc2->ID, '2026-04-17', 'attended');
        $session3 = $this->makeSession($sc1->ID, '2026-04-18', 'attended');

        PackageDeductionService::deductForSession($pkg->id, $sc1->ID, $session1->id, 'attendance');
        PackageDeductionService::deductForSession($pkg->id, $sc2->ID, $session2->id, 'attendance');
        PackageDeductionService::deductForSession($pkg->id, $sc1->ID, $session3->id, 'attendance');

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();

        $this->assertEquals(21, $pkg->remaining_sessions);
        $this->assertEquals(3, $pkg->used_sessions);
    }

    // ─── Test: Idempotent Deduction ──────────────────────

    public function test_idempotent_deduction_prevents_double_deduct(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 10);
        $sc = $this->makePackageMember($pkg, $student->id, $teacher->id, 1);
        $session = $this->makeSession($sc->ID, '2026-04-16', 'attended');

        $first  = PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        $second = PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->assertEquals(1, PackageSessionLedger::where('package_id', $pkg->id)->count());

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertEquals(9, $pkg->remaining_sessions);
    }

    // ─── Test: Reverse (Leave) Restores Package Remaining ─

    public function test_leave_reverse_restores_package_balance(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 10);
        $sc = $this->makePackageMember($pkg, $student->id, $teacher->id);
        $session = $this->makeSession($sc->ID, '2026-04-16', 'attended');

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertEquals(9, $pkg->remaining_sessions);

        PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave');
        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertEquals(10, $pkg->remaining_sessions);
    }

    // ─── Test: SessionDeductionService Hooks Into Package ──

    public function test_session_deduction_service_syncs_package_on_deduct(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 10);
        $sc = $this->makePackageMember($pkg, $student->id, $teacher->id);
        $session = $this->makeSession($sc->ID, '2026-04-16', 'attended');

        SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');

        $ledgerCount = PackageSessionLedger::where('package_id', $pkg->id)
            ->where('class_session_id', $session->id)
            ->count();
        $this->assertEquals(1, $ledgerCount);

        $pkg->refresh();
        $this->assertEquals(9, $pkg->remaining_sessions);
    }

    // ─── Test: List Packages API with Branch Isolation ────

    public function test_list_packages_filtered_by_branch(): void
    {
        $token = $this->createDirectorToken([1]);
        $student1 = $this->makeStudent(1);
        $student2 = $this->makeStudent(2);

        $pkg1 = $this->makePackage($student1->id, 1, 10);
        $pkg2 = $this->makePackage($student2->id, 2, 10);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/course-packages?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($pkg1->id, $ids);
        $this->assertNotContains($pkg2->id, $ids);
    }

    // ─── Test: Cross-Branch Access Forbidden ─────────────

    public function test_cross_branch_access_is_forbidden(): void
    {
        $token = $this->createDirectorToken([1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/course-packages?branch_id=999');

        $res->assertStatus(403);
    }

    // ─── Test: Show Package Detail ───────────────────────

    public function test_show_package_detail_with_per_subject_used(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 24);
        $sc1 = $this->makePackageMember($pkg, $student->id, $teacher1->id, 1);
        $sc2 = $this->makePackageMember($pkg, $student->id, $teacher2->id, 2);

        $s1 = $this->makeSession($sc1->ID, '2026-04-16', 'attended');
        $s2 = $this->makeSession($sc1->ID, '2026-04-17', 'attended');
        $s3 = $this->makeSession($sc2->ID, '2026-04-18', 'attended');

        PackageDeductionService::deductForSession($pkg->id, $sc1->ID, $s1->id, 'attendance');
        PackageDeductionService::deductForSession($pkg->id, $sc1->ID, $s2->id, 'attendance');
        PackageDeductionService::deductForSession($pkg->id, $sc2->ID, $s3->id, 'attendance');
        PackageDeductionService::recomputeCounters($pkg->id);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/course-packages/{$pkg->id}");

        $res->assertOk();
        $json = $res->json();
        $this->assertEquals(21, $json['remaining_sessions']);
        $this->assertEquals(3, $json['used_sessions']);

        $members = collect($json['members']);
        $sc1Used = $members->firstWhere('student_class_id', $sc1->ID)['subject_used'];
        $sc2Used = $members->firstWhere('student_class_id', $sc2->ID)['subject_used'];
        $this->assertEquals(2, $sc1Used);
        $this->assertEquals(1, $sc2Used);
    }

    // ─── Test: Recompute API ─────────────────────────────

    public function test_recompute_fixes_stale_remaining(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 10);
        $sc = $this->makePackageMember($pkg, $student->id, $teacher->id);
        $session = $this->makeSession($sc->ID, '2026-04-16', 'attended');

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');

        // Manually corrupt the cached counter
        $pkg->remaining_sessions = 999;
        $pkg->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/course-packages/{$pkg->id}/recompute");

        $res->assertOk();
        $this->assertEquals(9, $res->json('remaining_sessions'));
    }

    // ─── Test: Bind Courses with Dry-Run ─────────────────

    public function test_bind_courses_dry_run_then_execute(): void
    {
        $user = User::create([
            'LoginName' => 'sa-pkg@test.com',
            'Name'      => 'SA',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000001,
            'status'    => 'active',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $student = $this->makeStudent(1);
        $pkg = $this->makePackage($student->id, 1, 20);

        $sc = StudentClass::create([
            'StudentID'        => $student->id,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 1,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => now()->toDateString(),
            'TotalHours'       => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 10,
            'RemainingSessions' => 8,
            'UsedSessions'     => 2,
            'SessionDuration'  => 120,
            'ClassType'        => 'one_on_one',
            'Rate'             => 500,
            'rate_unit'        => 'session',
            'Charge'           => 0,
            'Pay'              => 0,
            'Paid'             => 0,
            'Stop'             => 0,
        ]);

        // Dry-run first
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", [
            'student_class_ids' => [$sc->ID],
            'dry_run'           => true,
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('dry_run'));
        $sc->refresh();
        $this->assertNull($sc->PackageID);

        // Execute
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", [
            'student_class_ids' => [$sc->ID],
        ]);

        $res->assertOk();
        $sc->refresh();
        $this->assertEquals($pkg->id, (int) $sc->PackageID);
    }

    public function test_bind_courses_rejects_cross_student_cross_campus_monthly_and_stopped_courses(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $otherStudent = $this->makeStudent(1);
        $otherCampusStudent = $this->makeStudent(2);
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 20);

        $valid = $this->makePlainCourse($student->id, $teacher->id);
        $crossStudent = $this->makePlainCourse($otherStudent->id, $teacher->id);
        $crossCampus = $this->makePlainCourse($otherCampusStudent->id, $teacher->id);
        $monthly = $this->makePlainCourse($student->id, $teacher->id, ['ScheduleMode' => 'date']);
        $stopped = $this->makePlainCourse($student->id, $teacher->id, ['Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", [
            'student_class_ids' => [
                $valid->ID,
                $crossStudent->ID,
                $crossCampus->ID,
                $monthly->ID,
                $stopped->ID,
            ],
            'dry_run' => true,
        ]);

        $res->assertStatus(422);
        $res->assertJsonStructure(['message', 'blocked']);
        $blockedIds = collect($res->json('blocked'))->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $crossStudent->ID, $blockedIds);
        $this->assertContains((int) $crossCampus->ID, $blockedIds);
        $this->assertContains((int) $monthly->ID, $blockedIds);
        $this->assertContains((int) $stopped->ID, $blockedIds);

        foreach ([$valid, $crossStudent, $crossCampus, $monthly, $stopped] as $course) {
            $course->refresh();
            $this->assertNull($course->PackageID, 'Dry-run rejection must not bind any course');
        }
    }

    public function test_bind_courses_rejects_other_campus_director(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->makeStudent(1);
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 20);
        $course = $this->makePlainCourse($student->id, $teacher->id);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", [
            'student_class_ids' => [$course->ID],
            'dry_run' => true,
        ]);

        $res->assertStatus(403);
        $course->refresh();
        $this->assertNull($course->PackageID);
    }

    // ─── Test: Alert Rules Unchanged for Package Members ──

    public function test_tuition_alerts_still_fire_for_package_members(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage($student->id, 1, 4);
        $sc = $this->makePackageMember($pkg, $student->id, $teacher->id);
        $sc->RemainingSessions = 1;
        $sc->Paid = 0;
        $sc->Charge = 2000; // 4 sessions × 500/session — required for alert to pass charge > 0 filter
        $sc->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $sc->ID, $ids);
    }

    // ─── Test: Update Package ────────────────────────────

    public function test_update_package_metadata(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $pkg = $this->makePackage($student->id, 1, 24);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/course-packages/{$pkg->id}", [
            'name' => '新名稱',
            'paid' => true,
        ]);

        $res->assertOk();
        $pkg->refresh();
        $this->assertEquals('新名稱', $pkg->name);
        $this->assertTrue($pkg->paid);
    }

    // ─── Test: subject_name string resolution ──────────────

    public function test_create_multi_subject_with_subject_name_string(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher = $this->makeTeacher();

        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '數學方案',
            'total_sessions' => 10,
            'rate'           => 600,
            'subjects'       => [
                [
                    'subject_name'    => 'Math',
                    'teacher_id'      => $teacher->id,
                    'duration_hours'  => 2,
                    'start_date'      => '2026-04-01',
                    'confirmed_dates' => ['2026-04-01', '2026-04-08'],
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertCount(1, $json['members']);
        $this->assertEquals(2, $json['members'][0]['confirmed_count']);

        $pkg = CoursePackage::find($json['package_id']);
        $this->assertNotNull($pkg);
        $this->assertEquals(10, $pkg->total_sessions);
        $this->assertEquals(8, $pkg->remaining_sessions);
        $this->assertEquals(2, $pkg->used_sessions);

        $sc = StudentClass::where('PackageID', $pkg->id)->first();
        $this->assertStringStartsWith('2026-04-01', (string) $sc->StartDate);
        $this->assertEquals(2, ClassSession::where('StudentClassID', $sc->ID)->where('Status', 'attended')->count());
    }

    public function test_create_multi_subject_unknown_subject_name_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $teacher = $this->makeTeacher();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '未知科目方案',
            'total_sessions' => 5,
            'rate'           => 300,
            'subjects'       => [
                ['subject_name' => 'UnknownSubject999', 'teacher_id' => $teacher->id],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('無法解析科目', $res->json('message'));
    }
}
