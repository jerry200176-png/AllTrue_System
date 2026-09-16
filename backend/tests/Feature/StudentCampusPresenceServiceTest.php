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
        $payload = $this->service->candidatesForStudent($student, now());
        $statuses = collect($payload['candidates'])->pluck('status')->all();
        $this->assertContains('scheduled', $statuses);
        $this->assertNotContains('leave', $statuses);
        $this->assertNotContains('cancelled', $statuses);
        $this->assertSame('leave_but_arrived', $payload['exception']);
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
}
