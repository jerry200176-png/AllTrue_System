<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'SessionDate' => $today->copy()->subDays(1)->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $today->copy()->addDays(1)->toDateString(),
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
                'pending_total',
                'payment' => ['status', 'paid_courses', 'unpaid_courses', 'total_courses'],
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
            'RoomID' => 'R1',
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
