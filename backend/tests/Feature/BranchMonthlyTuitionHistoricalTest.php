<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: 歷史課程（Stop=1）當月若有出席堂次，仍須計入「當月學收」報表。
 *
 * 對應 PRD：.cursor/plans/歷史課程排除學收修正_0ff99e77.plan.md
 * FR-001 / FR-002：branchMonthlyTuition 與 export 不再以 Stop=0 過濾。
 * FR-003：course 行新增 is_stopped 欄位。
 */
class BranchMonthlyTuitionHistoricalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-bmt-hist-' . uniqid() . '@test.com',
            'Name'      => '主任測試',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000001,
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

    private function makeStudent(string $name = '學生', int $campusId = 1): Student
    {
        return Student::create([
            'name'         => $name . uniqid(),
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function ensureSubjects(): void
    {
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    private function createClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'         => $studentId,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 99,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => now()->startOfMonth(),
            'EndDate'           => null,
            'TotalHours'        => 20,
            'Memo'              => null,
            'Charge'            => 6000,
            'Pay'               => null,
            'PayDate'           => null,
            'Paid'              => 1,
            'Disconunt'         => null,
            'Rate'              => 600,
            'LearnTimeID'       => null,
            'RoomID'            => 'R1',
            'MDate'             => now(),
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => 10,
            'SessionDuration'   => 120,
            'RemainingSessions' => 5,
            'ClassType'         => 'one_on_one',
            'UsedSessions'      => 0,
        ], $overrides));
    }

    private function addAttendedSessions(int $classId, Carbon $anchorMonth, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ClassSession::create([
                'StudentClassID' => $classId,
                'SessionDate'    => $anchorMonth->copy()->startOfMonth()->addDays($i)->toDateString(),
                'StartTime'      => '16:00',
                'EndTime'        => '18:00',
                'Status'         => 'attended',
            ]);
        }
    }

    /**
     * FR-001：Stop=1 的歷史課程，當月若有 attended 堂次，應出現在報表中並計入金額。
     */
    public function test_stopped_course_with_sessions_this_month_appears_in_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent('歷史學生');

        $course = $this->createClass($student->id, [
            'Stop' => 1,
            'Rate' => 600,
        ]);
        $this->addAttendedSessions((int) $course->ID, Carbon::parse('2026-04-01'), 2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $data = $res->json('data');

        $row = collect($data)->firstWhere('student_class_id', (int) $course->ID);
        $this->assertNotNull($row, '已結案課程（Stop=1）若當月有堂次，應出現在報表');
        $this->assertSame(2, (int) $row['monthly_sessions']);
        $this->assertSame(1200, (int) $row['monthly_tuition']);
        $this->assertTrue((bool) $row['is_stopped'], 'is_stopped 應為 true');
    }

    /**
     * FR-001 Edge：Stop=1 但當月無任何堂次的課程，不應出現在報表。
     */
    public function test_stopped_course_without_sessions_is_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent('歷史無堂次');

        $course = $this->createClass($student->id, ['Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids, '已結案且當月無堂次的課程不應出現');
    }

    /**
     * FR-001 Edge：Stop=1 但堂次在其他月份（跨月），查詢當月時不應出現。
     */
    public function test_stopped_course_with_sessions_in_other_month_is_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent('跨月無當月堂次');

        $course = $this->createClass($student->id, ['Stop' => 1]);
        $this->addAttendedSessions((int) $course->ID, Carbon::parse('2026-03-01'), 3);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids, '堂次不在查詢月份的歷史課程不應出現於當月報表');
    }

    /**
     * FR-003：進行中課程（Stop=0）is_stopped 應為 false；行為不變。
     */
    public function test_active_course_has_is_stopped_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent('進行中學生');

        $course = $this->createClass($student->id, ['Stop' => 0, 'Rate' => 500]);
        $this->addAttendedSessions((int) $course->ID, Carbon::parse('2026-04-01'), 3);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('student_class_id', (int) $course->ID);
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row['is_stopped']);
        $this->assertSame(3, (int) $row['monthly_sessions']);
        $this->assertSame(1500, (int) $row['monthly_tuition']);
    }

    /**
     * FR-001：summary（total_students / total_sessions / total_tuition）應包含已結案課程的貢獻。
     */
    public function test_summary_includes_stopped_course_contribution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $active = $this->makeStudent('進行中A');
        $historic = $this->makeStudent('歷史B');

        $courseA = $this->createClass($active->id, ['Stop' => 0, 'Rate' => 500]);
        $courseB = $this->createClass($historic->id, ['Stop' => 1, 'Rate' => 700]);
        $this->addAttendedSessions((int) $courseA->ID, Carbon::parse('2026-04-01'), 2); // 1000
        $this->addAttendedSessions((int) $courseB->ID, Carbon::parse('2026-04-01'), 3); // 2100

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $summary = $res->json('summary');
        $this->assertSame(2, (int) $summary['total_students']);
        $this->assertSame(5, (int) $summary['total_sessions']);
        $this->assertSame(3100, (int) $summary['total_tuition']);
    }

    /**
     * FR-002：CSV 匯出亦包含已結案課程；行數與頁面報表一致。
     */
    public function test_csv_export_includes_stopped_course(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent('CSV 歷史');

        $course = $this->createClass($student->id, ['Stop' => 1, 'Rate' => 800]);
        $this->addAttendedSessions((int) $course->ID, Carbon::parse('2026-04-01'), 2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'text/csv',
        ])->get('/api/v1/finance/branch-monthly-tuition/export?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString($student->name, $csv, 'CSV 應包含已結案學生姓名');
        $this->assertStringContainsString(',1600,', $csv, 'CSV 應包含 800 × 2 = 1600 的月學收金額');
        $this->assertStringContainsString('已結課', $csv, 'CSV 應包含「已結課」狀態標籤');
    }

    /**
     * 分校隔離回歸：其他分校的已結案課程不應出現在當前分校的報表。
     */
    public function test_stopped_course_from_other_campus_is_not_leaked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-17', 'Asia/Taipei'));
        $this->ensureSubjects();

        $token = $this->createDirectorToken([1]);
        $otherStudent = $this->makeStudent('他校學生', 2);

        $course = $this->createClass($otherStudent->id, ['Stop' => 1]);
        $this->addAttendedSessions((int) $course->ID, Carbon::parse('2026-04-01'), 2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=4');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids, '他校的歷史課程不應出現在本分校報表（分校隔離）');
    }
}
