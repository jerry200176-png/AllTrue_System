<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_schedule_read_has_stable_envelope_and_row_types(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $this->createSchedule($student->id, '2026-04-10');
        $this->createSchedule($student->id, '2026-04-11');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schedules?branch_id=1&start=2026-04-01&end=2026-04-30&per_page=1');

        $response->assertOk()->assertJsonStructure([
            'current_page',
            'data' => [[
                'id',
                'student_id',
                'teacher_id',
                'subject',
                'day_of_week',
                'start_time',
                'end_time',
                'duration_hours',
                'class_type',
                'status',
                'type',
                'deduction',
                'branch_id',
                'schedule_date',
                'student_course_id',
                'original_schedule_id',
            ]],
            'per_page',
            'total',
            'last_page',
        ]);

        $this->assertSame(1, $response->json('current_page'));
        $this->assertSame(1, $response->json('per_page'));
        $this->assertSame(2, $response->json('total'));
        $this->assertSame(2, $response->json('last_page'));

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['student_id']);
        $this->assertIsInt($row['teacher_id']);
        $this->assertIsInt($row['day_of_week']);
        $this->assertIsInt($row['deduction']);
        $this->assertIsInt($row['branch_id']);
        $this->assertIsString($row['duration_hours']);
        $this->assertIsString($row['schedule_date']);
        $this->assertSame('2026-04-10', $row['schedule_date']);
        $this->assertSame('09:00', $row['start_time']);
        $this->assertSame('11:00', $row['end_time']);
    }

    public function test_per_page_all_returns_a_plain_list_with_the_same_row_contract(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $first = $this->createSchedule($student->id, '2026-04-10');
        $second = $this->createSchedule($student->id, '2026-04-11');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schedules?branch_id=1&start=2026-04-01&end=2026-04-30&per_page=all');

        $response->assertOk();
        $rows = $response->json();

        $this->assertIsArray($rows);
        $this->assertTrue(array_is_list($rows));
        $this->assertCount(2, $rows);
        $this->assertSame([$first->id, $second->id], array_column($rows, 'id'));
        $this->assertSame('2026-04-10', $rows[0]['schedule_date']);
        $this->assertArrayNotHasKey('current_page', $rows);
        $this->assertArrayNotHasKey('data', $rows);
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'schedule-contract@example.com',
            'Name' => '排程契約測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
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

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '排程契約測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createSchedule(int $studentId, string $date): Schedule
    {
        return Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => 101,
            'subject' => 'Math',
            'day_of_week' => 5,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => null,
        ]);
    }
}
