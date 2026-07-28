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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Series vs occurrence: one-off 調課 must not rewrite week/time;
 * pause can opt out of cancelling remaining sessions;
 * renew-monthly preview warns about open contract exceptions.
 */
class ScheduleOccurrenceStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_ignores_contract_exception_sessions(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            $this->markTestSkipped('IsContractException column missing');
        }

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        try {
            $token = $this->directorToken();
            $student = $this->makeStudent();
            $course = $this->makeCourse($student->id, [
                'week' => 1,
                'time' => '16:00:00',
                'week1' => null,
                'time1' => null,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'RemainingSessions' => 6,
            ]);

            // Contract Monday 16:00 still scheduled
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-27', // Mon
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'IsContractException' => 0,
            ]);
            // One-off 調課到 Wednesday — must not rewrite contract week/time
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-22', // Wed
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
                'IsContractException' => 1,
            ]);

            $this->putJson(
                "/api/v1/student-classes/{$course->ID}",
                ['memo' => 'reconcile-probe'],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $course->refresh();
            $this->assertSame(1, (int) $course->week, 'contract weekday must stay Monday');
            $this->assertSame('16:00', substr((string) $course->time, 0, 5));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pause_without_cancel_remaining_keeps_future_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        try {
            $token = $this->directorToken();
            $student = $this->makeStudent();
            $course = $this->makeCourse($student->id, [
                'Paid' => 1,
                'RemainingSessions' => 3,
            ]);
            $futureId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-28',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
            ]);

            $this->postJson(
                "/api/v1/student-classes/{$course->ID}/pause",
                ['action' => 'pause', 'cancel_remaining' => false],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()
                ->assertJsonPath('cancel_remaining', false)
                ->assertJsonPath('cancelled_count', 0);

            $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $futureId)->value('Status'));
            $course->refresh();
            $this->assertSame(1, (int) $course->Stop);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_renew_monthly_preview_warns_open_exceptions(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            $this->markTestSkipped('IsContractException column missing');
        }

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        try {
            $token = $this->directorToken();
            $student = $this->makeStudent();
            $course = $this->makeCourse($student->id, [
                'ScheduleMode' => 'date',
                'StartDate' => '2026-07-01',
                'EndDate' => '2026-07-31',
                'week' => 2,
                'time' => '16:00:00',
                'Rate' => 1000,
                'settlement_day' => 15,
                'SessionDuration' => 120,
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-28',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
                'IsContractException' => 1,
            ]);

            $res = $this->postJson(
                "/api/v1/student-classes/{$course->ID}/renewal-preview",
                [
                    'mode' => 'renew_monthly',
                    'end_date' => '2026-08-31',
                ],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $codes = collect($res->json('warnings') ?? [])->pluck('code')->all();
            $this->assertContains('open_contract_exceptions', $codes);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'occ-stab-' . uniqid() . '@example.com',
            'Name' => 'Occurrence Stability Director',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => 'Occurrence Stability Student',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeCourse(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 1000,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'Memo' => '',
        ], $overrides));
    }
}
