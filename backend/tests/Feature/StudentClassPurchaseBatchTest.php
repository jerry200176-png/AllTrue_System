<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassPurchaseBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_batch_creates_separate_unpaid_course_without_merging_source(): void
    {
        $token = $this->createDirectorToken([1], 'director-purchase@example.com');

        $student = Student::create([
            'name' => '加購學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'Paid' => 1,
            'Rate' => 500,
            'SessionDuration' => 120,
            'Charge' => 4000,
            'StartDate' => '2026-03-01',
            'week' => 2,
            'time' => '20:00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 6,
            'start_date' => '2026-04-07',
            'mode' => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'new_purchase')
            ->assertJsonPath('new_course.session_count', 6)
            ->assertJsonPath('new_course.remaining_sessions', 6)
            ->assertJsonPath('new_course.created_sessions', 6)
            ->assertJsonPath('new_course.first_session_date', '2026-04-07')
            ->assertJsonPath('new_course.last_session_date', '2026-05-12');

        $source->refresh();
        $this->assertSame(8, (int) $source->SessionCount);
        $this->assertSame(1, (int) $source->RemainingSessions);
        $this->assertSame(1, (int) $source->Paid);

        $newId = (int) $res->json('new_course.id');
        $newCourse = StudentClass::where('ID', $newId)->first();
        $this->assertNotNull($newCourse);
        $this->assertSame(6, (int) $newCourse->SessionCount);
        $this->assertSame(6, (int) $newCourse->RemainingSessions);
        $this->assertSame(0, (int) $newCourse->Paid);
        $this->assertStringStartsWith('2026-04-07', (string) $newCourse->StartDate);
        $this->assertSame(3000, (int) $newCourse->Charge);

        // ClassSession rows must be created for the new course
        $sessionRows = ClassSession::where('StudentClassID', $newId)
            ->orderBy('SessionDate')
            ->get();
        $this->assertCount(6, $sessionRows);
        // First session on start_date (2026-04-07, a Monday — fallback uses start_date DOW)
        $this->assertStringStartsWith('2026-04-07', (string) $sessionRows[0]->SessionDate);
        $this->assertSame('scheduled', (string) $sessionRows[0]->Status);
        // All sessions should have the inherited time
        $this->assertStringStartsWith('20:00', (string) $sessionRows[0]->StartTime);
    }

    public function test_purchase_batch_rejects_split_mode(): void
    {
        $token = $this->createDirectorToken([1], 'director-repair@example.com');

        $student = Student::create([
            'name' => '修正學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 16,
            'RemainingSessions' => 10,
            'UsedSessions' => 6,
            'Paid' => 0,
            'Rate' => 500,
            'SessionDuration' => 60,
            'Charge' => 16000,
            'StartDate' => '2026-03-01',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 8,
            'start_date' => '2026-04-10',
            'mode' => 'split_existing',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);

        $source->refresh();
        $this->assertSame(16, (int) $source->SessionCount);
        $this->assertSame(10, (int) $source->RemainingSessions);
        $this->assertSame(6, (int) $source->UsedSessions);
        $this->assertSame(0, (int) $source->Paid);
        $this->assertSame(16000, (int) $source->Charge);
    }

    public function test_purchase_batch_builds_full_multi_day_contract_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-purchase-multiday@example.com');

        $student = Student::create([
            'name' => '多時段學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'Paid' => 1,
            'Rate' => 1000,
            'SessionDuration' => 120,
            'Charge' => 8000,
            'StartDate' => '2026-04-01',
            'week' => 1,
            'time' => '18:00:00',
            'week1' => 4,
            'time1' => '18:00:00',
            'duration1' => 120,
            'ClassType' => 'one_on_three',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 12,
            'start_date' => '2026-05-25',
            'mode' => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('new_course.created_sessions', 12)
            ->assertJsonPath('new_course.first_session_date', '2026-05-25')
            ->assertJsonPath('new_course.last_session_date', '2026-07-02');

        $newId = (int) $res->json('new_course.id');
        $sessions = ClassSession::where('StudentClassID', $newId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();
        $this->assertCount(12, $sessions);

        $this->assertTrue(
            $sessions->contains(fn ($s) => substr((string) $s->SessionDate, 0, 10) === '2026-05-28'),
            'multi-day contract must include Thursday slot in generated sessions'
        );
    }

    public function test_purchase_batch_rejects_trial_source_and_points_to_convert(): void
    {
        // in-app #374: renewing from a trial must not create another trial batch.
        $token = $this->createDirectorToken([1], 'director-trial-purchase@example.com');
        $student = Student::create([
            'name' => '試聽學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $source = $this->createStudentClass($student->id, [
            'ClassType' => 'trial',
            'SessionCount' => 1,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
            'StartDate' => '2026-03-01',
            'week' => 2,
            'time' => '20:00:00',
        ]);
        $before = StudentClass::count();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 4,
            'start_date' => '2026-04-07',
            'mode' => 'new_purchase',
        ])->assertStatus(422)->assertJsonPath('code', 'trial_use_convert');

        $this->assertSame($before, StudentClass::count());
    }

    public function test_renewal_preview_rejects_trial_source_for_purchase_batch(): void
    {
        $token = $this->createDirectorToken([1], 'director-trial-preview@example.com');
        $student = Student::create([
            'name' => '試聽預覽學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $source = $this->createStudentClass($student->id, ['ClassType' => 'trial']);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
            'mode' => 'purchase_batch',
            'sessions' => 4,
            'start_date' => '2026-04-07',
        ])->assertStatus(422)->assertJsonPath('code', 'trial_use_convert');
    }

    public function test_hourly_batch_bills_planned_lesson_lengths_in_preview_and_purchase(): void
    {
        // #4344: Mon 1h + Thu 2h, 3 lessons from Monday = 1+2+1 = 4h (the old average billed 4.5h).
        [$token, $source] = $this->hourlySource(['SessionDuration' => 60, 'duration1' => 120]);
        $payload = ['sessions' => 3, 'start_date' => '2026-05-25'];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $preview = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/renewal-preview", $payload + ['mode' => 'purchase_batch']
        )->assertOk();
        $res = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/purchase-batch", $payload + ['mode' => 'new_purchase']
        )->assertCreated();

        $newCourse = StudentClass::where('ID', (int) $res->json('new_course.id'))->first();
        $lessonHours = ClassSession::where('StudentClassID', $newCourse->ID)->get()
            ->sum(fn ($s) => (strtotime((string) $s->EndTime) - strtotime((string) $s->StartTime)) / 3600);

        $this->assertEquals(4, $lessonHours);
        $this->assertSame(4, (int) $preview->json('proposed_course.total_hours'));
        $this->assertSame(4, (int) $newCourse->TotalHours);
        $this->assertSame(4000, (int) $preview->json('proposed_course.charge'));
        $this->assertSame(4000, (int) $newCourse->Charge);
    }

    public function test_hourly_batch_counts_a_lesson_past_midnight_at_its_full_length(): void
    {
        // Codex P2 on #4761: 23:00 + 2h is 23:00–01:00 as times only; it is 2 hours, never negative.
        [$token, $source] = $this->hourlySource(['SessionDuration' => 120, 'duration1' => 120, 'time' => '23:00:00', 'time1' => '23:00:00']);
        $payload = ['sessions' => 2, 'start_date' => '2026-05-25'];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $preview = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/renewal-preview", $payload + ['mode' => 'purchase_batch']
        )->assertOk();
        $res = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/purchase-batch", $payload + ['mode' => 'new_purchase']
        )->assertCreated();

        $newCourse = StudentClass::where('ID', (int) $res->json('new_course.id'))->first();
        $this->assertSame(4, (int) $preview->json('proposed_course.total_hours'));
        $this->assertSame(4, (int) $newCourse->TotalHours);
        $this->assertSame(4000, (int) $preview->json('proposed_course.charge'));
        $this->assertSame(4000, (int) $newCourse->Charge);
    }

    public function test_hourly_batch_with_equal_length_slots_is_unchanged(): void
    {
        [$token, $source] = $this->hourlySource(['SessionDuration' => 120, 'duration1' => 120]);
        $payload = ['sessions' => 3, 'start_date' => '2026-05-25'];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $preview = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/renewal-preview", $payload + ['mode' => 'purchase_batch']
        )->assertOk();
        $res = $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$source->ID}/purchase-batch", $payload + ['mode' => 'new_purchase']
        )->assertCreated();

        $newCourse = StudentClass::where('ID', (int) $res->json('new_course.id'))->first();
        $this->assertSame(6, (int) $preview->json('proposed_course.total_hours'));
        $this->assertSame(6, (int) $newCourse->TotalHours);
        $this->assertSame(6000, (int) $preview->json('proposed_course.charge'));
        $this->assertSame(6000, (int) $newCourse->Charge);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: StudentClass}
     */
    private function hourlySource(array $overrides): array
    {
        $token = $this->createDirectorToken([1], 'director-hourly@example.com');
        $student = Student::create([
            'name' => '時薪學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $source = $this->createStudentClass($student->id, $overrides + [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'Paid' => 1,
            'Rate' => 1000,
            'rate_unit' => 'hour',
            'StartDate' => '2026-04-01',
            'week' => 1,
            'time' => '18:00:00',
            'week1' => 4,
            'time1' => '18:00:00',
        ]);

        return [$token, $source];
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
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
