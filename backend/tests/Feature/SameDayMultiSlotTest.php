<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SameDayMultiSlotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * day_time_slots must reflect the DB contract (week/time fields), not ClassSession instances.
     * Even when ClassSession has different times, the contract is authoritative.
     */
    public function test_index_day_time_slots_reflect_contract_not_sessions(): void
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '契約優先學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = $this->createStudentClass($student->id, [
            'week' => 6,
            'time' => '15:00:00',
            'week1' => 6,
            'time1' => '17:00:00',
        ]);

        // Sessions have different times than the contract
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-04',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'completed',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-04',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'completed',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes?branch_id=1&student_id={$student->id}");

        $res->assertOk();
        $courses = collect($res->json('data'));
        $course = $courses->firstWhere('id', $sc->ID);
        $this->assertNotNull($course, 'Course must appear in response');

        $slots = $course['day_time_slots'] ?? [];
        $this->assertCount(2, $slots, 'Should have exactly 2 contract slots');

        $times = array_map(fn ($s) => $s['start_time'], $slots);
        sort($times);
        $this->assertSame(['15:00', '17:00'], $times, 'Slots must match contract (week/time DB fields), not ClassSession times');
    }

    /**
     * schedule_drift should be true when future sessions don't match the contract.
     */
    public function test_index_schedule_drift_detected_when_sessions_differ(): void
    {
        Carbon::setTestNow('2026-04-01 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '漂移偵測學生',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $sc = $this->createStudentClass($student->id, [
                'week' => 6,
                'time' => '15:00:00',
            ]);

            // Future session at different time than contract
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-04',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/student-classes?branch_id=1&student_id={$student->id}");

            $res->assertOk();
            $courses = collect($res->json('data'));
            $course = $courses->firstWhere('id', $sc->ID);
            $this->assertNotNull($course);

            $this->assertTrue($course['schedule_drift'] ?? false, 'schedule_drift should be true when sessions differ from contract');

            $slots = $course['day_time_slots'] ?? [];
            $this->assertCount(1, $slots);
            $this->assertSame('15:00', substr($slots[0]['start_time'] ?? '', 0, 5), 'Contract time preserved despite different session');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 課程主檔僅週六時，未刪除的「週日」預排堂次不得讓列表時段多出週日（契約優先於孤兒預排）。
     */
    public function test_index_day_time_slots_ignore_future_sessions_outside_contract_weekdays(): void
    {
        Carbon::setTestNow('2026-04-01 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '單週六契約學生',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $sc = $this->createStudentClass($student->id, [
                'week' => 6,
                'time' => '13:00:00',
                'week1' => null,
                'time1' => null,
            ]);

            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-04',
                'StartTime' => '13:00:00',
                'EndTime' => '15:00:00',
                'Status' => 'scheduled',
            ]);
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-05',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/student-classes?branch_id=1&student_id={$student->id}");

            $res->assertOk();
            $courses = collect($res->json('data'));
            $course = $courses->firstWhere('id', $sc->ID);
            $this->assertNotNull($course);

            $slots = $course['day_time_slots'] ?? [];
            $this->assertCount(1, $slots);
            $this->assertSame(6, (int) ($slots[0]['day'] ?? 0));
            $this->assertSame('13:00', substr((string) ($slots[0]['start_time'] ?? ''), 0, 5));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * After editing (PUT) a course with immutable history, the week/time DB
     * fields must be reconciled with actual ClassSession times.
     */
    public function test_update_reconciles_week_time_fields_with_sessions(): void
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '編輯測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = $this->createStudentClass($student->id, [
            'week' => 6,
            'time' => '13:00:00',
            'week1' => 6,
            'time1' => '17:00:00',
            'SessionCount' => 8,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'StartDate' => '2026-04-04',
        ]);

        $cs1 = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-04',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'completed',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-04',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'completed',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-11',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-11',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
        ]);

        LearningRecord::create([
            'StudentClassID' => $sc->ID,
            'ClassSessionID' => $cs1->id,
            'TeacherID' => 99,
            'Subject' => '化學',
            'Content' => '',
            'SessionDate' => '2026-04-04',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'approved',
            'ApprovedBy' => 1,
            'ApprovedAt' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$sc->ID}", [
            'subject' => 'Chemistry',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'duration_hours' => 2,
            'payment_type' => 'session',
            'sessions_purchased' => 8,
            'days_of_week' => [6],
            'day_time_slots' => [
                ['day' => 6, 'start_time' => '13:00', 'duration_hours' => 2],
                ['day' => 6, 'start_time' => '17:00', 'duration_hours' => 2],
            ],
            'start_time' => '13:00',
            'first_class_date' => '2026-04-04',
        ]);

        $res->assertOk();

        $sc->refresh();
        $this->assertSame(6, (int) $sc->week, 'week should be Saturday (6)');
        $this->assertStringStartsWith('13:00', (string) $sc->time, 'time should match first ClassSession start (13:00)');
        $this->assertSame(6, (int) $sc->week1, 'week1 should be Saturday (6)');
        $this->assertStringStartsWith('17:00', (string) $sc->time1, 'time1 should match second ClassSession start (17:00)');
        $this->assertNull($sc->week2, 'No ghost third slot');
        $this->assertNull($sc->time2, 'No ghost third time');
    }

    /**
     * When a brand-new course with same-day multi-slot is updated with
     * force_rebuild_if_mismatch, buildSessionsForCount must generate
     * multiple sessions per day.
     */
    public function test_session_rebuild_generates_multi_slot_per_day(): void
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '重建測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = $this->createStudentClass($student->id, [
            'week' => 6,
            'time' => '13:00:00',
            'week1' => 6,
            'time1' => '17:00:00',
            'duration1' => 120,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'StartDate' => '2026-04-11',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$sc->ID}", [
            'subject' => 'Chemistry',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'duration_hours' => 2,
            'payment_type' => 'session',
            'sessions_purchased' => 8,
            'days_of_week' => [6],
            'day_time_slots' => [
                ['day' => 6, 'start_time' => '13:00', 'duration_hours' => 2],
                ['day' => 6, 'start_time' => '17:00', 'duration_hours' => 2],
            ],
            'start_time' => '13:00',
            'first_class_date' => '2026-04-18',
        ]);

        $res->assertOk();

        $sessions = ClassSession::where('StudentClassID', $sc->ID)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();

        $this->assertCount(8, $sessions, 'Should have 8 ClassSessions for 8-session course');

        $dates = $sessions->pluck('SessionDate')->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->unique()->values()->all();
        $this->assertCount(4, $dates, 'Should span 4 Saturdays (2 sessions each)');

        $firstDateSessions = $sessions->filter(fn ($s) => \Carbon\Carbon::parse($s->SessionDate)->toDateString() === $dates[0]);
        $this->assertCount(2, $firstDateSessions, 'Each Saturday should have 2 sessions');

        $startTimes = $firstDateSessions->pluck('StartTime')->map(fn ($t) => substr($t, 0, 5))->sort()->values()->all();
        $this->assertSame(['13:00', '17:00'], $startTimes, 'Same-day sessions must have distinct start times');
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'multi-slot-test-' . uniqid() . '@example.com',
            'Name' => '多時段測試主任',
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

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 68,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-04',
            'TotalHours' => 16,
            'Charge' => 20000,
            'Pay' => null,
            'Paid' => 0,
            'Rate' => 2500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
