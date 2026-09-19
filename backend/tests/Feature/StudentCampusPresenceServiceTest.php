<?php
namespace Tests\Feature;
use App\Models\{AuthToken, Campus, ClassSession, Student, StudentCampusPresence, StudentClass, Subject, User, UserCampus};
use App\Services\StudentCampusPresenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;
class StudentCampusPresenceServiceTest extends TestCase
{
    use RefreshDatabase;
    private Campus $campus;
    private StudentCampusPresenceService $service;
    private int $subjectId;
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $this->service = app(StudentCampusPresenceService::class);
        $this->campus = Campus::create([
            'name' => 'PresenceCampus', 'Token' => 'presence-token', 'code' => 'pres', 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '',
            'URL' => '', 'TelegramToken' => '', 'TelegramChatID' => '', 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);
        $this->subjectId = Subject::create([
            'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'PresenceMath',
        ])->id;
    }
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
    private function makeStudent(): Student
    {
        return Student::create([
            'name' => 'PresenceStudent', 'CampusID' => $this->campus->id, 'ClassID' => 1,
            'RFID' => 'PRES-RFID-1', 'enable' => 1,
        ]);
    }
    public function test_arrival_and_toggle_do_not_write_attendance_or_ledger(): void
    {
        $student = $this->makeStudent();
        $this->service->recordArrival($student, (int) $this->campus->id, now()->subMinutes(5), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABB');
        $out = $this->service->toggleSwipe($student, (int) $this->campus->id, now(), 'pi-1', 'AABB');
        $this->assertSame('sign_out', $out['action']);
        $this->assertDatabaseCount('StudentSingIn', 0);
        $this->assertDatabaseCount('session_deduction_ledger', 0);
    }
    public function test_debounce_reuses_open_presence(): void
    {
        $student = $this->makeStudent();
        $a = $this->service->recordArrival($student, (int) $this->campus->id, now(), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABB');
        $b = $this->service->recordArrival($student, (int) $this->campus->id, now()->addSeconds(10), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABB');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, StudentCampusPresence::count());
    }
    public function test_different_idempotency_keys_keep_one_open_presence_per_student_campus(): void
    {
        $student = $this->makeStudent();
        $a = $this->service->recordArrival($student, (int) $this->campus->id, now(), idempotencyKey: 'arrival-a');
        $b = $this->service->recordArrival($student, (int) $this->campus->id, now()->addMinute(), idempotencyKey: 'arrival-b');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, StudentCampusPresence::query()->where('StudentID', $student->id)->where('CampusID', $this->campus->id)->where('Status', 'open')->count());
    }
    public function test_database_named_open_slot_constraint_keeps_one_open_row(): void
    {
        $student = $this->makeStudent();
        $this->service->recordArrival($student, (int) $this->campus->id, now(), idempotencyKey: 'db-first');
        try {
            StudentCampusPresence::create(['CampusID' => $this->campus->id, 'StudentID' => $student->id, 'Source' => 'rfid', 'ArrivedAt' => now(), 'Status' => 'open', 'IdempotencyKey' => 'db-second']);
            $this->fail('expected named open-slot constraint');
        } catch (QueryException $exception) {
            $this->assertSame('1062', (string) ($exception->errorInfo[1] ?? ''));
            $this->assertStringContainsString('scp_one_open_student_campus', $exception->getMessage());
        }
        $this->assertSame(1, StudentCampusPresence::query()->where('StudentID', $student->id)->where('CampusID', $this->campus->id)->where('Status', 'open')->count());
    }
    public function test_explicit_idempotency_key_cannot_return_another_student_record(): void
    {
        $owner = $this->makeStudent();
        $other = Student::create([
            'name' => 'OtherPresenceStudent', 'CampusID' => $this->campus->id, 'ClassID' => 1,
            'RFID' => 'PRES-RFID-2', 'enable' => 1,
        ]);
        $this->service->recordArrival($owner, (int) $this->campus->id, now(), idempotencyKey: 'shared-key');
        $this->expectException(\RuntimeException::class);
        $this->service->recordArrival($other, (int) $this->campus->id, now(), idempotencyKey: 'shared-key');
    }
    public function test_closed_orphan_and_voided_rows_can_reopen_without_two_open_rows(): void
    {
        $student = $this->makeStudent();
        $closed = $this->service->recordArrival($student, (int) $this->campus->id, now()->subMinutes(10), idempotencyKey: 'closed-in');
        $this->service->recordDeparture($student, (int) $this->campus->id, now());
        $this->assertSame('closed', $closed->fresh()->Status);
        $orphan = $this->service->recordArrival($student, (int) $this->campus->id, now()->subDay(), idempotencyKey: 'orphan-in');
        $this->service->orphanCloseBefore(now()->toDateString());
        $this->assertSame('orphan_closed', $orphan->fresh()->Status);
        $voided = $this->service->recordArrival($student, (int) $this->campus->id, now(), idempotencyKey: 'voided-in');
        $voided->update(['Status' => StudentCampusPresence::STATUS_VOIDED, 'VoidedAt' => now()]);
        $reopened = $this->service->recordArrival($student, (int) $this->campus->id, now()->addMinute(), idempotencyKey: 'reopen-in');
        $this->assertNotSame($voided->id, $reopened->id);
        $this->assertSame(1, StudentCampusPresence::query()->where('StudentID', $student->id)->where('CampusID', $this->campus->id)->where('Status', 'open')->whereNull('DepartedAt')->whereNull('VoidedAt')->count());
    }
    public function test_candidates_exclude_leave_and_cancelled(): void
    {
        $student = $this->makeStudent();
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $this->subjectId, 'TeacherID' => 1,
            'by1' => 0, 'TotalHours' => 2, 'StartDate' => now()->subYear(), 'Stop' => 0,
            'SessionCount' => 10, 'ScheduleMode' => 'count',
        ]);
        foreach ([
            ['10:00:00', '11:00:00', 'scheduled'],
            ['12:00:00', '13:00:00', 'leave'],
            ['14:00:00', '15:00:00', 'cancelled'],
        ] as [$start, $end, $status]) {
            ClassSession::create([
                'StudentClassID' => $sc->ID, 'SessionDate' => now()->toDateString(),
                'StartTime' => $start, 'EndTime' => $end, 'Status' => $status,
            ]);
        }
        $this->service->recordArrival($student, (int) $this->campus->id, now()->subMinutes(5), idempotencyKey: 'candidate-presence');
        $payload = $this->service->candidatesForStudent($student, now());
        $statuses = collect($payload['candidates'])->pluck('status')->all();
        $this->assertContains('scheduled', $statuses);
        $this->assertNotContains('leave', $statuses);
        $this->assertNotContains('cancelled', $statuses);
        $this->assertSame('leave_but_arrived', $payload['exception']);
    }
    public function test_candidates_require_covering_presence_and_writes_reject_cross_campus(): void
    {
        $student = $this->makeStudent();
        $this->assertSame([], $this->service->candidatesForStudent($student, now())['candidates']);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordArrival($student, (int) $this->campus->id + 1, now(), idempotencyKey: 'wrong-campus');
    }
    public function test_departure_before_arrival_is_fail_closed_and_does_not_close_presence(): void
    {
        $student = $this->makeStudent();
        $arrival = $this->service->recordArrival($student, (int) $this->campus->id, now(), idempotencyKey: 'temporal-in');
        $this->assertNull($this->service->recordDeparture($student, (int) $this->campus->id, now()->subMinute()));
        $this->assertSame('open', $arrival->fresh()->Status);
    }
    public function test_source_forbids_billing_calls(): void
    {
        foreach ([
            app_path('Services/StudentCampusPresenceService.php'),
            app_path('Http/Controllers/CampusPresenceController.php'),
        ] as $path) {
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\SessionDeductionService/', $src);
            $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\AttendanceEffectsService/', $src);
            $this->assertStringNotContainsString('deductOnAttendance', $src);
            $this->assertStringNotContainsString('applySessionStatus', $src);
        }
    }
    public function test_real_http_director_scope_and_super_admin_bypass(): void
    {
        $otherCampus = Campus::create(['name' => 'Other', 'Token' => 'other-token', 'code' => 'oth', 'Current' => 0, 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramToken' => '', 'TelegramChatID' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);
        $other = Student::create(['name' => 'OtherStudent', 'CampusID' => $otherCampus->id, 'ClassID' => 1, 'enable' => 1]);
        $studentA = $this->makeStudent();
        $this->service->recordArrival($studentA, (int) $this->campus->id, now()->subDay(), idempotencyKey: 'http-a');
        $director = $this->httpToken('director', (int) $this->campus->id);
        $headers = ['Authorization' => "Bearer {$director}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->getJson('/api/v1/campus-presence/open?campus_id=' . $this->campus->id)
            ->assertOk()->assertJsonFragment(['student_id' => $studentA->id])->assertJsonMissing(['student_id' => $other->id]);
        $this->withHeaders($headers)->getJson("/api/v1/students/{$studentA->id}/campus-presence/today")->assertOk()->assertJsonPath('on_campus', true)->assertJsonFragment(['student_id' => $studentA->id]);
        $this->withHeaders($headers)->getJson('/api/v1/campus-presence/open?campus_id=' . $otherCampus->id)->assertForbidden();
        foreach ([$other->id, 999999] as $id) {
            $this->withHeaders($headers)->getJson("/api/v1/students/{$id}/campus-presence/today")->assertNotFound();
            $this->withHeaders($headers)->getJson("/api/v1/students/{$id}/campus-presence/candidates")->assertNotFound();
        }
        $this->withHeaders($headers)->getJson("/api/v1/students/{$studentA->id}/campus-presence/today?campus_id={$otherCampus->id}")->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/campus-presence/open?campus_id=0')->assertUnprocessable();
        $this->withHeaders($headers)->getJson("/api/v1/students/{$studentA->id}/campus-presence/candidates?at=bad")->assertUnprocessable();
        $super = $this->httpToken('super_admin', null);
        foreach (["/api/v1/campus-presence/open?campus_id={$otherCampus->id}", "/api/v1/students/{$other->id}/campus-presence/today", "/api/v1/students/{$other->id}/campus-presence/candidates"] as $uri) {
            $this->withHeaders(['Authorization' => "Bearer {$super}", 'Accept' => 'application/json'])->getJson($uri)->assertOk();
        }
    }
    private function httpToken(string $role, ?int $campusId): string
    {
        $user = User::create(['LoginName' => $role . uniqid() . '@example.com', 'Name' => $role, 'PSW' => 'secret', 'type' => $role === 'super_admin' ? 'S' : 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        if ($campusId !== null) UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
