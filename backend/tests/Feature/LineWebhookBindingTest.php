<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineWebhookBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_binding_matches_parent_phone_when_student_phone_is_empty(): void
    {
        $campus = $this->createCampus();
        $student = $this->createStudent($campus->id, [
            'name' => '家長手機綁定測試',
            'Phone' => '',
            'parent_phone' => '0912-345-678',
        ]);

        $this->postJson("/api/v1/line/webhook/{$campus->id}", [
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'source' => ['userId' => 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
                'message' => [
                    'type' => 'text',
                    'text' => '綁定 家長手機綁定測試 0912345678',
                ],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('student_line_bindings', [
            'student_id' => $student->id,
            'line_user_id' => 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'campus_id' => $campus->id,
        ]);
    }

    public function test_line_binding_by_student_id_matches_parent_phone_when_student_phone_differs(): void
    {
        $campus = $this->createCampus();
        $student = $this->createStudent($campus->id, [
            'name' => '家長手機代號綁定',
            'Phone' => '0200000000',
            'parent_phone' => '0988 765 432',
        ]);

        $this->postJson("/api/v1/line/webhook/{$campus->id}", [
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'source' => ['userId' => 'Ub1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
                'message' => [
                    'type' => 'text',
                    'text' => "綁定 {$student->id} 0988765432",
                ],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('student_line_bindings', [
            'student_id' => $student->id,
            'line_user_id' => 'Ub1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'campus_id' => $campus->id,
        ]);
    }

    private function createCampus(): Campus
    {
        return Campus::create([
            'name' => 'LineTestCampus',
            'Token' => 'line-test-token',
            'code' => 'line-test',
            'Current' => 0,
            'LineNotifyID' => '',
            'Client_ID' => '',
            'Client_Secret' => '',
            'LIFFID' => '',
            'LIFF_URL' => '',
            'URL' => '',
            'TelegramToken' => '',
            'TelegramChatID' => '',
            'TelegramURL' => '',
            'TeachLIFFID' => '',
            'TeachLIFF_URL' => '',
        ]);
    }

    private function createStudent(int $campusId, array $overrides = []): Student
    {
        return Student::create(array_merge([
            'name' => 'Line Binding Student',
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => '',
        ], $overrides));
    }
}
