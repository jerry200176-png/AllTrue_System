<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 [TEST] — Student Presence Window & Self-study
 *
 * Coverage: PRD Phase 2 FR-001 / FR-002 / NFR-002 / NFR-004 / NFR-005
 *
 * ⚠️ Never run with php artisan test on production (RefreshDatabase).
 *    CI only: push to branch → GitHub Actions.
 */
class StudentSwipePresenceWindowTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::create([
            'name'          => 'TestCampus',
            'Token'         => 'test-token-123',
            'code'          => 'test',
            'Current'       => 0,
            'LineNotifyID'  => '',
            'Client_ID'     => '',
            'Client_Secret' => '',
            'LIFFID'        => '',
            'LIFF_URL'      => '',
            'URL'           => '',
            'TelegramToken' => '',
            'TelegramChatID' => '',
            'TelegramURL'   => '',
            'TeachLIFFID'   => '',
            'TeachLIFF_URL' => '',
        ]);
    }

    private function makeStudent(array $attrs = []): Student
    {
        static $n = 0;
        $n++;
        return Student::create(array_merge([
            'name'     => "Student{$n}",
            'CampusID' => $this->campus->id,
            'ClassID'  => 1,
            'RFID'     => "RFID-STU-{$n}",
            'enable'   => 1,
        ], $attrs));
    }

    private function makeStudentClass(int $studentId, array $attrs = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'    => $studentId,
            'GradeID'      => 1,
            'SubjectID'    => 1,
            'TeacherID'    => 1,
            'by1'          => 0,
            'TotalHours'   => 2,
            'RoomID'       => '',
            'StartDate'    => now()->subYear(),
            'Stop'         => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ], $attrs));
    }

    private function makeClassSession(int $studentClassId, string $date, string $start, string $end, string $status = 'scheduled'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate'    => $date,
            'StartTime'      => $start,
            'EndTime'        => $end,
            'Status'         => $status,
        ]);
    }

    private function swipe(string $rfid): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => $rfid,
        ], [
            'Authorization' => 'Bearer test-token-123',
        ]);
    }

    // ─────────────────────────────────────────────
    //  FR-001: Self-study label
    // ─────────────────────────────────────────────

    /** @test */
    public function swipe_in_with_no_class_marks_self_study(): void
    {
        $student = $this->makeStudent();

        $res = $this->swipe($student->RFID);

        $res->assertStatus(201)
            ->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertNotNull($signIn);
        $this->assertSame('self_study', $signIn->Memo);
        $this->assertNull($signIn->StudentClassID);
        $this->assertFalse((bool) $signIn->SessionDeducted);
    }

    /** @test */
    public function swipe_in_with_matching_class_keeps_swipe_rfid_memo(): void
    {
        $student = $this->makeStudent();
        $sc = $this->makeStudentClass($student->id);
        $today = now()->toDateString();
        $startTime = now()->format('H:i:s');
        $endTime = now()->addHours(2)->format('H:i:s');
        $this->makeClassSession($sc->ID, $today, $startTime, $endTime);

        $res = $this->swipe($student->RFID);

        $res->assertStatus(201)
            ->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertSame('swipe-rfid', $signIn->Memo);
        $this->assertSame($sc->ID, (int) $signIn->StudentClassID);
    }

    // ─────────────────────────────────────────────
    //  FR-002a: Presence Window — two classes, one swipe in/out
    // ─────────────────────────────────────────────

    /** @test */
    public function presence_window_backfills_second_class_on_sign_out(): void
    {
        $student = $this->makeStudent();
        $scA = $this->makeStudentClass($student->id);
        $scB = $this->makeStudentClass($student->id);

        $today = now()->toDateString();
        $sessionA = $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');
        $sessionB = $this->makeClassSession($scB->ID, $today, '12:00:00', '14:00:00');

        // Swipe in at 10:00 → matches A class
        $this->travelTo(now()->setTime(10, 0));
        $resIn = $this->swipe($student->RFID);
        $resIn->assertStatus(201)->assertJsonPath('action', 'sign_in');

        // Verify A class sign-in exists
        $aSignIn = StudentSignIn::where('StudentID', $student->id)
            ->where('ClassSessionID', $sessionA->id)
            ->first();
        $this->assertNotNull($aSignIn);
        $this->assertSame('swipe-rfid', $aSignIn->Memo);

        // Swipe out at 14:00 → should close A + backfill B
        $this->travelTo(now()->setTime(14, 0));
        $resOut = $this->swipe($student->RFID);
        $resOut->assertOk()->assertJsonPath('action', 'sign_out');

        // B class should be backfilled
        $bSignIn = StudentSignIn::where('StudentID', $student->id)
            ->where('ClassSessionID', $sessionB->id)
            ->whereNull('VoidedAt')
            ->first();
        $this->assertNotNull($bSignIn, 'Presence Window should have created B class sign-in');
        $this->assertSame('presence-window', $bSignIn->Memo);
        $this->assertTrue((bool) $bSignIn->SessionDeducted, 'B class should be deducted');
        $this->assertStringContainsString('12:00', $bSignIn->SignInDT);
        $this->assertStringContainsString('14:00', $bSignIn->SignOutDT);
    }

    /** @test */
    public function presence_window_backfills_three_classes(): void
    {
        $student = $this->makeStudent();
        $scA = $this->makeStudentClass($student->id);
        $scB = $this->makeStudentClass($student->id);
        $scC = $this->makeStudentClass($student->id);

        $today = now()->toDateString();
        $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');
        $sessionB = $this->makeClassSession($scB->ID, $today, '12:00:00', '14:00:00');
        $sessionC = $this->makeClassSession($scC->ID, $today, '14:00:00', '16:00:00');

        // Swipe in at 10:00
        $this->travelTo(now()->setTime(10, 0));
        $this->swipe($student->RFID)->assertStatus(201);

        // Swipe out at 16:00 → should backfill B + C
        $this->travelTo(now()->setTime(16, 0));
        $this->swipe($student->RFID)->assertOk();

        $backfilled = StudentSignIn::where('StudentID', $student->id)
            ->where('Memo', 'presence-window')
            ->whereNull('VoidedAt')
            ->get();

        $this->assertCount(2, $backfilled, 'Should backfill B and C classes');
        $backfilledSessionIds = $backfilled->pluck('ClassSessionID')->sort()->values()->all();
        $this->assertEquals(
            [(int) $sessionB->id, (int) $sessionC->id],
            array_map('intval', $backfilledSessionIds)
        );
    }

    // ─────────────────────────────────────────────
    //  FR-002b: Idempotent — teacher already manually marked
    // ─────────────────────────────────────────────

    /** @test */
    public function presence_window_skips_class_already_marked_by_teacher(): void
    {
        $student = $this->makeStudent();
        $scA = $this->makeStudentClass($student->id);
        $scB = $this->makeStudentClass($student->id);

        $today = now()->toDateString();
        $sessionA = $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');
        $sessionB = $this->makeClassSession($scB->ID, $today, '12:00:00', '14:00:00');

        // Swipe in at 10:00
        $this->travelTo(now()->setTime(10, 0));
        $this->swipe($student->RFID)->assertStatus(201);

        // Teacher manually marks B class attendance BEFORE student swipes out
        StudentSignIn::create([
            'StudentClassID'  => $scB->ID,
            'StudentID'        => $student->id,
            'TeacherID'       => 1,
            'Memo'            => 'manual',
            'SignInDT'        => "{$today} 12:00:00",
            'SignOutDT'       => "{$today} 14:00:00",
            'MDT'             => now(),
            'ClassSessionID'  => $sessionB->id,
            'Status'          => 'present',
            'CampusID'        => $this->campus->id,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
        ]);

        // Swipe out at 14:00
        $this->travelTo(now()->setTime(14, 0));
        $this->swipe($student->RFID)->assertOk();

        // B class should NOT have a duplicate record
        $bRecords = StudentSignIn::where('StudentID', $student->id)
            ->where('ClassSessionID', $sessionB->id)
            ->whereNull('VoidedAt')
            ->count();
        $this->assertSame(1, $bRecords, 'Should not duplicate B class when teacher already marked it');
    }

    // ─────────────────────────────────────────────
    //  FR-002c: Idempotent — double swipe out
    // ─────────────────────────────────────────────

    /** @test */
    public function double_swipe_out_does_not_duplicate_backfill(): void
    {
        $student = $this->makeStudent();
        $scA = $this->makeStudentClass($student->id);
        $scB = $this->makeStudentClass($student->id);

        $today = now()->toDateString();
        $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');
        $sessionB = $this->makeClassSession($scB->ID, $today, '12:00:00', '14:00:00');

        // Swipe in at 10:00
        $this->travelTo(now()->setTime(10, 0));
        $this->swipe($student->RFID)->assertStatus(201);

        // Swipe out at 14:00 — first time
        $this->travelTo(now()->setTime(14, 0));
        $this->swipe($student->RFID)->assertOk();

        // B should be backfilled now
        $bCount = StudentSignIn::where('ClassSessionID', $sessionB->id)
            ->whereNull('VoidedAt')->count();
        $this->assertSame(1, $bCount);

        // Swipe in again at 14:01 (self-study, new open record)
        $this->travelTo(now()->setTime(14, 1));
        $this->swipe($student->RFID)->assertStatus(201);

        // Swipe out again at 14:02 — Presence Window runs again
        $this->travelTo(now()->setTime(14, 2));
        $this->swipe($student->RFID)->assertOk();

        // B should STILL only have 1 record
        $bCountAfter = StudentSignIn::where('ClassSessionID', $sessionB->id)
            ->whereNull('VoidedAt')->count();
        $this->assertSame(1, $bCountAfter, 'Double swipe-out must not duplicate B class record');
    }

    // ─────────────────────────────────────────────
    //  Edge: Single class — no backfill needed
    // ─────────────────────────────────────────────

    /** @test */
    public function single_class_sign_out_does_not_backfill(): void
    {
        $student = $this->makeStudent();
        $sc = $this->makeStudentClass($student->id);
        $today = now()->toDateString();
        $this->makeClassSession($sc->ID, $today, '10:00:00', '12:00:00');

        $this->travelTo(now()->setTime(10, 0));
        $this->swipe($student->RFID)->assertStatus(201);

        $this->travelTo(now()->setTime(14, 0));
        $this->swipe($student->RFID)->assertOk();

        $total = StudentSignIn::where('StudentID', $student->id)
            ->whereNull('VoidedAt')->count();
        $this->assertSame(1, $total, 'Only the original sign-in should exist');
    }

    // ─────────────────────────────────────────────
    //  Edge: Other student's class not backfilled
    // ─────────────────────────────────────────────

    /** @test */
    public function presence_window_does_not_backfill_other_students_sessions(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();
        $scA = $this->makeStudentClass($studentA->id);
        $scOther = $this->makeStudentClass($studentB->id);

        $today = now()->toDateString();
        $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');
        $otherSession = $this->makeClassSession($scOther->ID, $today, '12:00:00', '14:00:00');

        $this->travelTo(now()->setTime(10, 0));
        $this->swipe($studentA->RFID)->assertStatus(201);

        $this->travelTo(now()->setTime(14, 0));
        $this->swipe($studentA->RFID)->assertOk();

        $otherBackfill = StudentSignIn::where('ClassSessionID', $otherSession->id)
            ->whereNull('VoidedAt')->count();
        $this->assertSame(0, $otherBackfill, 'Must not backfill sessions belonging to other students');
    }

    // ─────────────────────────────────────────────
    //  NFR-004: Teacher swipe regression
    // ─────────────────────────────────────────────

    /** @test */
    public function teacher_swipe_is_not_affected(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\SwipeRfidController::class);
        $method = $reflection->getMethod('handleTeacherSwipe');

        $this->assertTrue(
            $method->isPrivate(),
            'handleTeacherSwipe must remain a private method (not accidentally removed)'
        );

        $constants = $reflection->getConstants();
        $this->assertArrayHasKey('TEACHER_SWIPE_DEBOUNCE_SECONDS', $constants);
        $this->assertSame(60, $constants['TEACHER_SWIPE_DEBOUNCE_SECONDS']);
    }
}
