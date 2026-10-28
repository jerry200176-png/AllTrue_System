<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\RecordStudentSwipe;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #4192: real committed cancellation versus a stale swipe candidate, CI MySQL only. */
class SwipeCancellationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // Like NotificationSyncConcurrencyTest: both connections must see committed fixtures.
    protected $connectionsToTransact = [];

    public static function swipePaths(): array
    {
        // rfid_sign_out: the sign-out presence-window backfill (StudentPresenceBackfillService).
        return [['rfid'], ['attendance'], ['rfid_sign_out']];
    }

    /** @dataProvider swipePaths */
    public function test_cancel_committed_after_candidate_read_is_not_overwritten(string $path): void
    {
        $reader = DB::connection();
        if ($reader->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires InnoDB locking reads and REPEATABLE READ.');
        }
        $this->assertStringStartsWith('AllTrue_test', $reader->getDatabaseName());
        $this->assertSame(0, $reader->transactionLevel());
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        Carbon::setTestNow(Carbon::today()->setTime($path === 'rfid_sign_out' ? 12 : 10, $path === 'rfid_sign_out' ? 30 : 0));
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => 'Swipe race fixture', 'CampusID' => $campus->id, 'ClassID' => 1,
            'RFID' => 'RACE-' . $campus->id, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 0,
            'TotalHours' => 2, 'StartDate' => now()->subMonth(), 'Stop' => 0,
            'SessionCount' => 1, 'SessionDuration' => 120, 'RemainingSessions' => 1, 'UsedSessions' => 0,
            'ScheduleMode' => 'count', 'ClassType' => 'one_on_one',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled',
        ]);
        if ($path === 'rfid_sign_out') {
            // Swiped in at 09:00 with no session in its window (self-study); the 12:30
            // sign-out backfills every scheduled session that started in 09:00-12:30.
            $openSignIn = StudentSignIn::create([
                'StudentID' => $student->id, 'TeacherID' => null, 'Memo' => 'self_study',
                'SignInDT' => now()->setTime(9, 0), 'SignOutDT' => null, 'MDT' => now()->setTime(9, 0),
                'ClassSessionID' => null, 'Status' => 'present', 'CampusID' => $campus->id,
                'PersonType' => 'student', 'SessionDeducted' => false,
            ]);
        }
        $name = 'swipe_cancellation_race';
        config(["database.connections.$name" => config('database.connections.' . config('database.default'))]);
        DB::purge($name);
        $writer = DB::connection($name);
        $originalEvents = $reader->getEventDispatcher();
        $events = clone $originalEvents;
        $reader->setEventDispatcher($events);
        $cancelled = false;
        $locks = [];
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use ($reader, $writer, $course, $session, &$cancelled, &$locks): void {
            if ($query->connection !== $reader) {
                return;
            }
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                if (str_contains($sql, 'from `studentclass`')) $locks[] = 'course';
                if (str_contains($sql, 'from `classsession`')) $locks[] = 'session';
            }
            // The SELECT has already fetched scheduled, but Eloquent has not yet
            // returned that stale candidate. Commit the competing writer now.
            if (!$cancelled && str_starts_with($sql, 'select') && str_contains($sql, 'from `classsession`') && !str_contains($sql, 'for update')) {
                $cancelled = true;
                $writer->transaction(function () use ($writer, $course, $session): void {
                    $writer->table('StudentClass')->where('ID', $course->ID)->lockForUpdate()->first();
                    $writer->table('ClassSession')->where('id', $session->id)->lockForUpdate()->first();
                    $writer->table('ClassSession')->where('id', $session->id)->update(['Status' => 'cancelled']);
                    $writer->table('StudentClass')->where('ID', $course->ID)->update(['SessionCount' => 0, 'RemainingSessions' => 0]);
                });
            }
        });

        try {
            if ($path === 'rfid_sign_out') {
                $result = app(RecordStudentSwipe::class)->handle($student, $campus, now(), fn (array $result) => $result);
                $this->assertSame('sign_out', $result['action']);
                $status = $result['status'];
            } elseif ($path === 'rfid') {
                try {
                    $result = app(RecordStudentSwipe::class)->handle($student, $campus, now(), fn (array $result) => $result);
                    $status = $result['status'];
                } catch (\InvalidArgumentException $e) {
                    $status = 422; // SwipeRfidController maps this exception to attendance_not_allowed.
                }
            } else {
                $request = Request::create('/api/v1/attendance/swipe', 'POST', ['RFID' => $student->RFID]);
                $request->attributes->set('api_campus_id', $campus->id);
                $status = app(AttendanceController::class)->swipe($request)->getStatusCode();
            }
            $this->assertTrue($cancelled, 'The second connection must commit the cancellation.');
            // A sign-out still succeeds; it just must not backfill the cancelled session.
            $this->assertSame($path === 'rfid_sign_out' ? 200 : 422, $status);
            if ($path === 'rfid_sign_out') {
                $this->assertNotNull($writer->table('StudentSingIn')->where('id', $openSignIn->id)->value('SignOutDT'));
            }
            $this->assertSame('cancelled', $writer->table('ClassSession')->where('id', $session->id)->value('Status'));
            $this->assertSame(0, $writer->table('StudentSingIn')->where('ClassSessionID', $session->id)->count());
            $this->assertSame(0, $writer->table('LearningRecord')->where('ClassSessionID', $session->id)->count());
            $this->assertSame(0, $writer->table('session_deduction_ledger')->where('class_session_id', $session->id)->count());
            $this->assertSame(0, (int) $writer->table('StudentClass')->where('ID', $course->ID)->value('UsedSessions'));
            $this->assertSame(['course', 'session'], array_slice($locks, 0, 2), 'Lock the course before rereading the session.');
        } finally {
            $reader->setEventDispatcher($originalEvents);
            foreach (['session_deduction_ledger', 'StudentSingIn', 'LearningRecord'] as $table) {
                $column = $table === 'session_deduction_ledger' ? 'class_session_id' : 'ClassSessionID';
                $writer->table($table)->where($column, $session->id)->delete();
            }
            $writer->table('StudentSingIn')->where('StudentID', $student->id)->delete();
            $writer->table('ClassSession')->where('id', $session->id)->delete();
            $writer->table('StudentClass')->where('ID', $course->ID)->delete();
            $writer->table('Student')->where('id', $student->id)->delete();
            $writer->table('Campus')->where('id', $campus->id)->delete();
            DB::purge($name);
            Carbon::setTestNow();
        }
    }
}
