<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentCampusPresence;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Services\StudentCampusPresenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFID-1 (#2809): campus presence lifecycle + candidates without billing side effects.
 */
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
            'name' => 'PresenceCampus',
            'Token' => 'presence-token',
            'code' => 'pres',
            'Current' => 0,
            'LineNotifyID' => '',
            'Client_ID' => '',
            'Client_Secret' => '',
            'LIFFID' => '',
            'LIFF_URL' => '',
            'URL' => '',
            'TelegramToken' => '',
            'TelegramChatID' => '',
            'TelegramURL' => '',
            'TeachLIFFID' => '',
            'TeachLIFF_URL' => '',
        ]);

        $subject = Subject::create([
            'School_id' => 1,
            'Grade_no' => 0,
            'Subject_Name' => 'PresenceMath',
        ]);
        $this->subjectId = $subject->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => 'PresenceStudent',
            'CampusID' => $this->campus->id,
            'ClassID' => 1,
            'RFID' => 'PRES-RFID-1',
            'enable' => 1,
        ]);
    }

    public function test_arrival_creates_open_presence_without_sign_in_or_ledger(): void
    {
        $student = $this->makeStudent();
        $presence = $this->service->recordArrival($student, (int) $this->campus->id, now(), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABBCCDD');

        $this->assertTrue($presence->isOpen());
        $this->assertSame(StudentCampusPresence::STATUS_OPEN, $presence->Status);
        $this->assertDatabaseCount('StudentSingIn', 0);
        $this->assertDatabaseCount('session_deduction_ledger', 0);
    }

    public function test_debounce_and_idempotency_do_not_duplicate_open_rows(): void
    {
        $student = $this->makeStudent();
        $first = $this->service->recordArrival($student, (int) $this->campus->id, now(), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABBCCDD');
        $second = $this->service->recordArrival($student, (int) $this->campus->id, now()->copy()->addSeconds(10), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABBCCDD');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StudentCampusPresence::where('StudentID', $student->id)->count());
    }

    public function test_toggle_sign_out_closes_presence_without_billing(): void
    {
        $student = $this->makeStudent();
        $this->service->recordArrival($student, (int) $this->campus->id, now()->copy()->subMinutes(5), StudentCampusPresence::SOURCE_RFID, 'pi-1', 'AABBCCDD');

        $result = $this->service->toggleSwipe($student, (int) $this->campus->id, now(), 'pi-1', 'AABBCCDD');

        $this->assertSame('sign_out', $result['action']);
        $this->assertSame(StudentCampusPresence::STATUS_CLOSED, $result['presence']->Status);
        $this->assertDatabaseCount('StudentSingIn', 0);
        $this->assertDatabaseCount('session_deduction_ledger', 0);
    }

    public function test_candidates_exclude_leave_and_cancelled(): void
    {
        $student = $this->makeStudent();
        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => $this->subjectId,
            'TeacherID' => 1,
            'by1' => 0,
            'TotalHours' => 2,
            'StartDate' => now()->subYear(),
            'Stop' => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00:00',
            'EndTime' => '11:00:00',
            'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '12:00:00',
            'EndTime' => '13:00:00',
            'Status' => 'leave',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '14:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'cancelled',
        ]);

        $payload = $this->service->candidatesForStudent($student, now());
        $statuses = collect($payload['candidates'])->pluck('status')->all();

        $this->assertContains('scheduled', $statuses);
        $this->assertNotContains('leave', $statuses);
        $this->assertNotContains('cancelled', $statuses);
        $this->assertSame('leave_but_arrived', $payload['exception']);
    }

    public function test_orphan_close_does_not_touch_billing(): void
    {
        $student = $this->makeStudent();
        $presence = $this->service->recordArrival(
            $student,
            (int) $this->campus->id,
            Carbon::yesterday()->setTime(10, 0),
            StudentCampusPresence::SOURCE_RFID,
            'pi-1',
            'AABBCCDD'
        );

        $n = $this->service->orphanCloseBefore(now()->toDateString());
        $presence->refresh();

        $this->assertSame(1, $n);
        $this->assertSame(StudentCampusPresence::STATUS_ORPHAN_CLOSED, $presence->Status);
        $this->assertDatabaseCount('session_deduction_ledger', 0);
    }

    public function test_service_source_forbids_billing_imports(): void
    {
        $src = file_get_contents(app_path('Services/StudentCampusPresenceService.php'));
        $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\SessionDeductionService/', $src);
        $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\AttendanceEffectsService/', $src);
        $this->assertStringNotContainsString('deductOnAttendance', $src);
        $this->assertStringNotContainsString('applySessionStatus', $src);
        $ctrl = file_get_contents(app_path('Http/Controllers/CampusPresenceController.php'));
        $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\SessionDeductionService/', $ctrl);
        $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\Services\\\\AttendanceEffectsService/', $ctrl);
        $this->assertStringNotContainsString('deductOnAttendance', $ctrl);
        $this->assertStringNotContainsString('applySessionStatus', $ctrl);
    }
}
