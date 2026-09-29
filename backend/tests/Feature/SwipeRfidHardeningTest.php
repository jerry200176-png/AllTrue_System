<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Notification;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #2809 — Founder 2026-09-29: swipe == teacher marking 已上 (incl. deduction).
 * Hardens four edge cases of that policy. Never run against a real DB.
 */
class SwipeRfidHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $this->campus = Campus::create([
            'name' => 'HardenCampus', 'Token' => 'harden-token', 'code' => 'harden', 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '',
            'URL' => '', 'TelegramToken' => '', 'TelegramChatID' => '', 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);
    }

    protected function tearDown(): void
    {
        SessionDeductionLedger::flushEventListeners();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(): Student
    {
        static $n = 0;
        $n++;

        return Student::create([
            'name' => "Harden{$n}", 'CampusID' => $this->campus->id, 'ClassID' => 1,
            'RFID' => "HARDEN-{$n}", 'enable' => 1,
        ]);
    }

    private function studentClass(int $studentId, array $attrs = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 0,
            'TotalHours' => 2, 'StartDate' => now()->subYear(), 'Stop' => 0, 'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ], $attrs));
    }

    private function mkSession(int $scId, string $date, string $start, string $end, string $status = 'scheduled'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $scId, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => $status,
        ]);
    }

    private function swipe(string $rfid)
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id, 'rfid' => $rfid,
        ], ['Authorization' => 'Bearer harden-token']);
    }

    private function orphan(Student $s, string $signInDT): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentID' => $s->id, 'Memo' => 'self_study', 'SignInDT' => $signInDT, 'SignOutDT' => null,
            'MDT' => $signInDT, 'Status' => 'present', 'CampusID' => $this->campus->id,
            'PersonType' => 'student', 'SessionDeducted' => false,
        ]);
    }

    // ── Fix 1 ────────────────────────────────────────────────────────────

    public function test_weekly_schedule_without_class_session_is_not_deducted(): void
    {
        $student = $this->student();
        $sc = $this->studentClass($student->id, [
            'week1' => now()->dayOfWeek, 'time1' => now()->format('H:i:s'),
        ]);

        $this->swipe($student->RFID)->assertCreated()->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->firstOrFail();
        $this->assertSame('self_study', $signIn->Memo);
        $this->assertNull($signIn->StudentClassID);
        $this->assertNull($signIn->ClassSessionID);
        $this->assertFalse((bool) $signIn->SessionDeducted);
        $this->assertSame(0, SessionDeductionLedger::where('student_class_id', $sc->ID)->count());
    }

    // ── Fix 2 ────────────────────────────────────────────────────────────

    public function test_orphan_close_backfills_later_consecutive_sessions_once(): void
    {
        $student = $this->student();
        $sc = $this->studentClass($student->id);
        $yesterday = now()->subDay()->toDateString();
        $this->mkSession($sc->ID, $yesterday, '10:00:00', '12:00:00', 'attended');
        $later = $this->mkSession($sc->ID, $yesterday, '12:00:00', '14:00:00');
        $orphan = $this->orphan($student, "{$yesterday} 10:00:00");

        $this->artisan('student-signin:close-orphans')->assertSuccessful();

        $orphan->refresh();
        $this->assertStringContainsString('14:00', (string) $orphan->SignOutDT);
        $bf = StudentSignIn::where('ClassSessionID', $later->id)->whereNull('VoidedAt')->firstOrFail();
        $this->assertSame('presence-window', $bf->Memo);
        $this->assertTrue((bool) $bf->SessionDeducted);
        $this->assertSame('attended', $later->refresh()->Status);

        $this->artisan('student-signin:close-orphans')->assertSuccessful();

        $this->assertSame(1, StudentSignIn::where('ClassSessionID', $later->id)->count());
        $this->assertSame(1, SessionDeductionLedger::where('student_class_id', $sc->ID)
            ->where('class_session_id', $later->id)->where('event_type', 'deduct')->count());
    }

    // ── Fix 3 ────────────────────────────────────────────────────────────

    public function test_deduction_failure_logs_alerts_staff_and_keeps_sign_in(): void
    {
        $student = $this->student();
        $sc = $this->studentClass($student->id);
        $session = $this->mkSession($sc->ID, now()->toDateString(), '09:50:00', '12:00:00');
        SessionDeductionLedger::creating(fn () => throw new \RuntimeException('forced ledger failure'));
        Log::spy();

        $this->swipe($student->RFID)->assertCreated()->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('ClassSessionID', $session->id)->firstOrFail();
        $this->assertFalse((bool) $signIn->SessionDeducted);
        Log::shouldHaveReceived('error')->atLeast()->once();
        $alert = Notification::where('Type', 'deduction_failed')->firstOrFail();
        $this->assertSame((int) $this->campus->id, (int) $alert->CampusID);
        $this->assertSame('high', $alert->Severity);
        $this->assertSame($signIn->id, $alert->Payload['sign_in_id']);
    }

    public function test_successful_swipe_raises_no_alert(): void
    {
        $student = $this->student();
        $sc = $this->studentClass($student->id);
        $this->mkSession($sc->ID, now()->toDateString(), '09:50:00', '12:00:00');

        $this->swipe($student->RFID)->assertCreated();

        $this->assertSame(0, Notification::where('Type', 'deduction_failed')->count());
    }
}
