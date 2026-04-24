<?php

namespace Tests\Feature;

use App\Models\LearningRecord;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: 家長入口評量科目名稱標準化
 * BUG: LearningRecord.Subject 存的是原始值（English / 英文課），
 *      未經 mapSubjectLabel，導致同科目顯示不一致。
 */
class ParentPortalSubjectNameTest extends TestCase
{
    use RefreshDatabase;

    private static int $lrSeq = 900000;

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '測試學生', 'CampusID' => 15, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            'Phone' => '0911000001',
        ]);
    }

    private function makeToken(Student $student): string
    {
        $raw = \Illuminate\Support\Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);
        return $raw;
    }

    private function makeClassForStudent(Student $student): StudentClass
    {
        return StudentClass::create([
            'StudentID'       => $student->id,
            'GradeID'         => 1,
            'SubjectID'       => 0,
            'TeacherID'       => 1,
            'by1'             => 1,
            'Period'          => 4,
            'StartDate'       => now()->toDateString(),
            'TotalHours'      => 4,
            'Charge'          => 0,
            'Paid'            => 0,
            'Rate'            => 0,
            'RoomID'          => 'R1',
            'MDate'           => now()->toDateString(),
            'Stop'            => 0,
            'ScheduleMode'    => 'count',
            'SessionCount'    => 4,
            'SessionDuration' => 60,
            'RemainingSessions' => 4,
            'ClassType'       => 'one_on_one',
            'UsedSessions'    => 0,
        ]);
    }

    private function makeLR(Student $student, StudentClass $sc, string $subjectRaw): void
    {
        $sessionId = self::$lrSeq++;
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $lr = new LearningRecord([
            'StudentClassID' => $sc->ID,
            'ClassSessionID' => $sessionId,
            'TeacherID'      => 1,
            'Content'        => '',
            'Subject'        => $subjectRaw,
            'Status'         => 'approved',
        ]);
        $lr->StudentID = $student->id;
        $lr->save();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_english_subject_normalized_to_chinese(): void
    {
        $s = $this->makeStudent();
        $sc = $this->makeClassForStudent($s);
        $this->makeLR($s, $sc, 'English');
        $token = $this->makeToken($s);

        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $subjects = collect($res->json('learning_records'))->pluck('Subject')->unique()->values();
        $this->assertNotContains('English', $subjects->all(), 'English should be mapped to 英文');
        $this->assertContains('英文', $subjects->all());
    }

    public function test_chinese_course_suffix_normalized(): void
    {
        $s = $this->makeStudent();
        $sc = $this->makeClassForStudent($s);
        $this->makeLR($s, $sc, '英文課');
        $token = $this->makeToken($s);

        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $subjects = collect($res->json('learning_records'))->pluck('Subject')->unique()->values();
        $this->assertNotContains('英文課', $subjects->all(), '英文課 should be mapped to 英文');
        $this->assertContains('英文', $subjects->all());
    }

    public function test_both_variants_normalize_to_same_value(): void
    {
        $s = $this->makeStudent();
        $sc = $this->makeClassForStudent($s);
        $this->makeLR($s, $sc, 'English');
        $this->makeLR($s, $sc, '英文課');
        $token = $this->makeToken($s);

        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $subjects = collect($res->json('learning_records'))->pluck('Subject')->unique()->values();
        $this->assertCount(1, $subjects, '兩種寫法應標準化為同一個科目名稱');
        $this->assertEquals('英文', $subjects->first());
    }
}
