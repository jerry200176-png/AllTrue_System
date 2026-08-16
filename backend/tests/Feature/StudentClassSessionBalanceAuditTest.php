<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1811: manual SessionCount / RemainingSessions edits must leave a
 * PII-minimized security_audit_events row (who / when / old→new).
 */
class StudentClassSessionBalanceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_count_change_writes_security_audit_event(): void
    {
        [$token, $userId] = $this->director();
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'Rate' => 1000,
            'Charge' => 8000,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            [
                'rate_per_30min' => 1000,
                'sessions_purchased' => 10,
                'remaining_sessions' => 10,
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $row = DB::table('security_audit_events')
            ->where('event_type', 'student_class.session_balance_adjust')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(SecurityAuditEvent::ref('user', $userId), $row->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('student_class', $sc->ID), $row->subject_ref);

        $meta = json_decode($row->metadata, true);
        $this->assertSame(8, (int) ($meta['old_session_count'] ?? -1));
        $this->assertSame(10, (int) ($meta['new_session_count'] ?? -1));
        $this->assertSame(8, (int) ($meta['old_remaining_sessions'] ?? -1));
        $this->assertSame(10, (int) ($meta['new_remaining_sessions'] ?? -1));
        $this->assertArrayNotHasKey('phone', $meta);
    }

    public function test_unchanged_session_count_does_not_write_audit_event(): void
    {
        [$token] = $this->director();
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'Rate' => 1000,
            'Charge' => 8000,
            'Memo' => 'old',
        ]);

        $before = DB::table('security_audit_events')
            ->where('event_type', 'student_class.session_balance_adjust')
            ->count();

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            [
                'rate_per_30min' => 1000,
                'sessions_purchased' => 8,
                'remaining_sessions' => 8,
                'memo' => 'note only',
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();

        $after = DB::table('security_audit_events')
            ->where('event_type', 'student_class.session_balance_adjust')
            ->count();
        $this->assertSame($before, $after);
    }

    /** @return array{0:string,1:int} */
    private function director(): array
    {
        $user = User::create([
            'LoginName' => 'session-balance-audit-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
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

        return [$token, (int) $user->id];
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '堂數稽核生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

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
            'TotalHours' => 16,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
