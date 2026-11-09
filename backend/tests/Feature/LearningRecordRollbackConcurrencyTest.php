<?php

namespace Tests\Feature;

use App\Models\{AuthToken, ClassSession, LearningRecord, SessionDeductionLedger, Student, StudentClass, User, UserCampus};
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentReportRaceWorker;
use Tests\TestCase;

/** In-app #523: real authenticated HTTP requests, separate processes and InnoDB lock barriers. */
class LearningRecordRollbackConcurrencyTest extends TestCase
{
    use PaymentReportRaceWorker;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tests/Support/alltrueTestDatabase.php');
        alltrue_race_database_config(config('database.connections.mysql'));
        $this->raceAssertMysqlRr();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        $this->raceTerminateAllWorkers();
        $this->raceCleanupFiles();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_bound_approved_requests_only_apply_rollback_once(): void
    {
        $teacher = $this->user('teacher-race', 'T', 0);
        $director = $this->user('director-race', 'A', 1);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Synthetic rollback student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'week' => 5, 'time' => '16:00:00', 'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        $records = [];
        foreach (['2026-04-10', '2026-04-11'] as $date) {
            $session = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled']);
            $lr = LearningRecord::create(['StudentClassID' => $course->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $teacher->id,
                'Subject' => 'Math', 'Content' => 'Synthetic assessment', 'Progress' => 'Synthetic progress', 'Status' => 'pending',
                'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00']);
            $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->postJson('/api/v1/learning-records/'.$lr->id.'/approve', [])->assertOk();
            $records[] = $lr;
        }
        $this->assertSame(2, (int) $teacher->fresh()->TeachingSessionCount);
        $usedBefore = (float) $course->fresh()->UsedSessions;
        $pause = $this->racePause('from `studentclass`');
        $connection = $this->raceFile();
        $payload = ['record_id' => $records[0]->id, 'token' => $token];
        $winner = $this->raceSpawn(base_path('tests/Support/learning-record-rollback-worker.php'), $payload + ['pause' => $pause, 'connection_file' => $this->raceFile()]);
        $this->raceAwait($pause['ready']);
        $loser = $this->raceSpawn(base_path('tests/Support/learning-record-rollback-worker.php'), $payload + ['connection_file' => $connection]);
        $this->raceAwait($connection);
        $blocked = false;
        $deadline = microtime(true) + 3;
        do {
            $row = DB::selectOne('SELECT TIME, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [(int) file_get_contents($connection)]);
            $sql = strtolower((string) ($row->INFO ?? ''));
            if ((int) ($row->TIME ?? 0) >= 1 && str_contains($sql, 'from `studentclass`') && str_contains($sql, 'for update')) { $blocked = true; break; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertTrue($blocked, 'the second authenticated request must wait on the winning course lock');
        touch($pause['release']);
        $this->assertSame(200, $this->raceFinish($winner)['status']);
        $this->assertSame(409, $this->raceFinish($loser)['status']);
        $this->assertSame('pending', $records[0]->fresh()->Status);
        $this->assertSame('approved', $records[1]->fresh()->Status);
        $this->assertSame(1, (int) $teacher->fresh()->TeachingSessionCount);
        $this->assertSame($usedBefore - 1, (float) $course->fresh()->UsedSessions);
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $records[0]->ClassSessionID)->where('event_type', 'reverse')->count());
    }

    private function user(string $name, string $type, int $admin): User
    {
        $user = User::create(['LoginName' => $name.'-'.uniqid().'@example.com', 'Name' => $name, 'PSW' => 'synthetic-only',
            'type' => $type, 'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => $admin, 'Approved' => 1]);
        return $user;
    }
}
