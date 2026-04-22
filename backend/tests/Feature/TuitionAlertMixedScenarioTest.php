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
 * 缺口 3：AlertController 混合情境守護。
 *
 * 背景：AlertController::tuition 同時處理三類資料：
 *  (a) 計次制 StudentClass (ScheduleMode=count)
 *  (b) 月結制 StudentClass (ScheduleMode=date, 個別)
 *  (c) 月結多科方案 CoursePackage (billing_mode=monthly)
 *
 * 過去已修復的 bug：CoursePackage 月結已付款仍顯示 payment_status='unpaid'。
 * 此測試組確保在混合情境下所有修復都同時正確：
 *
 *  - MX-001：同一學生有「計次制課程」+「月結方案」→ 兩者都出現在提醒列表
 *  - MX-002：月結方案已付款，同時有未付計次制課程 → 方案 payment_status='monthly_due_soon'，
 *             計次制 payment_status='unpaid'（不互相干擾）
 *  - MX-003：月結方案未付款，計次制已付款 → 方案 outstanding > 0，計次制 outstanding = 0
 *  - MX-004：月結方案成員課程不出現在個人月結提醒裡（避免重複計費）
 *  - MX-005：同一學生同時有多個計次制課程（不同科目）都出現在提醒中
 */
class TuitionAlertMixedScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── MX-001：計次制 + 月結方案 → 兩者都出現在提醒列表 ──────────────────

    public function test_count_course_and_monthly_package_both_appear_in_alerts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Taipei'));

        $token   = $this->makeDirectorToken();
        $student = $this->makeStudent();

        // (a) 計次制課程（未付款）
        $countCourse = $this->makeCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 1]);

        // (b) 月結方案（未付款，settlement_day=5，目前 2026-05-07 > 5 → 逾期 → 應顯示）
        $pkg = $this->makeMonthlyPackage($student->id, ['settlement_day' => 5, 'paid' => false]);

        $res = $this->getAlerts($token);
        $res->assertOk();

        $data = collect($res->json());

        // 計次制課程應出現
        $countIds = $data->where('schedule_mode', 'count')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $countCourse->ID, $countIds, '計次制課程應出現在催繳提醒');

        // 月結方案應出現
        $pkgRow = $data->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($pkgRow, '月結方案應出現在催繳提醒');
    }

    // ── MX-002：方案已付 + 計次制未付 → payment_status 各自獨立正確 ────────

    public function test_paid_package_and_unpaid_count_course_have_correct_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Taipei'));

        $token   = $this->makeDirectorToken('dir-mx2@test.com');
        $student = $this->makeStudent();

        // 計次制：未付款
        $countCourse = $this->makeCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 1]);

        // 月結方案：本月已付款，settlement_day=15，距到期 8 天 → 在 5 天窗口外，不顯示
        // 所以用 settlement_day=5（逾期）確保方案顯示，但標記已付
        $pkg = $this->makeMonthlyPackage($student->id, [
            'settlement_day' => 5,
            'paid'           => true,
            'paid_at'        => '2026-05-05 10:00:00',
        ]);

        $res = $this->getAlerts($token);
        $res->assertOk();

        $data = collect($res->json());

        // 月結方案 → payment_status 應為 monthly_due_soon（已付款），outstanding = 0
        $pkgRow = $data->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($pkgRow, '已付款月結方案應出現在提醒（next due soon）');
        $this->assertSame('monthly_due_soon', $pkgRow['payment_status'],
            '已付款月結方案的 payment_status 應為 monthly_due_soon，不是 unpaid（已修 bug 的回歸驗證）');
        $this->assertSame(0, (int) $pkgRow['outstanding'], '已付款月結方案的 outstanding 應為 0');
        $this->assertTrue((bool) $pkgRow['paid'], '已付款月結方案的 paid 應為 true');

        // 計次制課程 → payment_status 應為 unpaid
        $countRow = $data->firstWhere('id', (int) $countCourse->ID);
        $this->assertNotNull($countRow, '計次制課程應出現在提醒');
        $this->assertSame('unpaid', $countRow['payment_status'],
            '未付計次制課程的 payment_status 應為 unpaid');
        $this->assertGreaterThan(0, (int) $countRow['outstanding'], '計次制課程的 outstanding 應 > 0');
    }

    // ── MX-003：方案未付 + 計次制已付 → outstanding 各自正確 ────────────────

    public function test_unpaid_package_and_paid_count_course_outstanding_are_independent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Taipei'));

        $token   = $this->makeDirectorToken('dir-mx3@test.com');
        $student = $this->makeStudent();

        // 計次制：已付款，剩餘堂數 <= 2 所以仍出現在提醒
        $countCourse = $this->makeCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 1]);

        // 月結方案：未付款（逾期）
        $pkg = $this->makeMonthlyPackage($student->id, [
            'settlement_day' => 5,
            'paid'           => false,
        ]);

        // 本月已出席 3 堂 → charge = 3 * rate
        $this->simulateAttendedSessions($student->id, $pkg->id, 3);

        $res = $this->getAlerts($token);
        $res->assertOk();

        $data = collect($res->json());

        // 月結方案 → outstanding > 0，payment_status = unpaid
        $pkgRow = $data->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($pkgRow, '未付款月結方案應出現在提醒');
        $this->assertSame('unpaid', $pkgRow['payment_status'], '未付款月結方案 payment_status 應為 unpaid');
        $this->assertGreaterThan(0, (float) $pkgRow['outstanding'], '未付款月結方案 outstanding 應 > 0');

        // 計次制：已付款 → outstanding = 0
        $countRow = $data->firstWhere('id', (int) $countCourse->ID);
        if ($countRow !== null) { // 可能因 Charge=0 被過濾
            $this->assertSame(0, (int) $countRow['outstanding'], '已付計次制課程 outstanding 應為 0');
        }
    }

    // ── MX-004：方案成員課程不出現在個人月結提醒（避免重複）────────────────

    public function test_package_member_courses_excluded_from_individual_date_mode_alerts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Taipei'));

        $token   = $this->makeDirectorToken('dir-mx4@test.com');
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();

        // 建立月結方案，成員課程為 date-mode StudentClass
        $pkg = $this->makeMonthlyPackage($student->id, ['settlement_day' => 5, 'paid' => false]);

        // 直接建立成員 StudentClass（模擬方案成員）
        $memberSc = StudentClass::create([
            'StudentID'       => $student->id,
            'GradeID'         => 1,
            'SubjectID'       => 1,
            'TeacherID'       => $teacher->id,
            'by1'             => 1,
            'Period'          => 4,
            'StartDate'       => now(),
            'EndDate'         => now()->addMonth(),
            'TotalHours'      => 20,
            'Charge'          => 0,
            'Paid'            => 0,
            'RoomID'          => 'R1',
            'MDate'           => now(),
            'Stop'            => 0,
            'ScheduleMode'    => 'date',
            'SessionCount'    => 0,
            'SessionDuration' => 120,
            'RemainingSessions'=> 0,
            'ClassType'       => 'one_on_one',
            'UsedSessions'    => 0,
            'settlement_day'  => 5,
            'PackageID'       => $pkg->id,  // 歸屬方案
        ]);

        $res = $this->getAlerts($token);
        $res->assertOk();

        $data = collect($res->json());

        // 方案成員的 StudentClass.ID 不應出現在個人 id 列表中
        $individualIds = $data->whereNull('package_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $memberSc->ID, $individualIds,
            '方案成員課程不應出現在個人月結提醒（否則同一學生被計費兩次）');

        // 但方案本身應出現
        $pkgRow = $data->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($pkgRow, '月結方案本身應出現在提醒');
    }

    // ── MX-005：同一學生多個計次制科目都在提醒中 ─────────────────────────

    public function test_multiple_count_mode_subjects_for_same_student_all_appear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Taipei'));

        $token   = $this->makeDirectorToken('dir-mx5@test.com');
        $student = $this->makeStudent();

        $mathCourse = $this->makeCountModeClass($student->id, [
            'SubjectID'        => 1,
            'Paid'             => 0,
            'RemainingSessions'=> 1,
            'Charge'           => 3000,
        ]);
        $engCourse = $this->makeCountModeClass($student->id, [
            'SubjectID'        => 2,
            'Paid'             => 0,
            'RemainingSessions'=> 2,
            'Charge'           => 3000,
        ]);

        $res = $this->getAlerts($token);
        $res->assertOk();

        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $mathCourse->ID, $ids, '數學計次制課程應出現在提醒');
        $this->assertContains((int) $engCourse->ID, $ids, '英文計次制課程應出現在提醒');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function getAlerts(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');
    }

    private function makeDirectorToken(string $login = 'dir-mx@test.com'): string
    {
        $user = User::create([
            'LoginName'          => $login,
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => 900000000,
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName'          => 'teacher-mx-' . uniqid() . '@test.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => 911000000,
            'MustChangePassword' => false,
        ]);
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name'         => '混合學生' . uniqid(),
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => now(),
            'EndDate'          => null,
            'TotalHours'       => 20,
            'Memo'             => null,
            'Charge'           => 5000,
            'Pay'              => null,
            'PayDate'          => null,
            'Paid'             => 0,
            'Disconunt'        => null,
            'Rate'             => null,
            'LearnTimeID'      => null,
            'RoomID'           => 'R1',
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 10,
            'SessionDuration'  => 120,
            'RemainingSessions'=> 5,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
        ], $overrides));
    }

    private function makeMonthlyPackage(int $studentId, array $overrides = []): CoursePackage
    {
        return CoursePackage::create(array_merge([
            'student_id'     => $studentId,
            'campus_id'      => 1,
            'name'           => '月結方案' . uniqid(),
            'billing_mode'   => CoursePackage::BILLING_MODE_MONTHLY,
            'payment_type'   => 'monthly',
            'rate'           => 500,
            'settlement_day' => 5,
            'stop'           => false,
            'paid'           => false,
            'paid_at'        => null,
        ], $overrides));
    }

    /**
     * 模擬一個學生本月已出席 N 堂（寫入 ClassSession 到方案成員的 StudentClass 裡）。
     */
    private function simulateAttendedSessions(int $studentId, int $packageId, int $count): void
    {
        // Find or create a member StudentClass for this package
        $memberSc = StudentClass::where('StudentID', $studentId)
            ->where('PackageID', $packageId)
            ->first();

        if (!$memberSc) {
            $memberSc = StudentClass::create([
                'StudentID'       => $studentId,
                'GradeID'         => 1,
                'SubjectID'       => 1,
                'TeacherID'       => 99,
                'by1'             => 1,
                'Period'          => 4,
                'StartDate'       => now()->startOfMonth(),
                'EndDate'         => now()->endOfMonth(),
                'TotalHours'      => 20,
                'Charge'          => 0,
                'Paid'            => 0,
                'RoomID'          => 'R1',
                'MDate'           => now(),
                'Stop'            => 0,
                'ScheduleMode'    => 'date',
                'SessionCount'    => 0,
                'SessionDuration' => 120,
                'RemainingSessions'=> 0,
                'ClassType'       => 'one_on_one',
                'UsedSessions'    => 0,
                'settlement_day'  => 5,
                'PackageID'       => $packageId,
            ]);
        }

        for ($i = 1; $i <= $count; $i++) {
            DB::table('ClassSession')->insert([
                'StudentClassID' => $memberSc->ID,
                'SessionDate'    => now()->startOfMonth()->addDays($i - 1)->toDateString(),
                'StartTime'      => '16:00',
                'EndTime'        => '18:00',
                'Status'         => 'attended',
                'Note'           => '',
            ]);
        }
    }
}
