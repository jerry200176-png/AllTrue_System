<?php

namespace Tests\Feature;

use App\Models\CoursePackage;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: 共用方案（course_packages）在家長入口的堂數顯示。
 *
 * BUG（in-app 家族 #158/#162）：共用方案的每筆成員 StudentClass.SessionCount 都 = 池總堂數，
 * 家長端原本 per-course 計算 → 每科都顯示總堂數、總剩餘被重複加總成數倍。
 *
 * 期望：成員課的 remaining 走「池子」；總剩餘每池只算一次；科目明細每池一筆（共用標記）。
 */
class ParentPortalSharedPackageTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '共用測試生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0911222333',
        ]);
    }

    private function makeToken(Student $student): string
    {
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);
        return $raw;
    }

    private function makePackageMember(Student $s, CoursePackage $pkg, int $subjectId): StudentClass
    {
        return StudentClass::create([
            'StudentID'         => $s->id,
            'GradeID'           => 1,
            'SubjectID'         => $subjectId,
            'TeacherID'         => 1,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => now()->toDateString(),
            'TotalHours'        => 24,
            'Charge'            => 0,
            'Paid'              => 1,
            'Rate'              => 0,
            'MDate'             => now()->toDateString(),
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            // 真實資料：成員 SessionCount = 池總堂數；per-member 計數刻意設成「錯的」值，
            // 用來證明 dashboard 不採用 per-member，而是走池子。
            'SessionCount'      => 12,
            'RemainingSessions' => 11,
            'UsedSessions'      => 1,
            'SessionDuration'   => 120,
            'ClassType'         => 'one_on_one',
            'PackageID'         => $pkg->id,
            'CampusID'          => 1,
        ]);
    }

    private function dashboard(string $token)
    {
        return $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_shared_package_members_show_pool_remaining_not_per_subject_total(): void
    {
        $s = $this->makeStudent();
        $pkg = CoursePackage::create([
            'student_id' => $s->id, 'campus_id' => 1, 'name' => '數理共用方案',
            'billing_mode' => 'count', 'total_sessions' => 12, 'used_sessions' => 5,
            'remaining_sessions' => 7, 'rate' => 0, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $this->makePackageMember($s, $pkg, 3); // 數學
        $this->makePackageMember($s, $pkg, 4); // 理化

        $res = $this->dashboard($this->makeToken($s));
        $res->assertOk();

        $classes = collect($res->json('classes'));
        $this->assertCount(2, $classes, '兩個共用成員都應顯示（池子還有剩餘）');
        foreach ($classes as $c) {
            $this->assertTrue($c['is_package'], '應標記為共用方案');
            $this->assertSame(7, $c['remaining_sessions'], '剩餘走池子(7)，非 per-member(11) 或總堂數(12)');
            $this->assertSame(12, $c['package_total_sessions']);
            $this->assertSame(7, $c['package_remaining_sessions']);
            $this->assertSame(5, $c['package_used_sessions']);
        }

        // 總剩餘：每池只算一次 = 7（不是 7+7=14）。
        $this->assertSame(7, $res->json('remaining_sessions_total'));

        // 科目明細：共用方案只有一筆（不是每科一筆都掛 12 / 7）。
        $bySubject = $res->json('remaining_by_subject');
        $this->assertCount(1, $bySubject, '共用方案在科目明細只佔一筆');
        $this->assertSame(7, array_values($bySubject)[0]);
    }

    public function test_total_adds_package_pool_once_plus_non_package_courses(): void
    {
        $s = $this->makeStudent();
        $pkg = CoursePackage::create([
            'student_id' => $s->id, 'campus_id' => 1, 'name' => '共用方案A',
            'billing_mode' => 'count', 'total_sessions' => 12, 'used_sessions' => 5,
            'remaining_sessions' => 7, 'rate' => 0, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $this->makePackageMember($s, $pkg, 3);
        $this->makePackageMember($s, $pkg, 4);

        // 一般（非共用）堂數課：英文，購買 8、已上 0 → 剩 8。
        StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 2, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->toDateString(), 'TotalHours' => 8,
            'Charge' => 0, 'Paid' => 1, 'Rate' => 0, 'MDate' => now()->toDateString(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'RemainingSessions' => 8,
            'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'CampusID' => 1,
        ]);

        $res = $this->dashboard($this->makeToken($s));
        $res->assertOk();

        // 7（池子，一次）+ 8（英文）= 15。
        $this->assertSame(15, $res->json('remaining_sessions_total'));

        $bySubject = $res->json('remaining_by_subject');
        $this->assertCount(2, $bySubject, '英文一筆 + 共用方案一筆');
        $this->assertSame(8, $bySubject['英文'] ?? null, '非共用課照科目顯示');
    }
}
