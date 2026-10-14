<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReconcileControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        $this->reportPath = storage_path('logs/nightly-reconcile-99991231.json');
        @unlink($this->reportPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_latest_unwraps_authorized_display_names_without_persisting_them(): void
    {
        $campus = Campus::factory()->create(['name' => '東湖分校']);
        $student = Student::create([
            'name' => '對帳測試學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $subject = Subject::create([
            'School_id' => 1,
            'Grade_no' => 7,
            'Subject_Name' => '數學',
        ]);

        file_put_contents($this->reportPath, json_encode([
            'checked_at' => '9999-12-31 02:00:00',
            'total_checked' => 1,
            'mismatch_count' => 1,
            'cause_counts' => ['attendance_ahead' => 1],
            'mismatches' => [[
                'student_class_id' => 99,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'session_count' => 8,
                'recorded_used' => 1,
                'expected_used' => 2,
                'actual_attended' => 2,
                'diff' => 1,
                'category' => 'attendance_ahead',
            ]],
        ]));

        $response = $this->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/reconcile/latest');

        $response->assertOk()
            ->assertJsonPath('data.mismatches.0.student_name', '對帳測試學生')
            ->assertJsonPath('data.mismatches.0.subject_name', '數學')
            ->assertJsonPath('data.mismatches.0.campus_name', '東湖分校')
            ->assertJsonPath('data.cause_counts.attendance_ahead', 1);

        $stored = (string) file_get_contents($this->reportPath);
        $this->assertStringNotContainsString('對帳測試學生', $stored);
        $this->assertStringNotContainsString('東湖分校', $stored);
    }

    public function test_latest_and_history_ignore_dry_run_reports(): void
    {
        $live = storage_path('logs/nightly-reconcile-99991230.json');
        $preview = storage_path('logs/nightly-reconcile-dryrun-99991231.json');
        $extraFiles = [$live, $preview];
        try {
            file_put_contents($live, json_encode([
                'checked_at' => '9999-12-30 02:00:00', 'mode' => 'live',
                'total_checked' => 5, 'mismatch_count' => 0, 'cause_counts' => [], 'mismatches' => [],
            ]));
            file_put_contents($preview, json_encode([
                'checked_at' => '9999-12-31 10:00:00', 'mode' => 'dry-run',
                'total_checked' => 6, 'mismatch_count' => 0, 'cause_counts' => [], 'mismatches' => [],
            ]));
            // A dry-run written by an older build under the dated live name.
            file_put_contents($this->reportPath, json_encode([
                'checked_at' => '9999-12-31 09:00:00', 'mode' => 'dry-run',
                'total_checked' => 7, 'mismatch_count' => 0, 'cause_counts' => [], 'mismatches' => [],
            ]));

            $token = $this->superAdminToken();
            $this->withToken($token)->getJson('/api/v1/admin/reconcile/latest')
                ->assertOk()
                ->assertJsonPath('data.checked_at', '9999-12-30 02:00:00')
                ->assertJsonPath('data.mode', 'live');

            $history = $this->withToken($token)->getJson('/api/v1/admin/reconcile')
                ->assertOk()
                ->json('data');
            $dates = array_column($history, 'date');
            $this->assertContains('9999-12-30 02:00:00', $dates);
            $this->assertNotContains('9999-12-31 10:00:00', $dates);
            $this->assertNotContains('9999-12-31 09:00:00', $dates);
        } finally {
            foreach ($extraFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function superAdminToken(): string
    {
        $user = User::create([
            'LoginName' => 'reconcile-super-' . uniqid() . '@test.com',
            'Name' => 'Super',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => 912000002,
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
