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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 多科共用方案月結制（按堂計費版）：建立／報表／提醒／扣帳／回歸守護。
 * 月結金額 = 當月實際出席堂數總和 × 方案 rate（不分科目），
 * 並依 settlement_day 結算。
 */
class CoursePackageMonthlyBillingTest extends TestCase
{
    use RefreshDatabase;

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-pkg-mb-' . uniqid() . '@test.com',
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
            'name'         => '月結學生' . uniqid(),
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
            'LoginName' => 'teacher-mb-' . uniqid() . '@test.com',
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
            ['id' => 3, 'Subject_Name' => 'Science', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    // ─── FR-001 / FR-002：建立月結多科方案 Happy Path ─────

    public function test_create_monthly_multi_subject_package_happy_path(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '長期月結方案',
            'payment_type'   => 'monthly',
            'rate'           => 600,
            'settlement_day' => 5,
            'class_type'     => 'one_on_one',
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $pkg = CoursePackage::find($res->json('package_id'));
        $this->assertNotNull($pkg);
        $this->assertEquals(CoursePackage::BILLING_MODE_MONTHLY, $pkg->billing_mode);
        $this->assertEquals(600.0, (float) $pkg->rate);
        $this->assertEquals(5, (int) $pkg->settlement_day);
        $this->assertFalse(array_key_exists('monthly_fee', $pkg->getAttributes()));

        $members = StudentClass::where('PackageID', $pkg->id)->get();
        $this->assertCount(2, $members);
        foreach ($members as $m) {
            $this->assertEquals('date', $m->ScheduleMode);
            $this->assertEquals(0, (int) $m->Charge);
            $this->assertEquals(5, (int) $m->settlement_day);
        }
    }

    // ─── FR-002 Edge Cases ────────────────────────────────

    public function test_monthly_requires_at_least_two_subjects(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $this->ensureSubjects();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '月結單科不合法',
                'payment_type'   => 'monthly',
                'rate'           => 500,
                'settlement_day' => 5,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ],
            ]);

        $res->assertStatus(422);
    }

    public function test_monthly_rejects_invalid_rate_or_settlement_day(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $commonSubjects = [
            ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
            ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
        ];

        // rate 0
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '費率 0',
                'payment_type'   => 'monthly',
                'rate'           => 0,
                'settlement_day' => 5,
                'subjects'       => $commonSubjects,
            ]);
        $res->assertStatus(422);

        // 結算日 32
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '結算日 32',
                'payment_type'   => 'monthly',
                'rate'           => 600,
                'settlement_day' => 32,
                'subjects'       => $commonSubjects,
            ]);
        $res->assertStatus(422);

        // 缺 rate
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '缺費率',
                'payment_type'   => 'monthly',
                'settlement_day' => 5,
                'subjects'       => $commonSubjects,
            ]);
        $res->assertStatus(422);
    }

    // ─── FR-003：月結方案下出缺席扣帳仍正常 ─────────────

    public function test_monthly_package_member_attendance_writes_ledger(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '扣帳測試方案',
                'payment_type'   => 'monthly',
                'rate'           => 600,
                'settlement_day' => 10,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $res->assertStatus(201);
        $pkgId = (int) $res->json('package_id');
        $sc = StudentClass::where('PackageID', $pkgId)->first();

        $session = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate'    => Carbon::today()->toDateString(),
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
            'Status'         => 'attended',
        ]);

        $ok = PackageDeductionService::deductForSession($pkgId, $sc->ID, $session->id, 'attendance');
        $this->assertTrue($ok);

        $ledgerCount = PackageSessionLedger::where('package_id', $pkgId)
            ->where('class_session_id', $session->id)
            ->count();
        $this->assertEquals(1, $ledgerCount);
    }

    // ─── FR-005：當月學收報表以 sessions × rate 計算 ─────────────

    public function test_branch_monthly_tuition_reports_package_row_with_sessions_times_rate(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $rate = 700;
        $createRes = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '報表方案',
                'payment_type'   => 'monthly',
                'rate'           => $rate,
                'settlement_day' => 5,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $createRes->assertStatus(201);
        $pkgId = (int) $createRes->json('package_id');

        // 建立當月 3 堂 attended：第 1 科 2 堂、第 2 科 1 堂
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $today = Carbon::today();
        foreach ([[$members[0], 2], [$members[1], 1]] as [$m, $n]) {
            for ($i = 0; $i < $n; $i++) {
                ClassSession::create([
                    'StudentClassID' => $m->ID,
                    'SessionDate'    => $today->copy()->startOfMonth()->addDays($i)->toDateString(),
                    'StartTime'      => '16:00',
                    'EndTime'        => '18:00',
                    'Status'         => 'attended',
                ]);
            }
        }

        $year = (int) date('Y');
        $month = (int) date('n');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/finance/branch-monthly-tuition?branch_id=1&year={$year}&month={$month}");
        $res->assertOk();
        $data = $res->json('data');

        $packageRows = array_values(array_filter(
            $data,
            fn ($r) => ($r['row_type'] ?? '') === 'package'
        ));
        $this->assertCount(1, $packageRows, '月結多科方案應以單一方案行出現於報表');
        $this->assertEquals(3, $packageRows[0]['monthly_sessions']);
        $this->assertEquals($rate, $packageRows[0]['rate']);
        $this->assertEquals(3 * $rate, $packageRows[0]['monthly_tuition']);

        // 方案成員（Charge=0）不應以獨立一行出現
        $courseRows = array_values(array_filter(
            $data,
            fn ($r) => ($r['row_type'] ?? 'course') === 'course'
        ));
        foreach ($courseRows as $cr) {
            $this->assertNotEquals('報表方案', $cr['subject']);
        }
    }

    public function test_branch_monthly_tuition_reports_package_row_with_zero_when_no_attendance(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $createRes = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '無出席方案',
                'payment_type'   => 'monthly',
                'rate'           => 500,
                'settlement_day' => 5,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $createRes->assertStatus(201);

        $year = (int) date('Y');
        $month = (int) date('n');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/finance/branch-monthly-tuition?branch_id=1&year={$year}&month={$month}");
        $res->assertOk();
        $data = $res->json('data');
        $packageRows = array_values(array_filter($data, fn ($r) => ($r['row_type'] ?? '') === 'package'));
        $this->assertCount(1, $packageRows, '無出席仍應出現方案行（讓主任知道方案存在）');
        $this->assertEquals(0, $packageRows[0]['monthly_sessions']);
        $this->assertEquals(0, $packageRows[0]['monthly_tuition']);
    }

    // ─── FR-006：alerts/tuition 觸發月結多科方案提醒 ─────

    public function test_monthly_package_appears_in_tuition_alerts_with_sessions_times_rate(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $settlementDay = (int) Carbon::today()->day;
        $rate = 800;

        $createRes = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '提醒方案',
                'payment_type'   => 'monthly',
                'rate'           => $rate,
                'settlement_day' => $settlementDay,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $createRes->assertStatus(201);
        $pkgId = (int) $createRes->json('package_id');

        // 本月加入 4 堂出席（跨兩科）
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $today = Carbon::today();
        foreach ([[$members[0], 3], [$members[1], 1]] as [$m, $n]) {
            for ($i = 0; $i < $n; $i++) {
                ClassSession::create([
                    'StudentClassID' => $m->ID,
                    'SessionDate'    => $today->copy()->startOfMonth()->addDays($i)->toDateString(),
                    'StartTime'      => '16:00',
                    'EndTime'        => '18:00',
                    'Status'         => 'attended',
                ]);
            }
        }

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1');
        $res->assertOk();

        $rows = collect($res->json());
        $pkgRow = $rows->firstWhere('package_id', $pkgId);
        $this->assertNotNull($pkgRow, '月結多科方案應出現於 alerts/tuition');
        $this->assertEquals('monthly_due_soon', $pkgRow['alert_type']);
        $this->assertEquals(4, $pkgRow['monthly_sessions']);
        $this->assertEquals($rate, (int) $pkgRow['rate']);
        $this->assertEquals(4 * $rate, $pkgRow['charge']);
        $this->assertEquals(4 * $rate, $pkgRow['outstanding']);
        $this->assertArrayNotHasKey('monthly_fee', $pkgRow);

        // 成員（Charge=0）不應以獨立行重複出現
        $memberScIds = StudentClass::where('PackageID', $pkgId)->pluck('ID')->all();
        $memberRows = $rows->whereIn('id', $memberScIds);
        $this->assertCount(0, $memberRows, '月結方案成員（Charge=0）不應出現在提醒（已由方案層代為顯示）');
    }

    // ─── 回歸：堂數制多科方案仍然以原有邏輯運作 ──────────

    public function test_count_mode_multi_subject_package_unchanged(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '堂數制回歸',
                'total_sessions' => 24,
                'rate'           => 500,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $res->assertStatus(201);
        $pkg = CoursePackage::find($res->json('package_id'));
        $this->assertSame('count', $pkg->billing_mode);
        $this->assertNull($pkg->settlement_day);
        $this->assertEquals(24, $pkg->total_sessions);

        $members = StudentClass::where('PackageID', $pkg->id)->get();
        foreach ($members as $m) {
            $this->assertEquals('count', $m->ScheduleMode);
            $this->assertEquals(24, (int) $m->SessionCount);
        }
    }

    // ─── 回歸：update 同步 settlement_day / rate 到成員 ─────────

    public function test_update_monthly_package_cascades_settlement_day_and_rate_to_members(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->ensureSubjects();

        $createRes = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id'     => $student->id,
                'branch_id'      => 1,
                'name'           => '可修改方案',
                'payment_type'   => 'monthly',
                'rate'           => 600,
                'settlement_day' => 5,
                'subjects'       => [
                    ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                    ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
                ],
            ]);
        $pkgId = (int) $createRes->json('package_id');

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", [
                'settlement_day' => 20,
                'rate'           => 800,
            ])->assertOk();

        $pkg = CoursePackage::find($pkgId);
        $this->assertEquals(20, (int) $pkg->settlement_day);
        $this->assertEquals(800.0, (float) $pkg->rate);

        $memberRows = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($memberRows as $m) {
            $this->assertEquals(20, (int) $m->settlement_day, '修改方案 settlement_day 後應 cascade 至各成員');
            $this->assertEquals(800.0, (float) $m->Rate, '修改方案 rate 後應 cascade 至各成員');
        }
    }
}
