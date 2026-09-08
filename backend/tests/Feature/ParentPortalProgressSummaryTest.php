<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD: enterprise dashboard parent portal v2 — progress hub summary.
 *
 * Verify that /api/v1/parent/dashboard returns a `progress_summary` block with
 * the four expected sections used by the parent progress hub UI cards
 * (week_progress, next_session, pending_actions, payment).
 */
class ParentPortalProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_progress_summary_block(): void
    {
        $student = $this->createStudent(1, '進度中心學生', '0913000111');
        $course = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 5,
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);

        $today = Carbon::today();
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $today->toDateString(),
            'StartTime' => '08:00',
            'EndTime' => '08:30',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $today->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
        ]);

        $token = $this->parentLogin('進度中心學生', '0913000111');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $res->assertJsonStructure([
            'progress_summary' => [
                'week_label',
                'week_progress' => ['attended', 'scheduled', 'records_filled'],
                'next_session',
                'pending_actions',
                'interaction_statuses',
                'notifications',
                'pending_total',
                'payment' => ['status', 'paid_courses', 'unpaid_courses', 'total_courses'],
                'feedback_program' => [
                    'version',
                    'window' => ['start', 'end', 'days'],
                    'funnel' => ['approved_records', 'replied_records', 'reply_rate_pct', 'unreplied_records'],
                    'quick_templates',
                    'reminder_policy' => ['trigger_window_hours', 'quiet_hours', 'throttle', 'mute_options'],
                    'digest' => ['unreplied_preview', 'next_digest_at'],
                ],
                'feedback_program_version',
                'generated_at',
            ],
        ]);

        $payload = $res->json('progress_summary');
        $this->assertGreaterThanOrEqual(1, (int) $payload['week_progress']['attended']);
        $this->assertGreaterThanOrEqual(2, (int) $payload['week_progress']['scheduled']);
        $this->assertNotNull($payload['next_session']);
        $this->assertSame(1, (int) $payload['payment']['unpaid_courses']);
        $this->assertSame(1, (int) $payload['payment']['total_courses']);
        $this->assertContains($payload['payment']['status'], ['all_pending', 'partial', 'all_clear']);
        $this->assertSame('v1', (string) $payload['feedback_program']['version']);
        $this->assertSame(3, count($payload['feedback_program']['quick_templates']));
        $this->assertSame('v1', (string) $payload['feedback_program_version']);
        $this->assertArrayHasKey('daily_cap', $payload['feedback_program']['reminder_policy']['throttle']);
        $this->assertIsArray($payload['interaction_statuses']);
        $this->assertIsArray($payload['notifications']);
    }

    public function test_parent_event_endpoint_requires_session_and_accepts_parent_events(): void
    {
        $this->postJson('/api/v1/parent/events', [
            'event' => 'parent.progress_card_clicked',
            'meta' => ['card' => 'week_progress'],
        ])->assertStatus(401);

        $this->createStudent(1, '家長事件學生', '0913000222');
        $token = $this->parentLogin('家長事件學生', '0913000222');

        $this->postJson('/api/v1/parent/events', [
            'event' => 'parent.progress_card_clicked',
            'meta' => ['card' => 'week_progress', 'target' => 'learning'],
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_pending_feedback_count_is_not_limited_by_learning_history_page(): void
    {
        $student = $this->createStudent(1, '分頁回饋學生', '0913000333');
        $course = $this->createStudentClass($student->id);

        for ($i = 0; $i < 11; $i++) {
            $sessionDate = Carbon::today()->subDays($i)->toDateString();
            $session = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $sessionDate,
                'StartTime' => '18:00',
                'EndTime' => '19:00',
                'Status' => 'attended',
            ]);

            DB::table('LearningRecord')->insert([
                'StudentID' => $student->id,
                'StudentClassID' => $course->ID,
                'ClassSessionID' => $session->id,
                'TeacherID' => 1,
                'Subject' => '英文',
                'SessionDate' => $sessionDate,
                'StartTime' => '18:00',
                'EndTime' => '19:00',
                'Content' => '第 ' . ($i + 1) . ' 筆已核准評量',
                'Status' => 'approved',
                'ApprovedBy' => 1,
                'ApprovedAt' => now()->subDays($i),
                'created_at' => now()->subDays($i),
                'updated_at' => now()->subDays($i),
            ]);
        }

        $token = $this->parentLogin('分頁回饋學生', '0913000333');
        $res = $this->getJson('/api/v1/parent/dashboard?lr_page=1&lr_per_page=10', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $pendingFeedback = collect($res->json('progress_summary.pending_actions'))
            ->firstWhere('key', 'feedback');
        $this->assertSame(11, (int) ($pendingFeedback['count'] ?? 0));
        $this->assertCount(10, $res->json('learning_records'));
    }

    private function createStudent(int $campusId, string $name, ?string $phone = null): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-05-31',
            'TotalHours' => 8,
            'Charge' => 8800,
            'Paid' => 0,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function parentLogin(string $name, string $phone): string
    {
        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => $name,
            'Phone' => $phone,
        ]);
        $res->assertOk();
        return $res->json('token');
    }
}
