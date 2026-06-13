<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\ScheduleAuditLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for #766 — schedule_audit_logs + ClassSessionObserver.
 */
class ScheduleAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private string $directorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'AuditTestCampus',
            'Token'          => 'audit-test-token-xyz',
            'code'           => 'aud',
            'Current'        => 0,
            'LineNotifyID'   => '',
            'Client_ID'      => '',
            'Client_Secret'  => '',
            'LIFFID'         => '',
            'LIFF_URL'       => '',
            'URL'            => '',
            'TelegramToken'  => '',
            'TelegramChatID' => '',
            'TelegramURL'    => '',
            'TeachLIFFID'    => '',
            'TeachLIFF_URL'  => '',
        ]);

        $director = User::create([
            'LoginName'          => 'audit-director@example.com',
            'Name'               => 'AuditDir',
            'PSW'                => password_hash('password', PASSWORD_DEFAULT),
            'type'               => 'A',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $director->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(32));
        AuthToken::create([
            'user_id'    => $director->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        $this->directorToken = $token;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeStudentClass(): int
    {
        $student = Student::create([
            'name'         => 'AuditStudent',
            'CampusID'     => $this->campus->id,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        $teacher = User::create([
            'LoginName'          => 'audit-teacher-' . uniqid() . '@x.com',
            'Name'               => 'AuditTeacher',
            'PSW'                => password_hash('password', PASSWORD_DEFAULT),
            'type'               => 'T',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $teacher->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);

        $subject = Subject::firstOrCreate(
            ['Subject_Name' => 'AuditSubject'],
            ['Subject_Name' => 'AuditSubject', 'CampusID' => $this->campus->id, 'School_id' => 0, 'Grade_no' => 0]
        );

        $sc = StudentClass::create([
            'StudentID'         => $student->id,
            'TeacherID'         => $teacher->id,
            'SubjectID'         => $subject->id,
            'GradeID'           => 0,
            'by1'               => $teacher->id,
            'RoomID'            => 0,
            'TotalHours'        => 10,
            'Charge'            => 1000,
            'SessionCount'      => 10,
            'RemainingSessions' => 10,
            'StartDate'         => now()->toDateString(),
            'EndDate'           => now()->addYear()->toDateString(),
            'ScheduleMode'      => 'date',
        ]);

        return (int) $sc->ID;
    }

    private function makeSession(int $studentClassId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate'    => now()->addDay()->toDateString(),
            'StartTime'      => '14:00',
            'EndTime'        => '16:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);
    }

    // ─── Observer: create ─────────────────────────────────────────────────────

    /** @test */
    public function observer_writes_create_log_when_class_session_created(): void
    {
        $scId    = $this->makeStudentClass();
        $session = $this->makeSession($scId);

        $logs = ScheduleAuditLog::where('session_id', $session->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('create', $logs->first()->action_type);
        $this->assertNull($logs->first()->old_data);
        $this->assertNotNull($logs->first()->new_data);
    }

    // ─── Observer: update ─────────────────────────────────────────────────────

    /** @test */
    public function observer_writes_update_log_with_old_and_new_data(): void
    {
        $scId    = $this->makeStudentClass();
        $session = $this->makeSession($scId);

        ScheduleAuditLog::where('session_id', $session->id)->delete();

        $session->Note = 'updated note';
        $session->save();

        $log = ScheduleAuditLog::where('session_id', $session->id)
            ->where('action_type', 'update')
            ->firstOrFail();

        $this->assertNotNull($log->old_data);
        $this->assertNotNull($log->new_data);
        $this->assertSame('updated note', $log->new_data['Note'] ?? null);
    }

    // ─── Observer: delete ─────────────────────────────────────────────────────

    /** @test */
    public function observer_writes_delete_log_with_old_data(): void
    {
        $scId      = $this->makeStudentClass();
        $session   = $this->makeSession($scId);
        $sessionId = $session->id;

        ScheduleAuditLog::where('session_id', $sessionId)->delete();

        $session->delete();

        $log = ScheduleAuditLog::where('session_id', $sessionId)
            ->where('action_type', 'delete')
            ->firstOrFail();

        $this->assertNotNull($log->old_data);
        $this->assertNull($log->new_data);
    }

    // ─── API ──────────────────────────────────────────────────────────────────

    /** @test */
    public function director_can_list_schedule_audit_logs(): void
    {
        $scId = $this->makeStudentClass();
        $this->makeSession($scId);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->directorToken}"])
            ->getJson('/api/v1/schedule-audit');

        $res->assertOk();
        $this->assertIsArray($res->json('data'));
        $this->assertNotEmpty($res->json('data'));
        $this->assertArrayHasKey('total', $res->json('meta'));
    }

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/schedule-audit')->assertStatus(401);
    }

    /** @test */
    public function logs_can_be_filtered_by_session_id(): void
    {
        $scId     = $this->makeStudentClass();
        $session1 = $this->makeSession($scId);
        $session2 = $this->makeSession($scId);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->directorToken}"])
            ->getJson("/api/v1/schedule-audit?session_id={$session1->id}");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertSame((int) $session1->id, (int) $row['session_id']);
        }
    }

    /** @test */
    public function audit_log_rows_contain_required_fields(): void
    {
        $scId    = $this->makeStudentClass();
        $session = $this->makeSession($scId);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->directorToken}"])
            ->getJson("/api/v1/schedule-audit?session_id={$session->id}");

        $res->assertOk();
        $row = $res->json('data.0');
        foreach (['id', 'session_id', 'action_type', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $row, "Missing field: {$field}");
        }
    }
}
