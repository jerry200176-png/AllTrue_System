<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_message_uses_expected_copy_template_with_auto_values(): void
    {
        $token = $this->createDirectorToken([1], 'director-template@example.com');

        $student = Student::create([
            'name' => '王小明',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $mathSubject = Subject::create([
            'School_id' => 1,
            'Grade_no' => 0,
            'Subject_Name' => '數學',
        ]);
        $bioSubject = Subject::create([
            'School_id' => 1,
            'Grade_no' => 0,
            'Subject_Name' => '生物',
        ]);

        $this->createStudentClass($student->id, [
            'StartDate' => '2026-03-16',
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'Rate' => 1500,
            'Charge' => 12000,
            'Paid' => 0,
            'SubjectID' => $mathSubject->id,
        ]);

        $this->createStudentClass($student->id, [
            'StartDate' => '2026-03-03',
            'SessionCount' => 8,
            'RemainingSessions' => 2,
            'Rate' => 1500,
            'Charge' => 12000,
            'Paid' => 0,
            'SubjectID' => $bioSubject->id,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/parent/payment-message/{$student->id}");

        $res->assertOk()
            ->assertJsonPath('student_name', '王小明')
            ->assertJsonPath('total_amount', 24000);

        $message = (string) $res->json('message');
        $this->assertStringContainsString('媽媽你好:', $message);
        $this->assertStringContainsString('本期學費', $message);
        $this->assertStringContainsString("數學\n115.3.16~8堂\n1500*8 = *12,000*", $message);
        $this->assertStringContainsString("生物\n115.3.3~8堂\n1500*8 = *12,000*", $message);
        $this->assertStringContainsString('共24,000元', $message);
        $this->assertStringContainsString('再麻煩媽媽 幫我繳款 謝謝你', $message);
    }

    public function test_payment_message_rejects_cross_campus_access_for_director(): void
    {
        $token = $this->createDirectorToken([2], 'director-campus2@example.com');

        $student = Student::create([
            'name' => '跨校學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $this->createStudentClass($student->id, [
            'StartDate' => '2026-03-16',
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'Rate' => 1500,
            'Charge' => 12000,
            'Paid' => 0,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/parent/payment-message/{$student->id}")
            ->assertStatus(403);
    }

    public function test_payment_message_includes_only_unpaid_classes_and_uses_subject_name(): void
    {
        $token = $this->createDirectorToken([1], 'director-unpaid-only@example.com');

        $student = Student::create([
            'name' => '林同學',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $english = Subject::create([
            'School_id' => 1,
            'Grade_no' => 0,
            'Subject_Name' => '英文',
        ]);
        $math = Subject::create([
            'School_id' => 1,
            'Grade_no' => 0,
            'Subject_Name' => '數學',
        ]);

        // 已繳費課程：即使剩餘堂數低，也不應出現在文案
        $this->createStudentClass($student->id, [
            'StartDate' => '2026-03-01',
            'SessionCount' => 4,
            'RemainingSessions' => 1,
            'Rate' => 1000,
            'Charge' => 4000,
            'Paid' => 1,
            'SubjectID' => $english->id,
        ]);

        // 未繳費課程：應出現在文案
        $this->createStudentClass($student->id, [
            'StartDate' => '2026-03-20',
            'SessionCount' => 6,
            'RemainingSessions' => 6,
            'Rate' => 1200,
            'Charge' => 7200,
            'Paid' => 0,
            'SubjectID' => $math->id,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/parent/payment-message/{$student->id}");

        $res->assertOk()->assertJsonPath('total_amount', 7200);
        $message = (string) $res->json('message');

        $this->assertStringContainsString("數學\n115.3.20~6堂\n1200*6 = *7,200*", $message);
        $this->assertStringContainsString('共7,200元', $message);
        $this->assertStringNotContainsString('英文', $message);
        $this->assertStringNotContainsString('115.3.1', $message);
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'Subject' => '課程',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 0,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 0,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 2,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
