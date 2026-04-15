<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageE2EFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $directorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'LoginName' => 'e2e-dir@test.com', 'Name' => '主任',
            'PSW' => 'secret', 'type' => 'A', 'phone' => 900000000,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->directorToken = $token;

        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 3, 'Subject_Name' => 'Chinese', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->directorToken}", 'Accept' => 'application/json'];
    }

    /**
     * Full E2E: create package → attend 3 different subjects → check balance → recompute
     */
    public function test_full_e2e_create_attend_check_recompute(): void
    {
        $student = Student::create([
            'name' => 'E2E學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $t1 = User::create(['LoginName' => 'e2e-t1@t.com', 'Name' => '數學老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000001]);
        $t2 = User::create(['LoginName' => 'e2e-t2@t.com', 'Name' => '英文老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000002]);
        $t3 = User::create(['LoginName' => 'e2e-t3@t.com', 'Name' => '國文老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000003]);

        // Step 1: Create package via API
        $res = $this->withHeaders($this->authHeaders())->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id' => $student->id,
            'branch_id' => 1,
            'name' => '國英數24堂方案',
            'total_sessions' => 24,
            'rate' => 500,
            'class_type' => 'one_on_one',
            'subjects' => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ['subject_id' => 3, 'teacher_id' => $t3->id, 'duration_hours' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $packageId = $res->json('package_id');
        $members = $res->json('members');
        $this->assertCount(3, $members);

        // Step 2: Simulate attendance across 3 subjects
        $scIds = collect($members)->pluck('student_class_id')->all();

        $session1 = ClassSession::create([
            'StudentClassID' => $scIds[0], 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);
        $session2 = ClassSession::create([
            'StudentClassID' => $scIds[1], 'SessionDate' => '2026-04-17',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);
        $session3 = ClassSession::create([
            'StudentClassID' => $scIds[2], 'SessionDate' => '2026-04-18',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        PackageDeductionService::deductForSession($packageId, $scIds[0], $session1->id, 'attendance');
        PackageDeductionService::deductForSession($packageId, $scIds[1], $session2->id, 'attendance');
        PackageDeductionService::deductForSession($packageId, $scIds[2], $session3->id, 'attendance');
        PackageDeductionService::recomputeCounters($packageId);

        // Step 3: Check via API
        $res = $this->withHeaders($this->authHeaders())->getJson("/api/v1/course-packages/{$packageId}");
        $res->assertOk();
        $this->assertEquals(21, $res->json('remaining_sessions'));
        $this->assertEquals(3, $res->json('used_sessions'));
        $this->assertEquals(-3, $res->json('ledger_net'));

        $memberDetails = collect($res->json('members'));
        $this->assertEquals(1, $memberDetails->firstWhere('student_class_id', $scIds[0])['subject_used']);
        $this->assertEquals(1, $memberDetails->firstWhere('student_class_id', $scIds[1])['subject_used']);
        $this->assertEquals(1, $memberDetails->firstWhere('student_class_id', $scIds[2])['subject_used']);

        // Step 4: List all packages for this branch
        $listRes = $this->withHeaders($this->authHeaders())->getJson('/api/v1/course-packages?branch_id=1');
        $listRes->assertOk();
        $found = collect($listRes->json())->firstWhere('id', $packageId);
        $this->assertNotNull($found);
        $this->assertEquals(21, $found['remaining_sessions']);

        // Step 5: Recompute
        $recomputeRes = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/course-packages/{$packageId}/recompute");
        $recomputeRes->assertOk();
        $this->assertEquals(21, $recomputeRes->json('remaining_sessions'));
    }

    /**
     * E2E: Leave reversal restores balance.
     */
    public function test_e2e_leave_reversal_restores_balance(): void
    {
        $student = Student::create([
            'name' => 'Leave學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $t = User::create(['LoginName' => 'e2e-leave-t@t.com', 'Name' => '老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000010]);

        $res = $this->withHeaders($this->authHeaders())->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id' => $student->id, 'branch_id' => 1,
            'name' => '請假測試方案', 'total_sessions' => 10, 'rate' => 500,
            'subjects' => [['subject_id' => 1, 'teacher_id' => $t->id, 'duration_hours' => 2]],
        ]);

        $res->assertStatus(201);
        $packageId = $res->json('package_id');
        $scId = $res->json('members.0.student_class_id');

        $session = ClassSession::create([
            'StudentClassID' => $scId, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        // Deduct
        PackageDeductionService::deductForSession($packageId, $scId, $session->id, 'attendance');
        PackageDeductionService::recomputeCounters($packageId);

        $pkg = CoursePackage::find($packageId);
        $this->assertEquals(9, $pkg->remaining_sessions);

        // Retro-leave reverse
        PackageDeductionService::reverseForSession($packageId, $scId, $session->id, 'retro_leave');
        PackageDeductionService::recomputeCounters($packageId);

        $pkg->refresh();
        $this->assertEquals(10, $pkg->remaining_sessions);
    }

    /**
     * E2E: Tuition alert still fires for package members with low remaining.
     */
    public function test_e2e_tuition_alert_fires_for_package_low_remaining(): void
    {
        $student = Student::create([
            'name' => 'Alert學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $t = User::create(['LoginName' => 'e2e-alert-t@t.com', 'Name' => '老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000020]);

        $res = $this->withHeaders($this->authHeaders())->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id' => $student->id, 'branch_id' => 1,
            'name' => '提醒測試', 'total_sessions' => 3, 'rate' => 500,
            'subjects' => [['subject_id' => 1, 'teacher_id' => $t->id, 'duration_hours' => 2]],
        ]);

        $res->assertStatus(201);
        $scId = $res->json('members.0.student_class_id');

        // Manually set low remaining (simulating deductions)
        StudentClass::where('ID', $scId)->update(['RemainingSessions' => 1, 'Paid' => 0]);

        $alertRes = $this->withHeaders($this->authHeaders())->getJson('/api/v1/alerts/tuition?branch_id=1');
        $alertRes->assertOk();
        $ids = collect($alertRes->json())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($scId, $ids, 'Package member with low remaining should appear in tuition alerts');
    }
}
