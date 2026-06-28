<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * API contract (#1043): GET /class-sessions must not return duplicate rows for the
 * same (student_id, session_date, normalized start_time slot) when status is identical.
 *
 * Intentional multi-status pairs (cancelled + scheduled) remain covered by
 * ClassSessionDuplicateStatusTest — not duplicated here.
 *
 * Gate for epic #957 (unique slot index + unified materialization).
 */
class ClassSessionIndexDuplicateSlotContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_index_rejects_duplicate_scheduled_slot_for_same_student(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;
            $targetDate = '2026-06-20';

            $teacher = $this->createTeacherUser($campusId, 'teacher-dup-slot@example.com');
            $token = $this->createToken($teacher->id);
            $student = $this->createStudent($campusId, '重複時段學生');
            $courseId = $this->createCourse($student->id, $teacher->id);

            DB::table('ClassSession')->insert([
                [
                    'StudentClassID' => $courseId,
                    'SessionDate' => $targetDate,
                    'StartTime' => '15:00',
                    'EndTime' => '17:00',
                    'Status' => 'scheduled',
                    'Note' => 'materialize-race-a',
                ],
                [
                    'StudentClassID' => $courseId,
                    'SessionDate' => $targetDate,
                    'StartTime' => '15:00',
                    'EndTime' => '17:00',
                    'Status' => 'scheduled',
                    'Note' => 'materialize-race-b',
                ],
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/class-sessions?teacher_id={$teacher->id}&start={$targetDate}&end={$targetDate}&branch_id={$campusId}&per_page=200");

            $res->assertOk();
            $this->assertNoDuplicateStudentDateSlots($res->json('data') ?? []);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_director_index_rejects_duplicate_scheduled_slot_for_same_student(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 10:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;
            $targetDate = '2026-06-21';

            $directorToken = $this->createDirectorToken($campusId);
            $teacherId = $this->createTeacherUser($campusId, 'teacher-dup-slot-dir@example.com')->id;
            $student = $this->createStudent($campusId, '主任視角重複時段');
            $courseId = $this->createCourse($student->id, $teacherId);

            DB::table('ClassSession')->insert([
                [
                    'StudentClassID' => $courseId,
                    'SessionDate' => $targetDate,
                    'StartTime' => '23:00',
                    'EndTime' => '23:30',
                    'Status' => 'scheduled',
                    'Note' => 'dup-a',
                ],
                [
                    'StudentClassID' => $courseId,
                    'SessionDate' => $targetDate,
                    'StartTime' => '23:00',
                    'EndTime' => '23:30',
                    'Status' => 'scheduled',
                    'Note' => 'dup-b',
                ],
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$directorToken}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/class-sessions?branch_id={$campusId}&start={$targetDate}&end={$targetDate}&per_page=200");

            $res->assertOk();
            $this->assertNoDuplicateStudentDateSlots($res->json('data') ?? []);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertNoDuplicateStudentDateSlots(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            $date = substr((string) ($row['session_date'] ?? ''), 0, 10);
            $start = substr((string) ($row['start_time'] ?? ''), 0, 5);
            $status = (string) ($row['status'] ?? '');
            if ($studentId <= 0 || $date === '' || $start === '') {
                continue;
            }
            $key = "{$studentId}|{$date}|{$start}|{$status}";
            $this->assertArrayNotHasKey(
                $key,
                $seen,
                "Duplicate class-sessions index row for student/date/slot/status: {$key}"
            );
            $seen[$key] = true;
        }
    }

    private function createTeacherUser(int $campusId, string $email): User
    {
        $teacher = User::create([
            'LoginName' => $email,
            'Name' => 'dup-slot-teacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0911000111',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return $teacher;
    }

    private function createDirectorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'director-dup-slot@example.com',
            'Name' => 'dup-slot-director',
            'PSW' => bcrypt('test'),
            'type' => 'A',
            'status' => 'active',
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
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

    private function createToken(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCourse(int $studentId, int $teacherId): int
    {
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'RoomID' => '',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'UsedSessions' => 5,
            'Stop' => 0,
        ]);
    }
}
